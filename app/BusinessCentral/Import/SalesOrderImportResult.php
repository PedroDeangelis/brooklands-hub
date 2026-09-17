<?php

namespace App\BusinessCentral\Import;

use App\Models\SalesOrder;

/**
 * The outcome of importing one Business Central sales order.
 */
final readonly class SalesOrderImportResult
{
    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public SalesOrder $salesOrder,
        public bool $created,
        public array $changedFields,
    ) {}

    public function changed(): bool
    {
        return $this->created || $this->changedFields !== [];
    }

    public function hasChanged(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }
}
