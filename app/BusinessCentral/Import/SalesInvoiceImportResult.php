<?php

namespace App\BusinessCentral\Import;

use App\Models\SalesInvoice;

/**
 * The outcome of importing one Business Central posted sales invoice.
 */
final readonly class SalesInvoiceImportResult
{
    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public SalesInvoice $salesInvoice,
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
