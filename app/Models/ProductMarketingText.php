<?php

namespace App\Models;

use Database\Factories\ProductMarketingTextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest known Business Central marketing copy for a single item.
 *
 * Kept apart from Product because marketingTextExt is a separate Business
 * Central entity with its own modification timestamp: the copy is authored by
 * people and changes on a different rhythm from the item record, and carrying
 * kilobytes of unchanged HTML through every catalogue sweep would cost far more
 * than reading it on its own schedule.
 */
#[Fillable([
    'bc_id',
    'sku',
    'marketing_text',
    'short_description',
    'bc_modified_at',
    'bc_payload',
])]
class ProductMarketingText extends Model
{
    /** @use HasFactory<ProductMarketingTextFactory> */
    use HasFactory;

    /**
     * The item this copy belongs to.
     *
     * Joined on the Business Central id: copy can arrive before the item it
     * describes, so there may be no local product yet.
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
