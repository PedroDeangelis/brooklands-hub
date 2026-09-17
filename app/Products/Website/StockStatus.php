<?php

namespace App\Products\Website;

/**
 * The stock status the website should show.
 *
 * Deliberately a small vocabulary: the website only ever distinguishes between
 * something a customer can add to a basket and something they cannot.
 */
enum StockStatus: string
{
    case InStock = 'instock';

    case OutOfStock = 'outofstock';

    /**
     * The product is not on the website at all, so it has no stock status.
     */
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'In stock',
            self::OutOfStock => 'Out of stock',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function isInStock(): bool
    {
        return $this === self::InStock;
    }
}
