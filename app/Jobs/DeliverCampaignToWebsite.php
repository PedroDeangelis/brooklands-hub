<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\SyncRecord;
use App\Sync\CampaignSyncLedger;
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
 * Delivers one campaign's current desired website state.
 *
 * The campaign counterpart to DeliverProductToWebsite, and a sibling rather
 * than a generalisation of it: the two differ in which ledger they ask and
 * which entity they declare, and merging them would mean every line carried a
 * branch. What they genuinely share — the client, the signer, the response
 * semantics and the ledger state machine — is shared already.
 *
 * The job carries identifiers only. Everything it sends is rebuilt when it
 * runs, so a job queued before a change cannot deliver what the campaign looked
 * like then.
 */
class DeliverCampaignToWebsite implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(
        public readonly string $bcId,
        public readonly string $channel = CampaignSyncLedger::CHANNEL_WEBSITE,
    ) {}

    /**
     * Only one delivery per campaign per channel at a time.
     *
     * The lock names the entity as well as the id, so a campaign and a product
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

    public function handle(CampaignSyncLedger $ledger, WebsiteClient $client): void
    {
        $record = SyncRecord::query()
            ->forChannel($this->channel)
            ->where('entity', CampaignSyncLedger::ENTITY_CAMPAIGN)
            ->where('bc_id', $this->bcId)
            ->first();

        if ($record === null) {
            return;
        }

        $campaign = Campaign::query()->where('bc_id', $this->bcId)->first();

        if ($campaign === null) {
            // The ledger wants something delivered for a campaign that is no
            // longer here. Nothing can be rebuilt, so this needs a person.
            $ledger->markFailed($record, 'No campaign exists for this ledger row.');

            return;
        }

        // Rebuild from current state rather than trusting anything queued.
        $plan = $ledger->plan($campaign, $this->channel);

        if ($plan->type === DeliveryType::None) {
            // The website already holds this.
            $ledger->markSynced($record);

            return;
        }

        // The hash of the state actually being sent. Delivery is only recorded
        // against this exact state.
        $sentHash = $plan->payloadHash;

        $ledger->markSyncing($record);

        try {
            $response = $client->deliver(
                WebsiteRequest::fromPlan($plan, WebsiteRequest::ENTITY_CAMPAIGN),
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

            Log::info('website.campaign_delivery.succeeded', $this->context($plan->type, $response->status, [
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

            Log::info('website.campaign_delivery.accepted_not_applied', $this->context($plan->type, $response->status));

            return;
        }

        if ($response->needsFullSync()) {
            // The website has no promotion for this campaign, so the payload we
            // believed it held describes nothing. Forgetting it makes the next
            // plan a full delivery, which is the only thing that can work. This
            // is how a campaign whose promotion WordPress deleted comes back.
            $ledger->forgetDelivered($record);

            Log::info('website.campaign_delivery.full_sync_required', $this->context($plan->type, $response->status));

            return;
        }

        if ($response->isConflict()) {
            $ledger->markConflicted($record, $response->conflicts, (string) $response->error);

            Log::warning('website.campaign_delivery.conflict', $this->context($plan->type, $response->status, [
                'conflicts' => array_map(
                    static fn (array $conflict): string => (string) ($conflict['code'] ?? 'unknown'),
                    $response->conflicts,
                ),
            ]));

            return;
        }

        if ($response->isTransient()) {
            $ledger->markFailed($record, $response->describe());

            Log::warning('website.campaign_delivery.transient', $this->context($plan->type, $response->status));

            // Throwing hands the retry to the queue, which applies the backoff.
            throw new WebsiteDeliveryFailed($response->describe(), $response->status ?? 0);
        }

        $ledger->markFailed($record, $response->describe());

        Log::error('website.campaign_delivery.failed', $this->context($plan->type, $response->status, [
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
        return ['website-delivery', 'entity:campaign', 'bc:'.$this->bcId, 'channel:'.$this->channel];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('website.campaign_delivery.exhausted', [
            'bc_id' => $this->bcId,
            'channel' => $this->channel,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function lockKey(): string
    {
        return "website-delivery:{$this->channel}:campaign:{$this->bcId}";
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
            'entity' => CampaignSyncLedger::ENTITY_CAMPAIGN,
            'mode' => $type->value,
            'status' => $status,
        ], $extra);
    }
}
