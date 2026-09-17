<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read stock figures.
 *
 * itemQuantities is a separate entity from itemsExt: it changes whenever stock
 * moves, so it is read on its own schedule rather than with the item record.
 */
final class ItemQuantitiesQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'itemQuantities';

    /**
     * The fields the quantity import reads.
     *
     * "type" is load-bearing rather than redundant: an item switching between
     * Inventory and Non-Inventory changes whether stock is tracked at all, and
     * that can happen with every figure unchanged.
     */
    public const SELECT = 'id,number,type,inventory,qtyOnPurchOrder,qtyOnSalesOrder,qtyOnTransferOrder,'
        .'nextPurchaseReceiptDate,nextTransferReceiptDate,lastModifiedDateTime';

    /**
     * Deliberately narrower than the item filter: no itemCategoryId exclusion.
     *
     * A quantity row for an item the product import skipped simply finds no
     * product to attach to, which is cheaper than missing a figure for one it kept.
     */
    public const FILTER = "unitPrice gt 0 and (type eq 'Inventory' or type eq 'Non_x002D_Inventory') "
        ."and gppg eq 'FINISHED GOODS'";

    /**
     * Build the OData query for a bounded read of the quantities page.
     *
     * @return array<string, scalar>
     */
    public static function forTop(int $top): array
    {
        return [
            '$select' => self::SELECT,
            '$filter' => self::FILTER,
            '$orderby' => 'lastModifiedDateTime asc',
            '$top' => $top,
        ];
    }
}
