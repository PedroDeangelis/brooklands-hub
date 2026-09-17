<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read marketing copy.
 *
 * marketingTextExt is a separate entity from itemsExt because the copy is
 * edited by people rather than produced by trading: it is long, it changes
 * rarely, and fetching it with every item row would carry kilobytes of
 * unchanged HTML through every catalogue sweep.
 *
 * WHY THIS ONE IS INCREMENTAL
 *
 * Unlike itemQuantities, this page's lastModifiedDateTime is real. Measured
 * against the live catalogue: all 20 rows carry genuine timestamps spread
 * across months, none carry the 0001-01-01 sentinel, and a "gt" filter against
 * a mid-range moment correctly returns only the newer rows. The incremental
 * machinery built for items therefore applies here unchanged.
 *
 * The set is small, so a full reconciliation is cheap and runs nightly.
 *
 * No website eligibility filtering, for the same reason as items: whether a
 * product belongs on the website is decided in Laravel from data already
 * imported, and filtering here would put the same rule in two places.
 */
final class ItemMarketingTextQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'marketingTextExt';

    /**
     * The fields the marketing text import reads.
     *
     * itemId is the item GUID and the only thing that joins a row to a product;
     * itemNo is carried for logging and for the single-item fetch.
     */
    public const SELECT = 'itemId,itemNo,marketingText,lastModifiedDateTime';

    /**
     * Ordering used for every paged read.
     *
     * By timestamp for the incremental fetch, with itemId as the tiebreaker so
     * the sequence is total rather than partial. Without the tiebreaker, rows
     * sharing a timestamp would be ordered arbitrarily between calls and $skip
     * would repeat and skip them.
     */
    public const ORDER_BY = 'lastModifiedDateTime asc,itemId asc';

    /**
     * Build the OData query for one page of marketing text rows.
     *
     * $since selects only rows changed strictly after that moment. "gt" rather
     * than "ge" for the reason documented on ItemsQuery: the newest record
     * matches its own checkpoint, so "ge" would re-fetch it on every run
     * forever. It is safe because the checkpoint only ever advances after an
     * uncapped run has fetched its entire result set, so rows sharing a
     * timestamp are all dispatched before the checkpoint can reach it.
     *
     * A composite cursor is not attempted here: this endpoint family rejects
     * "OR" across distinct fields with HTTP 501. See ItemsQuery.
     *
     * @return array<string, scalar>
     */
    public static function page(int $pageSize, int $skip = 0, ?string $since = null): array
    {
        $query = [
            '$select' => self::SELECT,
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
     * Build the OData query for one specific item's marketing copy.
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
            '$filter' => sprintf("itemNo eq '%s'", self::escape($sku)),
        ];
    }

    /**
     * Build the OData query for a bounded read of the marketing text page.
     *
     * @return array<string, scalar>
     */
    public static function forTop(int $top, int $skip = 0): array
    {
        return self::page($top, $skip);
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
}
