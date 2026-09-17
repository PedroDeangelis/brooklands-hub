<?php

namespace App\Models;

use Database\Factories\ProductQuantityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest known Business Central quantity figures for a single item.
 *
 * Kept apart from Product because itemQuantities is a separate Business Central
 * entity with its own modification timestamp: stock moves far more often than
 * the item record, and the two arrive independently.
 */
#[Fillable([
    'bc_id',
    'sku',
    'type',
    'inventory',
    'qty_on_purchase_order',
    'qty_on_sales_order',
    'qty_on_transfer_order',
    'next_purchase_receipt_date',
    'next_transfer_receipt_date',
    'bc_modified_at',
    'bc_payload',
])]
class ProductQuantity extends Model
{
    /** @use HasFactory<ProductQuantityFactory> */
    use HasFactory;

    /**
     * The item these figures belong to.
     *
     * Joined on the Business Central id: quantities can arrive before the item
     * they describe, so there may be no local product yet.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bc_id', 'bc_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * The decimal casts must match the migration's scale. MySQL returns decimals
     * as strings, so filling the float 42.0 into an uncast column would mark it
     * dirty on every import and make unchanged quantities look changed forever.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inventory' => 'decimal:5',
            'qty_on_purchase_order' => 'decimal:5',
            'qty_on_sales_order' => 'decimal:5',
            'qty_on_transfer_order' => 'decimal:5',
            'next_purchase_receipt_date' => 'immutable_date',
            'next_transfer_receipt_date' => 'immutable_date',
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
