<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read products.
 *
 * Shared by every command that reads items so the test command and the import
 * command can never drift apart.
 */
final class ItemsQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'itemsExt';

    /**
     * The fields the product import reads.
     */
    public const SELECT = 'id,number,displayName,displayName2,unitPrice,blocked,salesBlocked,gtin,'
        .'inventory,weight,lastModifiedDateTime,itemCategoryId,priceListLines,itemAttributes,'
        .'itemDefaultDimensions,stockkeepingUnits,type';

    public const EXPAND = 'priceListLines,itemDefaultDimensions,itemAttributes,stockkeepingUnits';

    /**
     * "Non_x002D_Inventory" is Business Central's OData escaping of "Non-Inventory".
     */
    public const FILTER = "unitPrice gt 0 and (type eq 'Inventory' or type eq 'Non_x002D_Inventory') "
        ."and gppg eq 'FINISHED GOODS' and itemCategoryId ne 'RETIRE'";

    /**
     * Build the OData query for a bounded read of the items page.
     *
     * @return array<string, scalar>
     */
    public static function forTop(int $top): array
    {
        return [
            '$select' => self::SELECT,
            '$expand' => self::EXPAND,
            '$filter' => self::FILTER,
            '$orderby' => 'lastModifiedDateTime asc',
            '$top' => $top,
        ];
    }
}
