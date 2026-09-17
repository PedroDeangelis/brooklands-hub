<?php

namespace App\Models;

use App\Sync\SyncLedger;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The latest known Business Central state for a single item.
 *
 * Business Central remains the source of truth; this table is a local mirror
 * used for change detection and, in later phases, WordPress synchronisation.
 */
#[Fillable([
    'bc_id',
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
    'gppg',
    'bc_modified_at',
    'bc_payload',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Ledger rows tracking delivery of this product to each channel.
     *
     * Joined on the Business Central id rather than the local key, because the
     * ledger is keyed by BC identity.
     *
     * @return HasMany<SyncRecord, $this>
     */
    public function syncRecords(): HasMany
    {
        return $this->hasMany(SyncRecord::class, 'bc_id', 'bc_id');
    }

    /**
     * The latest Business Central quantity figures for this item.
     *
     * Joined on the Business Central id rather than the local key, and may be
     * absent: the quantity row is imported by its own flow and can lag behind.
     *
     * @return HasOne<ProductQuantity, $this>
     */
    public function quantity(): HasOne
    {
        return $this->hasOne(ProductQuantity::class, 'bc_id', 'bc_id');
    }

    /**
     * The latest Business Central marketing copy for this item.
     *
     * Joined on the Business Central id rather than the local key, and may be
     * absent: the copy is imported by its own flow and can lag behind, and many
     * items have no copy written for them at all.
     *
     * @return HasOne<ProductMarketingText, $this>
     */
    public function marketingText(): HasOne
    {
        return $this->hasOne(ProductMarketingText::class, 'bc_id', 'bc_id');
    }

    /**
     * The ledger row for the product delivery channel, if one exists.
     *
     * @return HasOne<SyncRecord, $this>
     */
    public function itemsSyncRecord(): HasOne
    {
        return $this->hasOne(SyncRecord::class, 'bc_id', 'bc_id')
            ->where('channel', SyncLedger::CHANNEL_ITEMS);
    }

    /**
     * Get the attributes that should be cast.
     *
     * The decimal casts must match the migration's scale. MySQL returns decimals
     * as strings, so filling the float 10.5 into an uncast column would mark it
     * dirty on every import and make unchanged products look changed forever.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:5',
            'inventory' => 'decimal:5',
            'weight' => 'decimal:5',
            'blocked' => 'boolean',
            'sales_blocked' => 'boolean',
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
