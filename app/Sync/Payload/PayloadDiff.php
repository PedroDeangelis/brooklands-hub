<?php

namespace App\Sync\Payload;

use App\Support\Canonical;

/**
 * The difference between what the website should hold and what it last received.
 *
 * Comparison is between two FINAL WEBSITE PAYLOADS, never between Business
 * Central fields. One Business Central change often moves several website
 * values at once — a quantity arriving changes website_quantity, stock_status
 * and possibly purchasable — and only a comparison of the finished payloads
 * catches all of them.
 *
 * Nested values are compared whole and sent whole. See ATOMIC_FIELDS.
 */
final readonly class PayloadDiff
{
    /**
     * Fields compared and sent as complete units rather than merged key by key.
     *
     * These are structures whose parts only mean something together: a location
     * code without its inventory, or a price list missing a line, would be an
     * instruction the website could apply wrongly. Sending the whole value keeps
     * the website's job a plain assignment, and keeps this class free of a deep
     * merge whose edge cases would be hard to trust.
     *
     * The cost is precision: changing one attribute resends every attribute.
     * That is a deliberate trade, because these structures are small.
     *
     * @var array<int, string>
     */
    public const ATOMIC_FIELDS = [
        // Product
        'location',
        'brand',
        'categories',
        'attributes',
        'group_prices',
        // Campaign. The audience is a set: a customer joining or leaving it
        // means the whole normalised list is resent, because a positional
        // merge into a sorted array would silently reassign entries.
        'customers',
        // Customer. The ship-to list is a set of whole addresses; a positional
        // merge would silently reassign one customer's address to another.
        'shipping_addresses',
        // Contact. The billing block is one address; half of one is not an
        // address the website could store.
        'billing',
        // Sales order. Each list is a set of whole documents; a positional
        // merge would silently move a quantity from one line to another.
        'items',
        'sales_shipments',
        'sales_invoices',
    ];

    /**
     * @param  array<string, mixed>  $changes  The fields to send, empty when nothing moved.
     * @param  list<string>  $changedFields  The names of those fields, sorted.
     */
    private function __construct(
        public array $changes,
        public array $changedFields,
    ) {}

    /**
     * Compare a desired payload against the last delivered one.
     *
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $delivered
     */
    public static function between(array $desired, array $delivered): self
    {
        $changes = [];

        foreach ($desired as $field => $value) {
            if (! array_key_exists($field, $delivered) || ! Canonical::equals($value, $delivered[$field])) {
                $changes[$field] = $value;
            }
        }

        // A field the website holds but the desired payload no longer carries is
        // reported as an explicit null, so the website clears it rather than
        // keeping a stale value forever.
        foreach ($delivered as $field => $value) {
            if (! array_key_exists($field, $desired)) {
                $changes[$field] = null;
            }
        }

        ksort($changes);

        return new self($changes, array_keys($changes));
    }

    /**
     * Everything is a change: used when the website has never been told anything.
     *
     * @param  array<string, mixed>  $desired
     */
    public static function everything(array $desired): self
    {
        $changes = $desired;
        ksort($changes);

        return new self($changes, array_keys($changes));
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /**
     * Whether a field is sent whole rather than merged.
     */
    public static function isAtomic(string $field): bool
    {
        return in_array($field, self::ATOMIC_FIELDS, true);
    }
}
