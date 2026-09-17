<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read campaigns.
 *
 * Business Central calls these Campaigns; the website calls them Promotions.
 *
 * This query carries no activation or date filtering. A deactivated or expired
 * campaign must still be fetched, because the website needs to be told to stop
 * showing a promotion it is currently showing. Filtering them out in Business
 * Central would mean the one row that proves a promotion should end never
 * arrives.
 */
final class CampaignsQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'campaignsExt';

    /**
     * The fields the campaign import reads.
     *
     * campaignCustomers is deliberately absent. It is a navigation property,
     * and naming it here makes Business Central return only the fields listed
     * before it — the rest of this list silently disappears. It is requested
     * through EXPAND instead.
     *
     * lastDateModified is not selected: the legacy sync asked for it and never
     * read it. lastModifiedDateTime is the one the checkpoint uses.
     */
    public const SELECT = 'id,code,description,startingDate,endingDate,activated,lastModifiedDateTime';

    /**
     * The campaign's audience, as a nested collection of customer references.
     *
     * Only $expand materialises it; see SELECT.
     */
    public const EXPAND = 'campaignCustomers';

    /**
     * Ordering used for every paged read.
     *
     * Ordering by the change timestamp is what makes an incremental fetch
     * meaningful, and the id tiebreaker makes the sequence total rather than
     * partial, which is what allows $skip to page through it without repeating
     * or skipping rows.
     */
    public const ORDER_BY = 'lastModifiedDateTime asc,id asc';

    /**
     * Build the OData query for one page of campaigns.
     *
     * $since selects only campaigns changed strictly after that moment. "gt"
     * is safe because the checkpoint never moves between pages and only ever
     * moves once an uncapped run has fetched its entire result set, so
     * campaigns sharing a timestamp are all dispatched before the checkpoint
     * can reach it.
     *
     * A composite cursor — "(ts gt T) or (ts eq T and id gt ID)" — would be
     * the textbook fix for the boundary, but this endpoint rejects it with
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
     * Build the OData query for one specific campaign, by its code.
     *
     * Deliberately carries no ordering, paging or since-filter: this asks for
     * one named record, not a slice of a result set.
     *
     * @return array<string, scalar>
     */
    public static function forCode(string $code): array
    {
        return [
            '$select' => self::SELECT,
            '$expand' => self::EXPAND,
            '$filter' => sprintf("code eq '%s'", self::escape($code)),
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
}
