<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read products.
 *
 * Shared by every command that reads items so the test command and the import
 * command can never drift apart.
 *
 * This query deliberately carries no website eligibility rules. Filtering them
 * out in Business Central meant an item that failed one was never fetched, so
 * nothing could explain its absence from the website. Every item is imported
 * instead, and App\Products\WebsiteEligibility decides what qualifies.
 */
final class ItemsQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'itemsExt';

    /**
     * The fields the product import reads.
     *
     * gppg is selected because the finished-goods rule is now evaluated in
     * Laravel and needs the value stored locally.
     */
    public const SELECT = 'id,number,displayName,displayName2,unitPrice,blocked,salesBlocked,gtin,'
        .'inventory,weight,lastModifiedDateTime,itemCategoryId,gppg,priceListLines,itemAttributes,'
        .'itemDefaultDimensions,stockkeepingUnits,type';

    public const EXPAND = 'priceListLines,itemDefaultDimensions,itemAttributes,stockkeepingUnits';

    /**
     * Build the OData query for a bounded read of the items page.
     *
     * Ordering by lastModifiedDateTime keeps paging stable and is what a
     * modified-since filter will later page through.
     *
     * @return array<string, scalar>
     */
    public static function forTop(int $top, int $skip = 0): array
    {
        $query = [
            '$select' => self::SELECT,
            '$expand' => self::EXPAND,
            '$orderby' => 'lastModifiedDateTime asc',
            '$top' => $top,
        ];

        if ($skip > 0) {
            $query['$skip'] = $skip;
        }

        return $query;
    }
}
