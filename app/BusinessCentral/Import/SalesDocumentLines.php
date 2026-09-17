<?php

namespace App\BusinessCentral\Import;

/**
 * Normalises the lines of a sales document — an order or a posted invoice —
 * into the shape stored and delivered.
 *
 * Shared by both importers because the lines genuinely are the same thing:
 * the standard API's salesOrderLines and salesInvoiceLines carry the same
 * fields, except that a posted invoice line has no shipping quantities. Those
 * come through as zero, which is also what an unshipped order line carries.
 *
 * Comment lines are dropped, as is a line with nothing on it. The result is
 * sorted by Business Central's own sequence, then id, so the same lines
 * arriving in a different order are the same list and open no delivery work.
 */
final class SalesDocumentLines
{
    /**
     * What Business Central sends as an item id on a line that has no item.
     */
    private const EMPTY_GUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $lines): array
    {
        $normalized = [];

        foreach (self::list($lines) as $line) {
            if (self::string($line, 'lineType') === 'Comment') {
                continue;
            }

            $itemBcId = self::guid($line['itemId'] ?? null);
            $description = self::string($line, 'description');
            $quantity = self::decimal($line['quantity'] ?? null);
            $unitPrice = self::decimal($line['unitPrice'] ?? null);

            if ($itemBcId === '' && $description === '' && $quantity === 0.0 && $unitPrice === 0.0) {
                continue;
            }

            $normalized[] = [
                'bc_id' => self::string($line, 'id'),
                'sequence' => (int) ($line['sequence'] ?? 0),
                'item_bc_id' => $itemBcId,
                'item_number' => self::string($line, 'lineObjectNumber'),
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'quantity_to_ship' => self::decimal($line['shipQuantity'] ?? null),
                'quantity_shipped' => self::decimal($line['shippedQuantity'] ?? null),
                'quantity_invoiced' => self::decimal($line['invoicedQuantity'] ?? null),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => [$a['sequence'], $a['bc_id']] <=> [$b['sequence'], $b['bc_id']]);

        return array_values($normalized);
    }

    /**
     * The rows inside a Business Central collection, or the bare list.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (isset($value['value']) && is_array($value['value'])) {
            $value = $value['value'];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * A GUID, or an empty string for the all-zero GUID Business Central sends
     * where there is no reference.
     */
    public static function guid(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        return $value === self::EMPTY_GUID ? '' : $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        return is_numeric($value) ? (string) $value : '';
    }

    private static function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
