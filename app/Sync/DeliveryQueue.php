<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Sync\Payload\DeliveryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decides which products are actually owed a delivery right now.
 *
 * The ledger's status says what happened last time; it does not say what needs
 * doing now. A record can sit at pending while the product has since changed
 * back to exactly what the website already holds, and a record can read synced
 * while its payload has moved on. So the plan is rebuilt from current state for
 * every candidate, and only the plan decides.
 *
 * Shared by the delivery command and the dashboard, so the counts someone reads
 * and the work that runs can never disagree.
 */
class DeliveryQueue
{
    /**
     * Ledger statuses that are never dispatched, and why.
     *
     * Syncing means a job already holds the record. Conflict needs data
     * corrected before another attempt could do anything but collide again.
     * Failed is deliberately left alone for now: automatic retry is a separate
     * decision, and re-sending on a schedule would hide a persistent problem.
     *
     * @var array<int, string>
     */
    private const NEVER_DISPATCHED = [
        SyncStatus::Syncing->value,
        SyncStatus::Conflict->value,
        SyncStatus::Failed->value,
    ];

    public function __construct(private readonly SyncLedger $ledger) {}

    /**
     * Work out what each candidate product needs.
     *
     * @param  array<int, string>  $skus  Limit to these SKUs, or all when empty.
     * @return Collection<int, DeliveryCandidate>
     */
    public function candidates(array $skus = [], string $channel = SyncLedger::CHANNEL_ITEMS): Collection
    {
        return Product::query()
            ->when($skus !== [], fn (Builder $query): Builder => $query->whereIn('sku', $skus))
            ->with('quantity')
            ->orderBy('sku')
            ->get()
            ->map(fn (Product $product): DeliveryCandidate => $this->assess($product, $channel));
    }

    /**
     * Assess one product against the website's last known state.
     */
    public function assess(Product $product, string $channel = SyncLedger::CHANNEL_ITEMS): DeliveryCandidate
    {
        $record = $this->ledger->find($product, $channel);

        // Rebuilt from current data rather than read off the record: an old
        // status cannot be trusted to describe what the product needs now.
        $plan = $this->ledger->plan($product, $channel);

        return new DeliveryCandidate(
            $product,
            $record,
            $plan->type,
            $this->blockedReason($record, $plan->type),
        );
    }

    /**
     * Why this product will not be dispatched, or null when it will be.
     */
    private function blockedReason(?SyncRecord $record, DeliveryType $type): ?string
    {
        if ($type === DeliveryType::None) {
            // The website already holds the desired state.
            return 'up to date';
        }

        if ($record === null) {
            // Work is owed but nothing has claimed it. The command opens the
            // ledger row before dispatching.
            return null;
        }

        return in_array($record->status->value, self::NEVER_DISPATCHED, true)
            ? $record->status->value
            : null;
    }
}
