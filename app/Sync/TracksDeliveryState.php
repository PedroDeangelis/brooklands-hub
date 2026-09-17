<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\SyncRecord;
use App\Support\Canonical;

/**
 * The delivery state transitions every ledger shares.
 *
 * These operate on a SyncRecord alone and never read the record they describe,
 * so they are identical whether the row tracks a product or a campaign. They
 * live here rather than in a base class because what differs between ledgers is
 * how a desired payload is built, not how a delivery attempt progresses.
 */
trait TracksDeliveryState
{
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
}
