<?php

namespace App\Products\Website;

/**
 * What the Business Central quantity figures mean for the website.
 *
 * Two on-hand numbers matter, and they are deliberately different:
 *
 *  - inventoryOrder is the item-level figure across every location, net of
 *    transfers. Backorder and partial-shipping displays read it and subtract
 *    open sales orders themselves, so it is left uncapped.
 *  - sellableStock is what could actually be sold: capped at the sellable
 *    location's holding, then net of transfers and open sales orders.
 *
 * Absent when no quantity row has been imported yet.
 */
final readonly class StockPosition
{
    public function __construct(
        public bool $known,
        public float $inventory = 0.0,
        public float $inventoryOrder = 0.0,
        public float $sellableStock = 0.0,
        public float $onPurchaseOrder = 0.0,
        public float $onSalesOrder = 0.0,
        public float $onTransferOrder = 0.0,
        public bool $locationCapped = false,
        public ?string $nextPurchaseReceiptDate = null,
        public ?string $nextTransferReceiptDate = null,
    ) {}

    /**
     * No quantity figures have been imported for this item.
     */
    public static function unknown(): self
    {
        return new self(false);
    }

    public function isKnown(): bool
    {
        return $this->known;
    }

    /**
     * Whether any stock is available to sell right now.
     */
    public function hasSellableStock(): bool
    {
        return $this->known && $this->sellableStock > 0;
    }

    /**
     * Whether more stock is expected, which is what backorder messaging hangs on.
     */
    public function expectsRestock(): bool
    {
        return $this->nextPurchaseReceiptDate !== null || $this->nextTransferReceiptDate !== null;
    }
}
