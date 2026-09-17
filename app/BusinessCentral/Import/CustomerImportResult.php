<?php

namespace App\BusinessCentral\Import;

use App\Models\Customer;

/**
 * The outcome of importing one Business Central customer row.
 */
final readonly class CustomerImportResult
{
    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public Customer $customer,
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
