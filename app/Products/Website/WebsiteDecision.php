<?php

namespace App\Products\Website;

/**
 * The final answer about how a product should appear on the website.
 *
 * Everything else in App\Products\Website describes what Business Central says;
 * this says what the website should do about it. It is the last read-only step
 * before delivery, so that the decision can be reviewed here rather than being
 * discovered by looking at the live website.
 *
 * Nothing here talks to the website. These are conclusions, not actions.
 */
final readonly class WebsiteDecision
{
    public function __construct(
        /** Whether the product should be present on the website at all. */
        public bool $existsOnWebsite,
        /** Whether a customer may buy it, ignoring how much stock there is. */
        public bool $purchasable,
        /** Whether the website should track a stock count for it. */
        public bool $manageStock,
        /**
         * The stock count the website should hold.
         *
         * Null when stock is not managed: an untracked item has no meaningful
         * quantity, and sending zero would read as "sold out".
         */
        public ?float $websiteQuantity,
        public StockStatus $stockStatus,
        /**
         * Why a customer cannot buy this right now, or null when they can.
         */
        public ?string $unavailableReason,
    ) {}

    /**
     * Whether a customer could complete a purchase right now.
     *
     * Purchasable covers the product's own rules; this adds the stock question.
     */
    public function isAvailableToBuy(): bool
    {
        return $this->purchasable && $this->stockStatus->isInStock();
    }

    /**
     * The website quantity as a display string.
     */
    public function quantityLabel(): string
    {
        return $this->websiteQuantity === null
            ? 'Not tracked'
            : number_format($this->websiteQuantity, 2);
    }
}
