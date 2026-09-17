<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page used to read ship-to addresses.
 *
 * A different API group from the catalogue pages: ship-tos live under "sales".
 *
 * Read as a sweep, never incrementally. A ship-to arriving before its customer
 * has to be skipped, and an incremental checkpoint would then advance past it
 * and lose the address until Business Central next touched it. The whole
 * endpoint is 1,289 rows — three requests — so re-reading it is cheaper than
 * being wrong.
 */
final class ShipToAddressesQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'sales';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'shipToAddresses';

    public const SELECT = 'id,customerNo,code,name,address,address2,city,postCode,countryCode,county,'
        .'phoneNo,isRural,shipmentMethodCode,lastModifiedDateTime';

    /**
     * id alone is a total ordering, which is all a sweep needs.
     */
    public const ORDER_BY = 'id asc';

    /**
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
     * Every ship-to for one customer, by the customer's number.
     *
     * @return array<string, scalar>
     */
    public static function forCustomerNumber(string $number): array
    {
        return [
            '$select' => self::SELECT,
            '$orderby' => self::ORDER_BY,
            '$filter' => sprintf("customerNo eq '%s'", str_replace("'", "''", $number)),
        ];
    }
}
