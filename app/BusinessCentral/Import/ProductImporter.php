<?php

namespace App\BusinessCentral\Import;

use App\Models\Product;
use App\Support\Canonical;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Normalises a raw Business Central itemsExt row and stores it as a Product.
 *
 * This is the only place that knows how BC field names map onto local columns.
 */
class ProductImporter
{
    /**
     * Columns excluded from change detection.
     *
     * bc_payload mirrors the whole BC row, so any nested movement would make every
     * product look changed; the timestamps are bookkeeping, not product data.
     *
     * @var array<int, string>
     */
    private const IGNORED_FOR_CHANGE_DETECTION = [
        'bc_payload',
        'created_at',
        'updated_at',
    ];

    /**
     * Nested Business Central collections that matter for change detection,
     * mapped from their BC key to the name reported in changedFields.
     *
     * These live inside bc_payload rather than in their own columns, so they are
     * compared against the previously stored payload instead of via getDirty().
     *
     * @var array<string, string>
     */
    private const NESTED_SECTIONS = [
        'priceListLines' => 'price_list_lines',
        'itemAttributes' => 'item_attributes',
        'itemDefaultDimensions' => 'item_default_dimensions',
        'stockkeepingUnits' => 'stockkeeping_units',
    ];

    /**
     * Create or update the Product for a Business Central row, reporting what changed.
     *
     * The BC "id" GUID is the identity: re-importing the same row updates the
     * existing Product rather than creating a duplicate.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable BC id.
     */
    public function import(array $row): ProductImportResult
    {
        $bcId = $this->string($row, 'id');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central row is missing an "id".');
        }

        $product = Product::firstOrNew(['bc_id' => $bcId]);

        // The stored payload must be read before fill() overwrites it.
        $previousPayload = is_array($product->bc_payload) ? $product->bc_payload : [];
        $created = ! $product->exists;

        $product->fill($this->normalize($row));

        // Read the dirty set before saving: afterwards getDirty() is empty.
        // Casts are applied here, so a decimal arriving as 2.0 against a stored
        // "2.00000" is correctly seen as unchanged.
        $changedFields = $created
            ? array_merge($this->presentFields($product), $this->presentNestedSections($row))
            : array_merge($this->dirtyFields($product), $this->changedNestedSections($previousPayload, $row));

        sort($changedFields);

        $product->save();

        return new ProductImportResult($product, $created, $changedFields);
    }

    /**
     * The normalised fields carried by a newly created product.
     *
     * @return array<int, string>
     */
    private function presentFields(Product $product): array
    {
        return $this->withoutIgnored(array_keys($product->getAttributes()));
    }

    /**
     * The normalised fields whose values differ from those already stored.
     *
     * @return array<int, string>
     */
    private function dirtyFields(Product $product): array
    {
        return $this->withoutIgnored(array_keys($product->getDirty()));
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private function withoutIgnored(array $fields): array
    {
        $fields = array_values(array_diff($fields, self::IGNORED_FOR_CHANGE_DETECTION));

        sort($fields);

        return $fields;
    }

    /**
     * The nested sections a newly created product carries data for.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function presentNestedSections(array $row): array
    {
        $present = [];

        foreach (self::NESTED_SECTIONS as $bcKey => $name) {
            if (($row[$bcKey] ?? []) !== []) {
                $present[] = $name;
            }
        }

        return $present;
    }

    /**
     * The nested sections whose contents differ from the stored payload.
     *
     * Compared canonically, so key reordering alone is not a change.
     *
     * @param  array<string, mixed>  $previousPayload
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function changedNestedSections(array $previousPayload, array $row): array
    {
        $changed = [];

        foreach (self::NESTED_SECTIONS as $bcKey => $name) {
            if (! Canonical::equals($previousPayload[$bcKey] ?? [], $row[$bcKey] ?? [])) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * Map a raw BC row onto the local column set.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        return [
            'sku' => $this->string($row, 'number'),
            'name' => $this->string($row, 'displayName'),
            'name_2' => $this->string($row, 'displayName2'),
            'type' => $this->string($row, 'type'),
            'price' => $this->decimal($row, 'unitPrice'),
            'inventory' => $this->decimal($row, 'inventory'),
            'weight' => $this->decimal($row, 'weight'),
            'blocked' => $this->boolean($row, 'blocked'),
            'sales_blocked' => $this->boolean($row, 'salesBlocked'),
            'gtin' => $this->string($row, 'gtin'),
            'item_category_id' => $this->string($row, 'itemCategoryId'),
            'bc_modified_at' => $this->timestamp($row, 'lastModifiedDateTime'),
            // Keep the row verbatim so nested collections are not lost.
            'bc_payload' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function decimal(array $row, string $key): float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function boolean(array $row, string $key): bool
    {
        return filter_var($row[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Business Central sends UTC timestamps with milliseconds; store them as UTC.
     *
     * @param  array<string, mixed>  $row
     */
    private function timestamp(array $row, string $key): ?CarbonImmutable
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
