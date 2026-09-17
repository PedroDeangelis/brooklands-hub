<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read stock figures.
 *
 * itemQuantities is a separate entity from itemsExt: it changes whenever stock
 * moves, so it is read on its own schedule rather than with the item record.
 *
 * No website eligibility filtering. Whether a product belongs on the website is
 * decided in Laravel by App\Products\WebsiteEligibility, from data that has
 * already been imported. Filtering here would hide rows from that decision and
 * put the same rule in two places.
 *
 * WHY THERE IS NO INCREMENTAL FETCH
 *
 * This page's lastModifiedDateTime cannot drive one. Measured against the live
 * catalogue: 5,515 of 5,617 rows carry the unset sentinel 0001-01-01T00:00:00Z
 * while holding real stock figures, and the 102 that do carry a value mirror
 * their item record's timestamp to within a few milliseconds rather than
 * tracking stock movement. A "changed since" filter therefore returns nothing
 * once the checkpoint passes the newest of those 102 — a sync that looks
 * healthy while seeing no stock changes at all.
 *
 * The whole endpoint is swept instead, frequently and in pages. A row whose
 * figures have not moved costs nothing beyond the fetch: the importer sees no
 * change and opens no delivery work.
 *
 * If the Business Central page is ever changed to expose a genuine
 * stock-modified timestamp, the incremental machinery built for items
 * (App\BusinessCentral\Sync\BusinessCentralPager with a SyncCheckpoint) applies
 * here unchanged.
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
     * Ordering used for every paged read.
     *
     * By id alone, because it is the only total ordering this page offers:
     * ordering by lastModifiedDateTime would put 5,515 identical sentinel
     * values first and leave $skip paging to the server's tie-breaking, which
     * is not guaranteed stable between calls.
     */
    public const ORDER_BY = 'id asc';

    /**
     * Build the OData query for one page of quantity rows.
     *
     * @return array<string, scalar>
     */
    public static function page(int $pageSize, int $skip = 0): array
    {
        $query = [
            '$select' => self::SELECT,
            '$orderby' => self::ORDER_BY,
            '$top' => $pageSize,
        ];

        if ($skip > 0) {
            $query['$skip'] = $skip;
        }

        return $query;
    }

    /**
     * Build the OData query for one specific item's stock figures.
     *
     * @return array<string, scalar>
     */
    public static function forSku(string $sku): array
    {
        return [
            '$select' => self::SELECT,
            '$filter' => sprintf("number eq '%s'", str_replace("'", "''", $sku)),
        ];
    }

    /**
     * Build the OData query for a bounded read of the quantities page.
     *
     * @return array<string, scalar>
     */
    public static function forTop(int $top): array
    {
        return self::page($top);
    }
}
