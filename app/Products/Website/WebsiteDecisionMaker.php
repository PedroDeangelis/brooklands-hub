<?php

namespace App\Products\Website;

use App\Products\EligibilityResult;

/**
 * Turns what Business Central says about a product into what the website should do.
 *
 * The rules are ported from the website's own upsert logic, where they were
 * spread across stock-status writes and read-time filters and could only be
 * checked by inspecting a live product. They are gathered here so the decision
 * is one reviewable step, and so both a person and a later delivery job read
 * the same answer.
 */
class WebsiteDecisionMaker
{
    public const REASON_EXCLUDED = 'Product does not qualify for the website';

    public const REASON_SALES_BLOCKED = 'Sales are blocked in Business Central';

    public const REASON_NO_SELLABLE_STOCK = 'No sellable stock at the website location';

    public const REASON_NO_QUANTITY_DATA = 'No quantity figures have been imported yet';

    /**
     * Decide how a product should appear on the website.
     *
     * The order matters and mirrors the website's own precedence: exclusion
     * removes the product outright, a sales block then overrides everything
     * that remains, and only then does stock decide anything.
     */
    public function decide(
        EligibilityResult $eligibility,
        bool $tracksInventory,
        bool $salesBlocked,
        StockPosition $stock,
    ): WebsiteDecision {
        // An excluded product is removed from the website entirely, so none of
        // the stock questions apply to it.
        if (! $eligibility->eligible) {
            return new WebsiteDecision(
                existsOnWebsite: false,
                purchasable: false,
                manageStock: false,
                websiteQuantity: null,
                stockStatus: StockStatus::NotApplicable,
                unavailableReason: self::REASON_EXCLUDED,
            );
        }

        // A sales block leaves the product listed and browsable but unsellable.
        // It applies to every inventory type, which is what stops a blocked
        // non-inventory item resolving straight back to being in stock, and it
        // turns stock management off so no later quantity write can revive it.
        if ($salesBlocked) {
            return new WebsiteDecision(
                existsOnWebsite: true,
                purchasable: false,
                manageStock: false,
                websiteQuantity: null,
                stockStatus: StockStatus::OutOfStock,
                unavailableReason: self::REASON_SALES_BLOCKED,
            );
        }

        return $tracksInventory
            ? $this->inventoryDecision($stock)
            : $this->nonInventoryDecision();
    }

    /**
     * An inventory item carries a managed count: its sellable stock.
     *
     * Sellable stock is already capped at the website location and net of
     * transfers and open sales orders, so it is exactly what the website should
     * hold and what decides the status.
     */
    private function inventoryDecision(StockPosition $stock): WebsiteDecision
    {
        // Quantities arrive from a separate Business Central entity and may not
        // have landed yet. Treating an unknown figure as zero would be a guess
        // that reads as "sold out", so it is reported as unknown instead.
        if (! $stock->isKnown()) {
            return new WebsiteDecision(
                existsOnWebsite: true,
                purchasable: true,
                manageStock: true,
                websiteQuantity: null,
                stockStatus: StockStatus::OutOfStock,
                unavailableReason: self::REASON_NO_QUANTITY_DATA,
            );
        }

        $hasStock = $stock->hasSellableStock();

        return new WebsiteDecision(
            existsOnWebsite: true,
            purchasable: true,
            manageStock: true,
            websiteQuantity: $stock->sellableStock,
            stockStatus: $hasStock ? StockStatus::InStock : StockStatus::OutOfStock,
            unavailableReason: $hasStock ? null : self::REASON_NO_SELLABLE_STOCK,
        );
    }

    /**
     * A non-inventory item is never stock tracked and is normally in stock.
     *
     * Business Central requires an item's stockkeeping units to be removed
     * before it can become non-inventory, so there is no quantity to track and
     * nothing that could take it out of stock except a sales block.
     */
    private function nonInventoryDecision(): WebsiteDecision
    {
        return new WebsiteDecision(
            existsOnWebsite: true,
            purchasable: true,
            manageStock: false,
            websiteQuantity: null,
            stockStatus: StockStatus::InStock,
            unavailableReason: null,
        );
    }
}
