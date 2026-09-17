<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read customers.
 *
 * Deliberately carries no filter. The legacy sync narrowed to four price groups
 * and thereby dropped four customers nobody could see; the hub fetches every
 * customer and lets the website decide what a blocked one or one with no price
 * group means, exactly as it does for campaigns.
 */
final class CustomersQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'customersExt';

    public const SELECT = 'id,number,displayName,type,addressLine1,addressLine2,city,state,postalCode,'
        .'country,phoneNumber,email,shipmentMethodCode,shippingLocationCode,blocked,'
        .'customerPriceGroup,customerDiscGroup,salespersonCode,lastModifiedDateTime';

    /**
     * Total ordering, so $skip can page without repeating or skipping rows.
     */
    public const ORDER_BY = 'lastModifiedDateTime asc,id asc';

    /**
     * Build the OData query for one page of customers.
     *
     * $since is strict "gt" for the reason documented on ItemsQuery::page(): the
     * checkpoint only moves once a complete result set has been fetched, so
     * records sharing its timestamp were all dispatched before it got there.
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
     * Build the OData query for one customer, by its Business Central number.
     *
     * @return array<string, scalar>
     */
    public static function forNumber(string $number): array
    {
        return [
            '$select' => self::SELECT,
            '$filter' => sprintf("number eq '%s'", str_replace("'", "''", $number)),
        ];
    }
}
