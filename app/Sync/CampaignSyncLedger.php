<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\Campaign;
use App\Models\SyncRecord;
use App\Sync\Payload\CampaignWebsitePayloadBuilder;
use App\Sync\Payload\DeliveryPlan;
use Carbon\CarbonImmutable;

/**
 * Records whether a Business Central campaign still needs delivering.
 *
 * The campaign counterpart to SyncLedger, and deliberately a sibling rather
 * than a generalisation of it. What the two share — how a delivery attempt
 * progresses through pending, syncing, synced, failed and conflicted — lives in
 * TracksDeliveryState and is used by both. What differs is only how a desired
 * payload is built and what "belongs on the website" means, and folding those
 * into one class would mean every read of it carried an entity check.
 *
 * Identity is (channel, entity, bc_id). Campaigns and products share the
 * website channel because they share a destination; the entity is what tells
 * them apart.
 *
 * A campaign is never removed. Laravel mirrors Business Central's current
 * campaign state and WordPress decides what to do with it, so the action is
 * always an upsert — a deactivated, expired or not-yet-started campaign is
 * still a fact the website needs. This is the deliberate difference from
 * SyncLedger, where an ineligible product genuinely must come off the website.
 */
class CampaignSyncLedger
{
    use TracksDeliveryState;

    /**
     * The one destination. Campaigns and products both go to the website.
     */
    public const CHANNEL_WEBSITE = 'website';

    public const ENTITY_CAMPAIGN = 'campaign';

    public function __construct(
        private readonly CampaignWebsitePayloadBuilder $payloads,
    ) {}

    /**
     * Mark a campaign as needing delivery.
     *
     * @param  array<int, string>  $changedFields
     */
    public function markPending(
        Campaign $campaign,
        array $changedFields,
        string $channel = self::CHANNEL_WEBSITE,
    ): SyncRecord {
        $record = SyncRecord::firstOrNew([
            'channel' => $channel,
            'entity' => self::ENTITY_CAMPAIGN,
            'bc_id' => $campaign->bc_id,
        ]);

        $payload = $this->payloads->build($campaign);

        $record->fill([
            'status' => SyncStatus::Pending,
            'action' => $this->desiredAction($campaign),
            'changed_fields' => array_values($changedFields),
            'payload' => $payload,
            'payload_hash' => $this->hash($payload),
            'bc_modified_at' => $campaign->bc_modified_at,
            'dispatched_at' => now(),
            'started_at' => null,
            // A new desired state starts its own attempt count: failures against
            // the previous payload say nothing about this one.
            'attempts' => 0,
            'last_error' => null,
            'conflict_details' => null,
            'conflicted_at' => null,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Bring the ledger in line with what the campaign now needs, if anything.
     *
     * Returns the ledger row when work was opened, and null when the website is
     * already being asked for exactly this. Only the payload is compared,
     * because the action never varies — deactivation shows up as a changed
     * `activated` field rather than as a different instruction.
     *
     * $force re-opens the row regardless, for a deliberate resync after the
     * website has drifted from what the ledger believes it holds. It also
     * overrides the stale-campaign skip: a forced run is an instruction to
     * re-send, and quietly dropping ended campaigns would make it a half
     * measure on exactly the records someone is trying to repair. It is never
     * set by the scheduled path.
     *
     * @param  array<int, string>  $changedFields
     */
    public function reconcile(
        Campaign $campaign,
        array $changedFields = [],
        string $channel = self::CHANNEL_WEBSITE,
        bool $force = false,
    ): ?SyncRecord {
        $record = $this->find($campaign, $channel);
        $hash = $this->hash($this->payloads->build($campaign));

        if (! $force && $record !== null && $record->payload_hash === $hash) {
            // The ledger already wants exactly this. The action is not compared
            // because it cannot move: a campaign is always upserted.
            return null;
        }

        if (! $force && $this->isStale($campaign, $record)) {
            return null;
        }

        return $this->markPending($campaign, $changedFields, $channel);
    }

    /**
     * Whether this campaign is finished and was never sent, so there is no
     * point sending it now.
     *
     * Business Central keeps campaigns long after they end, and a first import
     * carries years of them. Publishing a promotion whose deadline has already
     * passed creates a post the website will never show, so the work is skipped.
     *
     * The "never sent" half is what makes this safe. A campaign already on the
     * website keeps receiving updates however old it is: once a promotion
     * exists, a correction to its title or audience still has to reach the
     * site, and freezing it at whatever was last delivered would silently
     * discard that. Only a campaign the website has never held is skipped,
     * because for that one there is genuinely nothing to keep in step.
     *
     * A campaign with no end date is never stale — it has not finished.
     *
     * This is an efficiency gate and nothing more. It is not a removal, and it
     * never takes a promotion down: WordPress still owns what a passed deadline
     * means for a promotion it already holds.
     */
    public function isStale(Campaign $campaign, ?SyncRecord $record = null): bool
    {
        $record ??= $this->find($campaign);

        if ($record?->delivered_payload !== null) {
            return false;
        }

        $endingDate = $campaign->ending_date;

        if ($endingDate === null) {
            return false;
        }

        // Compared as calendar dates in the application timezone: these are days
        // someone chose in Business Central, not instants.
        return $endingDate->lessThan(CarbonImmutable::now()->startOfDay());
    }

    /**
     * What the website should be asked to do with this campaign.
     *
     * Always an upsert. Whether the promotion should then be published, hidden,
     * expired or deleted is WordPress's decision, made from the state this
     * payload carries.
     */
    public function desiredAction(Campaign $campaign): WebsiteAction
    {
        return WebsiteAction::Upsert;
    }

    public function find(Campaign $campaign, string $channel = self::CHANNEL_WEBSITE): ?SyncRecord
    {
        return SyncRecord::query()
            ->forChannel($channel)
            ->where('entity', self::ENTITY_CAMPAIGN)
            ->where('bc_id', $campaign->bc_id)
            ->first();
    }

    /**
     * What would be sent to the website for this campaign right now.
     *
     * A removal never diffs, and a campaign with nothing delivered yet gets the
     * full payload; otherwise only the fields that moved are sent.
     */
    public function plan(Campaign $campaign, string $channel = self::CHANNEL_WEBSITE): DeliveryPlan
    {
        $record = $this->find($campaign, $channel);
        $desired = $this->payloads->build($campaign);

        return DeliveryPlan::make(
            WebsiteAction::Upsert,
            $desired,
            $record?->delivered_payload,
            $this->hash($desired),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(Campaign $campaign): array
    {
        return $this->payloads->build($campaign);
    }

    public function payloadHash(Campaign $campaign): string
    {
        return $this->hash($this->payloads->build($campaign));
    }
}
