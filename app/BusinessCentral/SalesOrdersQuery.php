<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read sales orders.
 *
 * Only open orders live here. Business Central deletes a sales order once it
 * is fully invoiced, so a fetch sees the orders still in flight plus whatever
 * was touched since the checkpoint; an order that has gone is simply never
 * seen again and the website keeps the last state it was told.
 *
 * The page carries the header only. Lines, shipments and invoices come from
 * the standard API per order (see SalesOrderDetailsQuery), because the custom
 * page does not expose them and the header row cannot say how much of an
 * order has shipped.
 */
final class SalesOrdersQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'salesOrdersExt';

    public const SELECT = 'id,number,orderDate,customerId,customerName,totalAmountExcludingTax,totalTaxAmount,'
        .'totalAmountIncludingTax,fullyShipped,status,externalDocumentNumber,shipToAddressLine1,shipToAddressLine2,'
        .'shipToCity,shipToState,shipToPostCode,workDescription,SystemCreatedAt,lastModifiedDateTime';

    /**
     * Total ordering, so $skip can page without repeating or skipping rows.
     */
    public const ORDER_BY = 'lastModifiedDateTime asc,id asc';

    /**
     * Build the OData query for one page of sales orders.
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
     * Build the OData query for one sales order, by its Business Central number.
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
