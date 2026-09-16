<?php

namespace App\BusinessCentral\Import;

use App\Models\Product;

/**
 * The outcome of importing one Business Central row.
 */
final readonly class ProductImportResult
{
    /**
     * @param  array<int, string>  $changedFields  Normalised product columns whose
     *                                             values differ from the stored ones.
     */
    public function __construct(
        public Product $product,
        public bool $created,
        public array $changedFields,
    ) {}

    /**
     * Whether this import altered the stored product at all.
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
