<?php

namespace App\Products\Website;

use App\Models\ProductQuantity;
use Carbon\CarbonImmutable;

/**
 * Turns Business Central quantity figures into the stock position for the website.
 *
 * The location cap is the reason two on-hand figures exist. Business Central's
 * "inventory" counts every location, including ones nothing can be sold from
 * (write-off, quarantine and the like), so selling against it would promise stock
 * that cannot be picked. The cap is applied to the sellable figure only.
 */
class StockCalculator
{
    public function calculate(ProductQuantity $quantity, ProductLocation $location): StockPosition
    {
        $inventory = (float) $quantity->inventory;
        $onSalesOrder = (float) $quantity->qty_on_sales_order;
        $onTransferOrder = (float) $quantity->qty_on_transfer_order;

        // Item-level on-hand after transfers, deliberately uncapped: the displays
        // that read it subtract open sales orders for themselves.
        $inventoryOrder = max($inventory - $onTransferOrder, 0.0);

        // A resolved location caps what can be sold. Without one, there is nothing
        // to cap against, so the item-level figure stands.
        $capped = $location->isResolved()
            ? min($inventory, max((float) $location->inventory, 0.0))
            : $inventory;

        $sellableAfterTransfers = max($capped - $onTransferOrder, 0.0);

        // Units already picked or held against open orders cannot be sold twice.
        $sellableStock = max($sellableAfterTransfers - $onSalesOrder, 0.0);

        return new StockPosition(
            known: true,
            inventory: $inventory,
            inventoryOrder: $inventoryOrder,
            sellableStock: $sellableStock,
            onPurchaseOrder: (float) $quantity->qty_on_purchase_order,
            onSalesOrder: $onSalesOrder,
            onTransferOrder: $onTransferOrder,
            locationCapped: $location->isResolved(),
            nextPurchaseReceiptDate: $this->futureDate($quantity->next_purchase_receipt_date),
            nextTransferReceiptDate: $this->futureDate($quantity->next_transfer_receipt_date),
        );
    }

    /**
     * A receipt date matters only while it is still ahead.
     *
     * Evaluated on every read rather than at import, so a date quietly stops
     * counting the day it passes instead of lingering as a stale promise.
     */
    private function futureDate(?CarbonImmutable $date): ?string
    {
        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return $date->toDateString() > CarbonImmutable::now()->toDateString()
            ? $date->toDateString()
            : null;
    }
}
