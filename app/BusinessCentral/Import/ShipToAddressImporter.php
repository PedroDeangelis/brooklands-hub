<?php

namespace App\BusinessCentral\Import;

use App\Models\Customer;
use App\Support\Canonical;
use InvalidArgumentException;

/**
 * Attaches one Business Central ship-to address to its customer.
 *
 * The addresses live on the customer as one normalised list, because that is
 * how the website stores them — a repeater on the customer post — and how the
 * customer payload delivers them: whole, in one atomic field.
 *
 * Normalisation is what makes that safe to hash and diff. Every entry has the
 * same keys in the same order, and the list is sorted by code then id, so the
 * same addresses arriving in a different order from Business Central are the
 * same list.
 */
class ShipToAddressImporter
{
    /**
     * Counties that ship from the South Island. Everything else, including an
     * unknown county, is treated as North Island — the legacy default.
     *
     * @var array<int, string>
     */
    private const SOUTH_ISLAND = [
        'southland', 'otago', 'canterbury', 'nelson', 'marlborough',
        'west coast', 'westcoast', 'west_coast', 'tasman',
    ];

    /**
     * Set a customer's ship-to list to exactly these rows.
     *
     * This is the path a complete sweep uses, and the only one that can remove
     * an address: a ship-to deleted in Business Central simply stops appearing,
     * so the per-row import below can never notice it has gone. Replacing the
     * list wholesale is what makes a deletion propagate.
     *
     * @param  array<int, array<string, mixed>>  $rows  Every ship-to row for one customer.
     *
     * @throws InvalidArgumentException when the customer number is empty.
     */
    public function replace(string $customerNumber, array $rows): ShipToAddressImportResult
    {
        if (trim($customerNumber) === '') {
            throw new InvalidArgumentException('A customer number is required to replace ship-to addresses.');
        }

        $customer = Customer::query()->where('number', $customerNumber)->first();

        if ($customer === null) {
            return ShipToAddressImportResult::skipped();
        }

        $before = $customer->shippingAddresses();
        $after = $this->sorted(array_map(fn (array $row): array => $this->normalizeAddress($row), $rows));
        $changed = ! Canonical::equals($before, $after);

        if ($changed) {
            $customer->shipping_addresses = $after;
            $customer->save();
        }

        return ShipToAddressImportResult::attached($customer, $changed);
    }

    /**
     * Add or replace one address on its customer, leaving the rest alone.
     *
     * Used by capped (--top) runs, which see only part of the endpoint and so
     * must never remove anything. A complete sweep uses replace() instead.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no id or no customer number.
     */
    public function import(array $row): ShipToAddressImportResult
    {
        $bcId = $this->string($row, 'id');
        $customerNumber = $this->string($row, 'customerNo');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central ship-to row is missing an "id".');
        }

        if ($customerNumber === '') {
            throw new InvalidArgumentException('Business Central ship-to row is missing a "customerNo".');
        }

        // Matched on the customer's number, never a guess: that is the only
        // identity the ship-to row carries.
        $customer = Customer::query()->where('number', $customerNumber)->first();

        if ($customer === null) {
            return ShipToAddressImportResult::skipped();
        }

        $before = $customer->shippingAddresses();
        $incoming = $this->normalizeAddress($row);

        // Replace the entry with this id, or add it; never merge field by field.
        $others = array_values(array_filter(
            $before,
            static fn (array $address): bool => ($address['bc_id'] ?? null) !== $incoming['bc_id'],
        ));

        $after = $this->sorted([...$others, $incoming]);
        $changed = ! Canonical::equals($before, $after);

        if ($changed) {
            $customer->shipping_addresses = $after;
            $customer->save();
        }

        return ShipToAddressImportResult::attached($customer, $changed);
    }

    /**
     * One address in the shape the website's repeater stores it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalizeAddress(array $row): array
    {
        $county = $this->string($row, 'county');

        return [
            'bc_id' => $this->string($row, 'id'),
            'code' => $this->string($row, 'code'),
            'name' => $this->string($row, 'name'),
            'address_1' => $this->string($row, 'address'),
            'address_2' => $this->string($row, 'address2'),
            'city' => $this->string($row, 'city'),
            'county' => $county,
            'postal_code' => $this->string($row, 'postCode'),
            'country' => $this->string($row, 'countryCode'),
            'region' => $this->region($county),
            'phone' => $this->string($row, 'phoneNo'),
            'is_rural' => filter_var($row['isRural'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $addresses
     * @return array<int, array<string, mixed>>
     */
    private function sorted(array $addresses): array
    {
        usort($addresses, static fn (array $a, array $b): int => [$a['code'], $a['bc_id']] <=> [$b['code'], $b['bc_id']]);

        return array_values($addresses);
    }

    private function region(string $county): string
    {
        return in_array(mb_strtolower(trim($county)), self::SOUTH_ISLAND, true)
            ? 'south-island'
            : 'north-island';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        return is_numeric($value) ? (string) $value : '';
    }
}
