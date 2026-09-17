<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\WebsiteEligibility;
use App\Support\Canonical;
use App\Sync\Payload\DeliveryPlan;
use App\Sync\Payload\ProductWebsitePayloadBuilder;

/**
 * Records whether a Business Central record still needs delivering to a channel.
 *
 * The ledger tracks delivery intent only; it never talks to a destination.
 *
 * Intent is a pair: the action the website should be asked to perform, and the
 * payload it should be given. Either can move on its own — a product losing its
 * eligibility changes the action while every Business Central field stays
 * put — so both are compared against what was last delivered before any work is
 * opened. That is what stops an unchanged product being re-queued forever while
 * still catching a product that has only changed its mind about existing.
 */
class SyncLedger
{
    /**
     * The product delivery channel. Other channels arrive with their own entities.
     */
    public const CHANNEL_ITEMS = 'items';

    public function __construct(
        private readonly WebsiteEligibility $eligibility,
        private readonly ProductWebsitePayloadBuilder $payloads,
    ) {}

    /**
     * Mark a product as needing delivery.
     *
     * @param  array<int, string>  $changedFields
     */
    public function markPending(Product $product, array $changedFields, string $channel = self::CHANNEL_ITEMS): SyncRecord
    {
        $record = SyncRecord::firstOrNew([
            'channel' => $channel,
            'bc_id' => $product->bc_id,
        ]);

        $payload = $this->payloads->build($product);

        $record->fill([
            'status' => SyncStatus::Pending,
            'action' => $this->desiredAction($product),
            'changed_fields' => array_values($changedFields),
            'payload' => $payload,
            'payload_hash' => $this->hash($payload),
            'bc_modified_at' => $product->bc_modified_at,
            'dispatched_at' => now(),
            'started_at' => null,
            // A new desired state starts its own attempt count: failures against
            // the previous payload say nothing about this one.
            'attempts' => 0,
            'last_error' => null,
            // A new desired state is a new question. Whatever collided before
            // was about the payload that is now superseded, and leaving it would
            // explain a problem that may no longer exist.
            'conflict_details' => null,
            'conflicted_at' => null,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Bring the ledger in line with what the product now needs, if anything.
     *
     * Returns the ledger row when work was opened, and null when the website is
     * already being asked for exactly this. Both halves of the intent are
     * checked, so a product that becomes excluded is queued for removal even
     * though its Business Central fields are untouched, while a product that
     * has genuinely not moved is left alone however often it is re-imported.
     *
     * @param  array<int, string>  $changedFields
     */
    public function reconcile(Product $product, array $changedFields = [], string $channel = self::CHANNEL_ITEMS): ?SyncRecord
    {
        $record = $this->find($product, $channel);
        $action = $this->desiredAction($product);
        $hash = $this->hash($this->payloads->build($product));

        if ($record !== null && $record->action === $action && $record->payload_hash === $hash) {
            // The ledger already wants exactly this. Whether it has been
            // delivered yet is the queue's business, not ours: re-opening the
            // row here would reset a failure count or overwrite an in-flight
            // attempt with identical intent.
            return null;
        }

        // A removal carries no payload changes worth listing: the fields did not
        // move, the product's right to be on the website did.
        if ($action === WebsiteAction::Remove && $record?->action === WebsiteAction::Upsert) {
            $changedFields = ['website_eligibility'];
        }

        return $this->markPending($product, $changedFields, $channel);
    }

    /**
     * What the website should be asked to do with this product.
     */
    public function desiredAction(Product $product): WebsiteAction
    {
        return $this->desiredState($product)->action;
    }

    /**
     * The desired website state for a product, with the reasons behind it.
     */
    public function desiredState(Product $product): DesiredWebsiteState
    {
        return DesiredWebsiteState::from($this->eligibility->for($product));
    }

    /**
     * Claim a record for delivery.
     *
     * Recorded so an in-flight delivery is visible, and so a re-import can tell
     * "a job already holds this" from "nothing has started".
     */
    public function markSyncing(SyncRecord $record): SyncRecord
    {
        $record->fill([
            'status' => SyncStatus::Syncing,
            'started_at' => now(),
            'attempts' => $record->attempts + 1,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Mark a record as delivered, recording what the website now holds.
     *
     * The full desired payload is stored even when only a partial diff went
     * over the wire: the website now holds the whole state, and the next diff
     * must be computed against all of it rather than against the fragment sent.
     *
     * $deliveredHash is the hash of the state that was actually sent. If the
     * product moved on while the request was in flight, that no longer matches
     * the record and marking it synced would claim the website holds something
     * it was never given. The newer state is left pending instead, and false is
     * returned so the caller can say so.
     */
    public function markSynced(SyncRecord $record, ?string $deliveredHash = null): bool
    {
        $record->refresh();

        $deliveredHash ??= $record->payload_hash;

        if ($record->payload_hash !== $deliveredHash) {
            // A newer desired state exists. Record nothing about delivery: what
            // reached the website is already stale, and another delivery is owed.
            $record->fill([
                'status' => SyncStatus::Pending,
                'started_at' => null,
            ]);

            $record->save();

            return false;
        }

        $record->fill([
            'status' => SyncStatus::Synced,
            'delivered_action' => $record->action,
            'delivered_hash' => $record->payload_hash,
            'delivered_payload' => $record->payload,
            'synced_at' => now(),
            'last_error' => null,
        ]);

        $record->save();

        return true;
    }

    /**
     * Forget what the website was believed to hold, so the next delivery is full.
     *
     * The website has told us it has no record of this product, which means the
     * delivered payload we were diffing against describes something that does
     * not exist. Clearing it makes the next plan a full payload rather than a
     * partial the website could not apply.
     *
     * Deliberately not a failure: nothing went wrong, and the record goes back
     * to pending so the corrected delivery happens on its own.
     */
    public function forgetDelivered(SyncRecord $record): SyncRecord
    {
        $record->fill([
            'status' => SyncStatus::Pending,
            'started_at' => null,
            'delivered_action' => null,
            'delivered_hash' => null,
            'delivered_payload' => null,
            'synced_at' => null,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Record that the website could not take this product as addressed.
     *
     * Nothing is recorded as delivered and the desired state is left intact: the
     * product is still wanted, and the payload is still the one to send once the
     * collision is resolved. Deliberately not a failure, and never retried
     * automatically — the same payload would collide the same way.
     *
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    public function markConflicted(SyncRecord $record, array $conflicts, string $summary): SyncRecord
    {
        $record->fill([
            'status' => SyncStatus::Conflict,
            'started_at' => null,
            'conflict_details' => array_values($conflicts),
            'conflicted_at' => now(),
            'last_error' => $summary,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Record a failed delivery, leaving the desired state untouched.
     */
    public function markFailed(SyncRecord $record, string $error): SyncRecord
    {
        $record->fill([
            'status' => SyncStatus::Failed,
            'started_at' => null,
            'last_error' => $error,
            'failed_at' => now(),
        ]);

        $record->save();

        return $record;
    }

    /**
     * Hand a record back for another attempt without recording a failure.
     *
     * Used when a delivery could not proceed rather than did not succeed: the
     * state moved underneath it, or a lock was already held.
     */
    public function releaseToPending(SyncRecord $record): SyncRecord
    {
        $record->fill([
            'status' => SyncStatus::Pending,
            'started_at' => null,
        ]);

        $record->save();

        return $record;
    }

    /**
     * The ledger row for a product, if one exists.
     */
    public function find(Product $product, string $channel = self::CHANNEL_ITEMS): ?SyncRecord
    {
        return SyncRecord::query()
            ->forChannel($channel)
            ->where('bc_id', $product->bc_id)
            ->first();
    }

    /**
     * What would be sent to the website for this product right now.
     *
     * A removal never diffs, and a product with nothing delivered yet gets the
     * full payload; otherwise only the website fields that moved are sent.
     */
    public function plan(Product $product, string $channel = self::CHANNEL_ITEMS): DeliveryPlan
    {
        $record = $this->find($product, $channel);
        $payload = $this->payloads->build($product);

        // Only a payload delivered under the same action is a usable baseline.
        // A product coming back from removal has nothing on the website to
        // apply a partial change to, so it starts again from the full payload.
        $delivered = $record?->delivered_action === $this->desiredAction($product)
            ? $record?->delivered_payload
            : null;

        return DeliveryPlan::make(
            $this->desiredAction($product),
            $payload,
            $delivered,
            $this->hash($payload),
        );
    }

    /**
     * The complete website payload currently desired for a product.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(Product $product): array
    {
        return $this->payloads->build($product);
    }

    /**
     * Hash of a website payload.
     *
     * Canonical encoding means equivalent payloads always hash identically,
     * regardless of key ordering.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', Canonical::encode($payload));
    }

    /**
     * Hash of the payload a delivery would currently send for a product.
     */
    public function payloadHash(Product $product): string
    {
        return $this->hash($this->payloads->build($product));
    }
}
