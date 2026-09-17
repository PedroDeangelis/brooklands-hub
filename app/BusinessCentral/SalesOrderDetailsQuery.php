<?php

namespace App\BusinessCentral;

/**
 * The standard API pages that carry what a sales order header does not.
 *
 * Three reads per order, all against Microsoft's standard v2.0 API rather
 * than the custom catalogue page:
 *
 *   salesOrders({id})/salesOrderLines   what was ordered, shipped and invoiced
 *   salesShipments?orderNumber eq …     the shipment documents posted from it
 *   salesInvoices?orderNumber eq …      the invoices posted from it
 *
 * Lines carry no $select on purpose. The legacy sync had one and commented it
 * out; the page is small, and a field name that this tenant's version does
 * not expose would fail the whole request rather than just that field.
 */
final class SalesOrderDetailsQuery
{
    public const ORDERS_ENTITY_SET = 'salesOrders';

    public const LINES_PATH = 'salesOrderLines';

    public const SHIPMENTS_ENTITY_SET = 'salesShipments';

    public const INVOICES_ENTITY_SET = 'salesInvoices';

    public const SHIPMENTS_SELECT = 'id,number,externalDocumentNumber';

    public const INVOICES_SELECT = 'id,number';

    /**
     * The path to one order's lines. A GUID literal is unquoted in OData v4.
     */
    public static function linesPath(string $orderId): string
    {
        return sprintf(
            '%s(%s)/%s',
            self::ORDERS_ENTITY_SET,
            preg_replace('/[^0-9a-fA-F-]/', '', $orderId),
            self::LINES_PATH,
        );
    }

    /**
     * @return array<string, scalar>
     */
    public static function lines(): array
    {
        return [];
    }

    /**
     * Every shipment posted from one order, by the order's number.
     *
     * @return array<string, scalar>
     */
    public static function shipmentsFor(string $orderNumber): array
    {
        return [
            '$select' => self::SHIPMENTS_SELECT,
            '$filter' => sprintf("orderNumber eq '%s'", str_replace("'", "''", $orderNumber)),
        ];
    }

    /**
     * Every invoice posted from one order, by the order's number.
     *
     * @return array<string, scalar>
     */
    public static function invoicesFor(string $orderNumber): array
    {
        return [
            '$select' => self::INVOICES_SELECT,
            '$filter' => sprintf("orderNumber eq '%s'", str_replace("'", "''", $orderNumber)),
        ];
    }
}
