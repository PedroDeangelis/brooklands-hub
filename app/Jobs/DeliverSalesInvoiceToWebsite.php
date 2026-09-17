<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\SalesInvoice;
use App\Models\SyncRecord;
use App\Sync\CustomerSyncLedger;
use App\Sync\Payload\DeliveryType;
use App\Sync\SalesInvoiceSyncLedger;
use App\Website\WebsiteClient;
use App\Website\WebsiteConfigurationException;
use App\Website\WebsiteRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one posted sales invoice's current desired website state.
 *
 * The invoice counterpart to DeliverSalesOrderToWebsite, and a sibling
 * rather than a generalisation of it. One thing is particular to invoices, as to orders: the
 * invoice post links to its customer's post, so the customer has to have
 * reached the website first. When it has not, the invoice is left pending —
 * not failed — and the next import of it tries again.
 *
 * The job carries identifiers only. Everything it sends is rebuilt when it
 * runs, so a job queued before a change cannot deliver what the invoice looked
 * like then.
 */
class DeliverSalesInvoiceToWebsite implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(
        public readonly string $bcId,
        public readonly string $channel = SalesInvoiceSyncLedger::CHANNEL_WEBSITE,
    ) {}

    /**
     * Only one delivery per invoice per channel at a time.
     *
     * The lock names the entity as well as the id, so an invoice and a product
     * that happened to share a Business Central id could not block each other.
     *
     * dontRelease() is deliberate: a job that cannot get the lock is dropped
     * rather than queued behind the one that holds it, because the holder is
     * delivering the same freshly-rebuilt state this job would have built.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->lockKey()))
                ->dontRelease()
                ->expireAfter(180),
        ];
    }

    public function handle(SalesInvoiceSyncLedger $ledger, WebsiteClient $client): void
    {
        $record = SyncRecord::query()
            ->forChannel($this->channel)
            ->where('entity', SalesInvoiceSyncLedger::ENTITY_SALES_INVOICE)
            ->where('bc_id', $this->bcId)
            ->first();

        if ($record === null) {
            return;
        }

        $salesInvoice = SalesInvoice::query()->where('bc_id', $this->bcId)->first();

        if ($salesInvoice === null) {
            // The ledger wants something delivered for an invoice that is no
            // longer here. Nothing can be rebuilt, so this needs a person.
            $ledger->markFailed($record, 'No sales invoice exists for this ledger row.');

            return;
        }

        // Rebuild from current state rather than trusting anything queued.
        $plan = $ledger->plan($salesInvoice, $this->channel);

        if ($plan->type === DeliveryType::None) {
            // The website already holds this.
            $ledger->markSynced($record);

            return;
        }

        if (! $this->customerIsOnWebsite($salesInvoice)) {
            // The invoice post has to link to a customer post, and the customer
            // has not been delivered yet. Not a failure — nothing went wrong —
            // so the record stays pending and the next import re-queues it.
            $ledger->releaseToPending($record);

            Log::info('website.sales_invoice_delivery.waiting_for_customer', $this->context($plan->type, null, [
                'customer_bc_id' => $salesInvoice->customer_bc_id,
            ]));

            return;
        }

        // The hash of the state actually being sent. Delivery is only recorded
        // against this exact state.
        $sentHash = $plan->payloadHash;

        $ledger->markSyncing($record);

        try {
            $response = $client->deliver(
                WebsiteRequest::fromPlan($plan, WebsiteRequest::ENTITY_SALES_INVOICE),
            );
        } catch (WebsiteConfigurationException $e) {
            // Misconfiguration is not a transport failure: retrying cannot fix
            // it, and burning the attempts would hide the cause.
            $ledger->markFailed($record, $e->getMessage());
            $this->fail($e);

            return;
        }

        if ($response->isDelivered()) {
            // The full desired payload is recorded as delivered even when only
            // a partial diff was sent: the website now holds the whole state.
            $matched = $ledger->markSynced($record, $sentHash);

            Log::info('website.sales_invoice_delivery.succeeded', $this->context($plan->type, $response->status, [
                'recorded' => $matched,
                'stale' => ! $matched,
            ]));

            return;
        }

        if ($response->isAccepted()) {
            // Validated but applied nothing. The desired state is still owed,
            // so the record goes back to pending rather than claiming a
            // delivery that never happened.
            $ledger->releaseToPending($record);

            Log::info('website.sales_invoice_delivery.accepted_not_applied', $this->context($plan->type, $response->status));

            return;
        }

        if ($response->needsFullSync()) {
            // The website has no invoice post for this invoice, so the payload we
            // believed it held describes nothing. Forgetting it makes the next
            // plan a full delivery, which is the only thing that can work.
            $ledger->forgetDelivered($record);

            Log::info('website.sales_invoice_delivery.full_sync_required', $this->context($plan->type, $response->status));

            return;
        }

        if ($response->isConflict()) {
            $ledger->markConflicted($record, $response->conflicts, (string) $response->error);

            Log::warning('website.sales_invoice_delivery.conflict', $this->context($plan->type, $response->status, [
                'conflicts' => array_map(
                    static fn (array $conflict): string => (string) ($conflict['code'] ?? 'unknown'),
                    $response->conflicts,
                ),
            ]));

            return;
        }

        if ($response->isTransient()) {
            $ledger->markFailed($record, $response->describe());

            Log::warning('website.sales_invoice_delivery.transient', $this->context($plan->type, $response->status));

            // Throwing hands the retry to the queue, which applies the backoff.
            throw new WebsiteDeliveryFailed($response->describe(), $response->status ?? 0);
        }

        $ledger->markFailed($record, $response->describe());

        Log::error('website.sales_invoice_delivery.failed', $this->context($plan->type, $response->status, [
            'error' => $response->error,
        ]));

        // A deterministic failure must not consume the remaining attempts.
        $this->fail(new WebsiteDeliveryFailed($response->describe(), $response->status ?? 0));
    }

    /**
     * Whether the invoice's customer has been delivered to the website.
     *
     * Read from the customer ledger rather than asked of the website: a
     * delivered customer is one the ledger recorded as synced with a payload,
     * which is the same fact the website would report and costs no request.
     */
    private function customerIsOnWebsite(SalesInvoice $salesInvoice): bool
    {
        $customerBcId = trim((string) $salesInvoice->customer_bc_id);

        if ($customerBcId === '') {
            return false;
        }

        $record = SyncRecord::query()
            ->forChannel($this->channel)
            ->where('entity', CustomerSyncLedger::ENTITY_CUSTOMER)
            ->where('bc_id', $customerBcId)
            ->first();

        return $record !== null
            && $record->status === SyncStatus::Synced
            && $record->delivered_payload !== null;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['website-delivery', 'entity:sales_invoice', 'bc:'.$this->bcId, 'channel:'.$this->channel];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('website.sales_invoice_delivery.exhausted', [
            'bc_id' => $this->bcId,
            'channel' => $this->channel,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function lockKey(): string
    {
        return "website-delivery:{$this->channel}:sales_invoice:{$this->bcId}";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(DeliveryType $type, ?int $status, array $extra = []): array
    {
        return array_merge([
            'bc_id' => $this->bcId,
            'channel' => $this->channel,
            'entity' => SalesInvoiceSyncLedger::ENTITY_SALES_INVOICE,
            'mode' => $type->value,
            'status' => $status,
        ], $extra);
    }
}
