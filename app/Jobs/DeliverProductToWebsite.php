<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SyncRecord;
use App\Sync\Payload\DeliveryType;
use App\Sync\SyncLedger;
use App\Website\WebsiteClient;
use App\Website\WebsiteConfigurationException;
use App\Website\WebsiteRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one product's current desired website state.
 *
 * The job carries identifiers only. Everything it sends is rebuilt when it
 * runs, so a job queued before a change cannot deliver what the product looked
 * like then: a stale job simply delivers the newer state, or finds there is
 * nothing left to do.
 */
class DeliverProductToWebsite implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(
        public readonly string $bcId,
        public readonly string $channel = SyncLedger::CHANNEL_ITEMS,
    ) {}

    /**
     * Only one delivery per product per channel at a time.
     *
     * dontRelease() is deliberate: a job that cannot get the lock is dropped
     * rather than queued behind the one that holds it. The holder is delivering
     * the same freshly-rebuilt state this job would have built, so repeating it
     * would send the same bytes twice. If the state has moved on, the importer
     * has already left the record pending and a new job will pick it up.
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

    public function handle(SyncLedger $ledger, WebsiteClient $client): void
    {
        $record = SyncRecord::query()
            ->forChannel($this->channel)
            ->where('bc_id', $this->bcId)
            ->first();

        if ($record === null) {
            return;
        }

        $product = Product::query()->where('bc_id', $this->bcId)->first();

        if ($product === null) {
            // The ledger wants something delivered for a product that is no
            // longer here. Nothing can be rebuilt, so this needs a person.
            $ledger->markFailed($record, 'No product exists for this ledger row.');

            return;
        }

        // Rebuild from current state rather than trusting anything queued.
        $plan = $ledger->plan($product, $this->channel);

        if ($plan->type === DeliveryType::None) {
            // The website already holds this. Reaching here usually means two
            // jobs were queued for one change; the ledger is simply brought up
            // to date without another request.
            $ledger->markSynced($record);

            return;
        }

        // The hash of the state actually being sent. Delivery is only recorded
        // against this exact state.
        $sentHash = $plan->payloadHash;

        $ledger->markSyncing($record);

        try {
            $response = $client->deliver(WebsiteRequest::fromPlan($plan));
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
            //
            // A removal counts as delivered whatever the website found. Removing
            // a product that was already absent has achieved what was asked, so
            // the receiver answers 2xx either way and this records it as done.
            $matched = $ledger->markSynced($record, $sentHash);

            Log::info('website.delivery.succeeded', $this->context($plan->type, $response->status, [
                'recorded' => $matched,
                'stale' => ! $matched,
            ]));

            return;
        }

        if ($response->isAccepted()) {
            // The website validated the request but applied nothing, which is
            // what the transport-only endpoint does. Nothing is recorded as
            // delivered: the desired state is still owed, so the record goes
            // back to pending rather than claiming a delivery that never
            // happened. No error either, because nothing went wrong.
            $ledger->releaseToPending($record);

            Log::info('website.delivery.accepted_not_applied', $this->context($plan->type, $response->status));

            return;
        }

        if ($response->needsFullSync()) {
            // The website has no record of this product, so the payload we
            // believed it held describes nothing. Forgetting that makes the next
            // plan a full delivery, which is the only thing that can work. The
            // record returns to pending and recovers without anyone intervening.
            $ledger->forgetDelivered($record);

            Log::info('website.delivery.full_sync_required', $this->context($plan->type, $response->status));

            return;
        }

        if ($response->isConflict()) {
            // Applying would take another product's identity on the website.
            // Nothing was written there and nothing is recorded as delivered
            // here; the desired payload stays intact, waiting for the data to be
            // corrected. No retry: the same payload would collide identically.
            $ledger->markConflicted($record, $response->conflicts, (string) $response->error);

            Log::warning('website.delivery.conflict', $this->context($plan->type, $response->status, [
                'conflicts' => array_map(
                    static fn (array $conflict): string => (string) ($conflict['code'] ?? 'unknown'),
                    $response->conflicts,
                ),
            ]));

            return;
        }

        if ($response->isTransient()) {
            $ledger->markFailed($record, $response->describe());

            Log::warning('website.delivery.transient', $this->context($plan->type, $response->status));

            // Throwing hands the retry to the queue, which applies the backoff.
            throw new WebsiteDeliveryFailed($response->describe(), $response->status ?? 0);
        }

        $ledger->markFailed($record, $response->describe());

        Log::error('website.delivery.failed', $this->context($plan->type, $response->status, [
            'error' => $response->error,
        ]));

        // A deterministic failure must not consume the remaining attempts.
        $this->fail(new WebsiteDeliveryFailed($response->describe(), $response->status ?? 0));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['website-delivery', 'bc:'.$this->bcId, 'channel:'.$this->channel];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('website.delivery.exhausted', [
            'bc_id' => $this->bcId,
            'channel' => $this->channel,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function lockKey(): string
    {
        return "website-delivery:{$this->channel}:{$this->bcId}";
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
            'mode' => $type->value,
            'status' => $status,
        ], $extra);
    }
}
