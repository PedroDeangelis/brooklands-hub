<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Support\Canonical;

/**
 * Records whether a Business Central record still needs delivering to a channel.
 *
 * The ledger tracks delivery intent only; it never talks to a destination.
 */
class SyncLedger
{
    /**
     * The product delivery channel. Other channels arrive with their own entities.
     */
    public const CHANNEL_ITEMS = 'items';

    /**
     * Product columns that form the deliverable state.
     *
     * bc_id identifies the record rather than describing it, and the local id and
     * timestamps are bookkeeping, so none of them belong in the hash.
     *
     * @var array<int, string>
     */
    private const HASHED_COLUMNS = [
        'sku',
        'name',
        'name_2',
        'type',
        'price',
        'inventory',
        'weight',
        'blocked',
        'sales_blocked',
        'gtin',
        'item_category_id',
        'bc_modified_at',
    ];

    /**
     * Nested Business Central collections that form part of the deliverable state.
     *
     * @var array<int, string>
     */
    private const HASHED_NESTED_SECTIONS = [
        'priceListLines',
        'itemAttributes',
        'itemDefaultDimensions',
        'stockkeepingUnits',
    ];

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

        $record->fill([
            'status' => SyncStatus::Pending,
            'changed_fields' => array_values($changedFields),
            'payload_hash' => $this->payloadHash($product),
            'bc_modified_at' => $product->bc_modified_at,
            'dispatched_at' => now(),
            'last_error' => null,
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
     * Hash of the state a delivery would send.
     *
     * Canonical encoding means equivalent data always produces the same hash,
     * regardless of key ordering inside the Business Central payload.
     */
    public function payloadHash(Product $product): string
    {
        return hash('sha256', Canonical::encode($this->hashableState($product)));
    }

    /**
     * The normalised state a delivery would be built from.
     *
     * @return array<string, mixed>
     */
    private function hashableState(Product $product): array
    {
        $state = [];

        foreach (self::HASHED_COLUMNS as $column) {
            $value = $product->getAttribute($column);

            // Cast objects (dates) to string so the hash does not depend on the
            // in-memory representation.
            $state[$column] = $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value;
        }

        $payload = is_array($product->bc_payload) ? $product->bc_payload : [];

        foreach (self::HASHED_NESTED_SECTIONS as $section) {
            $state[$section] = $payload[$section] ?? [];
        }

        return $state;
    }
}
