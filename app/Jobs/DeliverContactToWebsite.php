<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\Contact;
use App\Models\SyncRecord;
use App\Sync\ContactSyncLedger;
use App\Sync\CustomerSyncLedger;
use App\Sync\Payload\DeliveryType;
use App\Website\WebsiteClient;
use App\Website\WebsiteConfigurationException;
use App\Website\WebsiteRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one contact's current desired website state.
 *
 * The contact counterpart to DeliverCustomerToWebsite, and a sibling rather
 * than a generalisation of it. Two things are particular to contacts:
 *
 *  - Eligibility is checked again here, at the moment of sending. A contact
 *    can stop qualifying between being queued and being delivered, and a
 *    delivery creates a website user, so the last word is the job's.
 *  - A contact's user must link to its customer's post, so the customer has
 *    to have reached the website first. When it has not, the contact is left
 *    pending — not failed — and the link sweep re-queues it.
 *
 * The job carries identifiers only. Everything it sends is rebuilt when it
 * runs, so a job queued before a change cannot deliver what the contact looked
 * like then.
 */
class DeliverContactToWebsite implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(
        public readonly string $bcId,
        public readonly string $channel = ContactSyncLedger::CHANNEL_WEBSITE,
    ) {}

    /**
     * Only one delivery per contact per channel at a time.
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

    public function handle(ContactSyncLedger $ledger, WebsiteClient $client): void
    {
        $record = SyncRecord::query()
            ->forChannel($this->channel)
            ->where('entity', ContactSyncLedger::ENTITY_CONTACT)
            ->where('bc_id', $this->bcId)
            ->first();

        if ($record === null) {
            return;
        }

        $contact = Contact::query()->where('bc_id', $this->bcId)->first();

        if ($contact === null) {
            $ledger->markFailed($record, 'No contact exists for this ledger row.');

            return;
        }

        if (! $ledger->isEligible($contact)) {
            // Stopped qualifying since it was queued. A user that was never
            // created must not be created now; the row goes, so the dashboard
            // reads "not applicable" rather than "pending" forever. A user
            // that already exists is left exactly as it is.
            if ($record->delivered_payload === null) {
                $record->delete();

                Log::info('website.contact_delivery.dropped_ineligible', $this->context(DeliveryType::None, null));

                return;
            }

            $ledger->releaseToPending($record);

            Log::info('website.contact_delivery.held_ineligible', $this->context(DeliveryType::None, null));

            return;
        }

        // Rebuild from current state rather than trusting anything queued.
        $plan = $ledger->plan($contact, $this->channel);

        if ($plan->type === DeliveryType::None) {
            $ledger->markSynced($record);

            return;
        }

        if (! $this->customerIsOnWebsite($contact)) {
            // The user has to link to a customer post, and the customer has not
            // been delivered yet. Not a failure — nothing went wrong — so the
            // record stays pending and the link sweep tries again.
            $ledger->releaseToPending($record);

            Log::info('website.contact_delivery.waiting_for_customer', $this->context($plan->type, null, [
                'customer_bc_id' => $contact->customer_bc_id,
            ]));

            return;
        }

        $sentHash = $plan->payloadHash;

        $ledger->markSyncing($record);

        try {
            $response = $client->deliver(
                WebsiteRequest::fromPlan($plan, WebsiteRequest::ENTITY_CONTACT),
            );
        } catch (WebsiteConfigurationException $e) {
            $ledger->markFailed($record, $e->getMessage());
            $this->fail($e);

            return;
        }

        if ($response->isDelivered()) {
            $matched = $ledger->markSynced($record, $sentHash);

            Log::info('website.contact_delivery.succeeded', $this->context($plan->type, $response->status, [
                'recorded' => $matched,
                'stale' => ! $matched,
            ]));

            return;
        }

        if ($response->isAccepted()) {
            // Validated but applied nothing — for a contact, usually because
            // the website has no post for its customer yet. Still owed, so
            // back to pending; the link sweep re-queues it.
            $ledger->releaseToPending($record);

            Log::info('website.contact_delivery.accepted_not_applied', $this->context($plan->type, $response->status, [
                'reason' => $response->body['reason'] ?? null,
            ]));

            return;
        }

        if ($response->needsFullSync()) {
            // The website has no user for this contact, so the payload we
            // believed it held describes nothing. Forgetting it makes the next
            // plan a full delivery, and re-queueing sends that now rather than
            // leaving the row pending until something else touches it.
            $ledger->forgetDelivered($record);

            Log::info('website.contact_delivery.full_sync_required', $this->context($plan->type, $response->status));

            static::dispatch($this->bcId, $this->channel);

            return;
        }

        if ($response->isConflict()) {
            $ledger->markConflicted($record, $response->conflicts, (string) $response->error);

            Log::warning('website.contact_delivery.conflict', $this->context($plan->type, $response->status, [
                'conflicts' => array_map(
                    static fn (array $conflict): string => (string) ($conflict['code'] ?? 'unknown'),
                    $response->conflicts,
                ),
            ]));

            return;
        }

        if ($response->isTransient()) {
            $ledger->markFailed($record, $response->describe());

            Log::warning('website.contact_delivery.transient', $this->context($plan->type, $response->status));

            throw new WebsiteDeliveryFailed($response->describe(), $response->status ?? 0);
        }

        $ledger->markFailed($record, $response->describe());

        Log::error('website.contact_delivery.failed', $this->context($plan->type, $response->status, [
            'error' => $response->error,
        ]));

        $this->fail(new WebsiteDeliveryFailed($response->describe(), $response->status ?? 0));
    }

    /**
     * Whether the contact's customer has been delivered to the website.
     *
     * Read from the customer's own ledger row, which is what the website
     * confirmed. It can be stale if the post was deleted afterwards, and the
     * website answers that case itself with applied=false.
     */
    private function customerIsOnWebsite(Contact $contact): bool
    {
        $customerBcId = trim((string) $contact->customer_bc_id);

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
        return ['website-delivery', 'entity:contact', 'bc:'.$this->bcId, 'channel:'.$this->channel];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('website.contact_delivery.exhausted', [
            'bc_id' => $this->bcId,
            'channel' => $this->channel,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function lockKey(): string
    {
        return "website-delivery:{$this->channel}:contact:{$this->bcId}";
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
            'entity' => ContactSyncLedger::ENTITY_CONTACT,
            'mode' => $type->value,
            'status' => $status,
        ], $extra);
    }
}
