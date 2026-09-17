<?php

namespace App\BusinessCentral\Import;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns a Business Central customer row into a local Customer.
 *
 * Ship-to addresses are not touched here: they arrive from their own
 * endpoint and are attached by ShipToAddressImporter. A customer re-import
 * therefore never wipes the addresses already gathered for it.
 */
class CustomerImporter
{
    /**
     * @var array<int, string>
     */
    private const IGNORED_FOR_CHANGE_DETECTION = [
        'bc_payload',
        'created_at',
        'updated_at',
    ];

    /**
     * Business Central's encoding of a blank enum value.
     *
     * "blocked" is an enum whose empty member is a space, which OData sends as
     * "_x0020_". Every one of the 1,360 customers carries it today.
     */
    private const ODATA_SPACE = '_x0020_';

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable BC id.
     */
    public function import(array $row): CustomerImportResult
    {
        $bcId = $this->string($row, 'id');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central row is missing an "id".');
        }

        $customer = Customer::firstOrNew(['bc_id' => $bcId]);
        $created = ! $customer->exists;

        $customer->fill($this->normalize($row));

        $changedFields = $created
            ? $this->withoutIgnored(array_keys($customer->getAttributes()))
            : $this->withoutIgnored(array_keys($customer->getDirty()));

        sort($changedFields);

        $customer->save();

        return new CustomerImportResult($customer, $created, $changedFields);
    }

    /**
     * Map a Business Central row onto Customer columns.
     *
     * shipping_addresses is deliberately absent: see the class docblock.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        return [
            'bc_id' => $this->string($row, 'id'),
            'number' => $this->string($row, 'number'),
            'display_name' => $this->string($row, 'displayName'),
            'type' => $this->string($row, 'type'),
            'address_1' => $this->string($row, 'addressLine1'),
            'address_2' => $this->string($row, 'addressLine2'),
            'city' => $this->string($row, 'city'),
            'state' => $this->string($row, 'state'),
            'postal_code' => $this->string($row, 'postalCode'),
            'country' => $this->string($row, 'country'),
            'phone' => $this->string($row, 'phoneNumber'),
            'email' => $this->string($row, 'email'),
            'shipment_method_code' => $this->string($row, 'shipmentMethodCode'),
            'shipping_location_code' => $this->string($row, 'shippingLocationCode'),
            'blocked' => $this->blocked($row['blocked'] ?? null),
            'customer_price_group' => $this->string($row, 'customerPriceGroup'),
            'customer_disc_group' => $this->string($row, 'customerDiscGroup'),
            'salesperson_code' => $this->string($row, 'salespersonCode'),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => $row,
        ];
    }

    /**
     * The blocked flag as the website's select field spells it.
     *
     * "none" for the encoded space or an empty value; otherwise Business
     * Central's own member name (Ship, Invoice, All), which the website's
     * choices use verbatim.
     */
    private function blocked(mixed $value): string
    {
        if (! is_string($value)) {
            return 'none';
        }

        $value = trim($value);

        return $value === '' || $value === self::ODATA_SPACE ? 'none' : $value;
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private function withoutIgnored(array $fields): array
    {
        return array_values(array_diff($fields, self::IGNORED_FOR_CHANGE_DETECTION));
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

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
