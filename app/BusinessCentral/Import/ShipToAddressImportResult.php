<?php

namespace App\BusinessCentral\Import;

use App\Models\Customer;

/**
 * The outcome of attaching one ship-to address to its customer.
 */
final readonly class ShipToAddressImportResult
{
    private function __construct(
        public ?Customer $customer,
        public bool $changed,
        public bool $skipped,
    ) {}

    public static function attached(Customer $customer, bool $changed): self
    {
        return new self($customer, $changed, false);
    }

    /**
     * No customer carries this number yet. The row is left for the next sweep.
     */
    public static function skipped(): self
    {
        return new self(null, false, true);
    }
}
