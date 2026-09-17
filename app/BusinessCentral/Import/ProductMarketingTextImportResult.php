<?php

namespace App\BusinessCentral\Import;

use App\Models\ProductMarketingText;

/**
 * The outcome of importing one Business Central marketing text row.
 */
final readonly class ProductMarketingTextImportResult
{
    /**
     * @param  array<int, string>  $changedFields  Normalised columns whose values
     *                                             differ from the stored ones.
     */
    private function __construct(
        public ?ProductMarketingText $marketingText,
        public bool $created,
        public array $changedFields,
        public bool $skipped = false,
    ) {}

    /**
     * @param  array<int, string>  $changedFields
     */
    public static function imported(ProductMarketingText $marketingText, bool $created, array $changedFields): self
    {
        return new self($marketingText, $created, $changedFields);
    }

    /**
     * No local product carries this row's Business Central id.
     *
     * Marketing copy describes a product; without one there is nothing for it
     * to describe. Storing it anyway would leave a row that nothing reads and
     * that no later import would reconcile.
     */
    public static function skipped(): self
    {
        return new self(null, false, [], skipped: true);
    }

    /**
     * Whether this import altered the stored copy at all.
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
