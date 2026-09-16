<?php

namespace App\Support;

/**
 * Produces an order-stable representation of decoded JSON data.
 *
 * Business Central does not guarantee the key order of objects, nor that two
 * equivalent payloads are byte-identical. Comparing canonical forms means only a
 * genuine value difference counts as a change.
 */
final class Canonical
{
    /**
     * Recursively sort associative array keys, leaving list order intact.
     *
     * List order is preserved deliberately: the position of a price list line or a
     * stockkeeping unit is data, not formatting.
     */
    public static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(static fn (mixed $item): mixed => self::sort($item), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    /**
     * A stable string form of the value, suitable for equality comparison.
     */
    public static function encode(mixed $value): string
    {
        return (string) json_encode(
            self::sort($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Whether two decoded JSON values are equivalent ignoring key order.
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        return self::encode($a) === self::encode($b);
    }
}
