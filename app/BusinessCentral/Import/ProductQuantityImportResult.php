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
    private function __construct(
        public ?ProductQuantity $quantity,
        public bool $created,
        public array $changedFields,
        public bool $skipped = false,
    ) {}

    /**
     * @param  array<int, string>  $changedFields
     */
    public static function imported(ProductQuantity $quantity, bool $created, array $changedFields): self
    {
        return new self($quantity, $created, $changedFields);
    }

    /**
     * No local product carries this row's Business Central id.
     *
     * Stock figures describe a product; without one there is nothing for them
     * to describe. Storing them anyway would leave a row that nothing reads and
     * that no later import would reconcile.
     */
    public static function skipped(): self
    {
        return new self(null, false, [], skipped: true);
    }

    /**
     * Whether this import altered the stored figures at all.
     */
    public function changed(): bool
    {
        return ! $this->skipped && ($this->created || $this->changedFields !== []);
    }

    public function hasChanged(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }
}
