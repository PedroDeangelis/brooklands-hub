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
     * Ordering used for every paged read.
     *
     * Ordering by the change timestamp is what makes an incremental fetch
     * meaningful, but timestamps are not unique — in this catalogue 5,617
     * products share only 603 distinct values, with up to 148 on one second.
     * The id tiebreaker makes the sequence total rather than partial, which is
     * what allows $skip to page through it without repeating or skipping rows.
     */
    public const ORDER_BY = 'lastModifiedDateTime asc,id asc';

    /**
     * Build the OData query for one page of items.
     *
     * $since selects only products changed strictly after that moment. "gt"
     * is safe here only because of how the checkpoint is advanced: it never
     * moves between pages, and only ever moves once an uncapped run has
     * fetched its entire result set. Products sharing a timestamp are
     * therefore all dispatched before the checkpoint can reach that timestamp,
     * so excluding them on the next run drops nothing.
     *
     * "ge" was the earlier spelling, and it could never terminate: the newest
     * record matches its own checkpoint, so it came back on every run for as
     * long as it stayed newest.
     *
     * A composite cursor — "(ts gt T) or (ts eq T and id gt ID)" — would be
     * the textbook fix, but this endpoint rejects it with
     * "The 'OR' operator is not supported on distinct fields on an OData
     * filter" (HTTP 501). Verified against the live API; do not reintroduce.
     *
     * @return array<string, scalar>
     */
    public static function page(int $pageSize, int $skip = 0, ?string $since = null): array
    {
        $query = [
            '$select' => self::SELECT,
            '$expand' => self::EXPAND,
            '$orderby' => self::ORDER_BY,
            '$top' => $pageSize,
        ];

        if ($skip > 0) {
            $query['$skip'] = $skip;
        }

        if ($since !== null) {
            $query['$filter'] = sprintf('lastModifiedDateTime gt %s', $since);
        }

        return $query;
    }

    /**
     * Build the OData query for one specific item, by its Business Central number.
     *
     * Deliberately carries no ordering, paging or since-filter: this asks for
     * one named record, not a slice of a result set.
     *
     * @return array<string, scalar>
     */
    public static function forSku(string $sku): array
    {
        return [
            '$select' => self::SELECT,
            '$expand' => self::EXPAND,
            '$filter' => sprintf("number eq '%s'", self::escape($sku)),
        ];
    }

    /**
     * Escape a value for an OData string literal.
     *
     * A single quote ends the literal, so one inside the value must be doubled
     * or the filter becomes malformed and the request is rejected.
     */
    private static function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Build the OData query for a bounded read of the items page.
     *
     * Retained for the single-page test command; paged reads use page().
     *
     * @return array<string, scalar>
     */
    public static function forTop(int $top, int $skip = 0): array
    {
        return self::page($top, $skip);
    }
}
