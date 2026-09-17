<?php

namespace App\BusinessCentral\Import;

use App\Models\ProductQuantity;

/**
 * The outcome of importing one Business Central quantity row.
 */
final readonly class ProductQuantityImportResult
{
    /**
     * @param  array<int, string>  $changedFields  Normalised columns whose values
     *                                             differ from the stored ones.
     */
    public function __construct(
        public ProductQuantity $quantity,
        public bool $created,
        public array $changedFields,
    ) {}

    /**
     * Whether this import altered the stored figures at all.
     */
    public function changed(): bool
    {
        return $this->created || $this->changedFields !== [];
    }

    public function hasChanged(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }
}
