<?php

namespace App\BusinessCentral\Import;

use App\Models\Product;
use App\Models\ProductQuantity;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Normalises a raw Business Central itemQuantities row and stores it.
 *
 * This is the only place that knows how BC quantity field names map onto local
 * columns. Figures are stored as sent; what they mean for the website is decided
 * when the website state is built.
 */
class ProductQuantityImporter
{
    /**
     * Business Central's placeholder for "no date".
     */
    private const EMPTY_DATES = ['0001-01-01', '0000-00-00'];

    /**
     * Columns excluded from change detection.
     *
     * bc_payload mirrors the whole row, so any incidental movement inside it
     * would make every quantity look changed; the timestamps are bookkeeping.
     *
     * @var array<int, string>
     */
    private const IGNORED_FOR_CHANGE_DETECTION = [
        'bc_payload',
        'created_at',
        'updated_at',
    ];

    /**
     * Create or update the quantity row for a Business Central item.
     *
     * The BC "id" GUID is the identity, and the same GUID the product carries, so
     * re-importing updates in place rather than creating a duplicate.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable BC id.
     */
    public function import(array $row): ProductQuantityImportResult
    {
        $bcId = trim((string) ($row['id'] ?? ''));

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central quantity row is missing an "id".');
        }

        // Stock figures belong to a product. Without one there is nothing for
        // them to describe, so the row is skipped rather than stored as an
        // orphan: the product may simply not have been imported yet, and the
        // next sweep picks the row up once it has been.
        //
        // Matching is by Business Central id only. A SKU match would be a guess,
        // and guessing here would attach one item's stock to another.
        if (! Product::query()->where('bc_id', $bcId)->exists()) {
            return ProductQuantityImportResult::skipped();
        }

        $quantity = ProductQuantity::firstOrNew(['bc_id' => $bcId]);
        $created = ! $quantity->exists;

        $quantity->fill($this->normalize($row));

        // Read the dirty set before saving: afterwards getDirty() is empty. Casts
        // are applied here, so a figure arriving as 42 against a stored "42.00000"
        // is correctly seen as unchanged.
        $changedFields = $created
            ? $this->withoutIgnored(array_keys($quantity->getAttributes()))
            : $this->withoutIgnored(array_keys($quantity->getDirty()));

        $quantity->save();

        return ProductQuantityImportResult::imported($quantity, $created, $changedFields);
    }

    /**
     * Map a Business Central row onto local columns.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'bc_id' => trim((string) ($row['id'] ?? '')),
            'sku' => trim((string) ($row['number'] ?? '')),
            'type' => trim((string) ($row['type'] ?? '')),
            'inventory' => $this->decimal($row['inventory'] ?? null),
            'qty_on_purchase_order' => $this->decimal($row['qtyOnPurchOrder'] ?? null),
            'qty_on_sales_order' => $this->decimal($row['qtyOnSalesOrder'] ?? null),
            'qty_on_transfer_order' => $this->decimal($row['qtyOnTransferOrder'] ?? null),
            'next_purchase_receipt_date' => $this->date($row['nextPurchaseReceiptDate'] ?? null),
            'next_transfer_receipt_date' => $this->date($row['nextTransferReceiptDate'] ?? null),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => $row,
        ];
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Business Central sends "0001-01-01" rather than null for an unset date.
     *
     * Whether a real date still lies ahead is a question about the present, so it
     * is answered when the website state is built, not frozen in here.
     */
    private function date(mixed $value): ?string
    {
        $date = trim((string) $value);

        if ($date === '' || in_array($date, self::EMPTY_DATES, true)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        $timestamp = trim((string) $value);

        if ($timestamp === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)->utc();
        } catch (Throwable) {
            return null;
        }
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
}
