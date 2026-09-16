<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    'bc_modified_at',
    'bc_payload',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

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
