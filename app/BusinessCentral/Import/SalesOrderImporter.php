<?php

namespace App\BusinessCentral\Import;

use App\Models\SalesOrder;
use App\SalesOrders\SalesOrderStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns a Business Central sales order, header and documents, into a local SalesOrder.
 *
 * The row is the salesOrdersExt header, optionally enriched by
 * SalesOrderDetailFetcher with `lines`, `salesShipments` and `salesInvoices`.
 * Each of those is only written when its key is present: a row without them
 * leaves whatever is already stored, so a header-only import can never wipe an
 * order's items. Every path that runs in production carries all three.
 *
 * Lines, shipments and invoices are normalised to fixed keys in a fixed order
 * and sorted, so the same documents arriving in a different order are the same
 * list and open no delivery work. The website status is derived here from the
 * Business Central status and the lines, and stored, so the list can filter on
 * it and the payload never has to work it out again.
 */
class SalesOrderImporter
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
     * What Business Central sends as a blank date.
     */
    private const BLANK_DATE = '0001-01-01';

    /**
     * Keys the header row may carry that are stored in their own columns.
     *
     * @var array<int, string>
     */
    private const DOCUMENT_KEYS = ['lines', 'salesShipments', 'salesInvoices'];

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable BC id.
     */
    public function import(array $row): SalesOrderImportResult
    {
        $bcId = $this->string($row, 'id');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central row is missing an "id".');
        }

        $order = SalesOrder::firstOrNew(['bc_id' => $bcId]);
        $created = ! $order->exists;

        $order->fill($this->normalize($row));

        if (array_key_exists('lines', $row)) {
            $order->lines = $this->normalizeLines($row['lines']);
        } elseif ($created) {
            $order->lines = [];
        }

        if (array_key_exists('salesShipments', $row)) {
            $order->shipments = $this->normalizeShipments($row['salesShipments']);
        } elseif ($created) {
            $order->shipments = [];
        }

        if (array_key_exists('salesInvoices', $row)) {
            $order->invoices = $this->normalizeInvoices($row['salesInvoices']);
        } elseif ($created) {
            $order->invoices = [];
        }

        $order->website_status = SalesOrderStatus::derive((string) $order->bc_status, $order->lines())->value;

        $changedFields = $created
            ? $this->withoutIgnored(array_keys($order->getAttributes()))
            : $this->withoutIgnored(array_keys($order->getDirty()));

        sort($changedFields);

        $order->save();

        return new SalesOrderImportResult($order, $created, $changedFields);
    }

    /**
     * Map a Business Central header row onto SalesOrder columns.
     *
     * The documents are absent here on purpose: see import().
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        return [
            'bc_id' => $this->string($row, 'id'),
            'number' => $this->string($row, 'number'),
            'customer_bc_id' => SalesDocumentLines::guid($row['customerId'] ?? null) ?: null,
            'customer_name' => $this->string($row, 'customerName'),
            'order_date' => $this->date($row['orderDate'] ?? null),
            'bc_status' => $this->string($row, 'status'),
            'external_document_number' => $this->string($row, 'externalDocumentNumber'),
            'total_amount_excluding_tax' => $this->decimal($row['totalAmountExcludingTax'] ?? null),
            'total_tax_amount' => $this->decimal($row['totalTaxAmount'] ?? null),
            'total_amount_including_tax' => $this->decimal($row['totalAmountIncludingTax'] ?? null),
            'fully_shipped' => filter_var($row['fullyShipped'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ship_to_address_1' => $this->string($row, 'shipToAddressLine1'),
            'ship_to_address_2' => $this->string($row, 'shipToAddressLine2'),
            'ship_to_city' => $this->string($row, 'shipToCity'),
            'ship_to_state' => $this->string($row, 'shipToState'),
            'ship_to_post_code' => $this->string($row, 'shipToPostCode'),
            'work_description' => $this->string($row, 'workDescription'),
            'bc_created_at' => $this->timestamp($row['SystemCreatedAt'] ?? null),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => array_diff_key($row, array_flip(self::DOCUMENT_KEYS)),
        ];
    }

    /**
     * The order's lines in the shape stored and delivered. See SalesDocumentLines.
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeLines(mixed $lines): array
    {
        return SalesDocumentLines::normalize($lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function normalizeShipments(mixed $shipments): array
    {
        $normalized = [];

        foreach (SalesDocumentLines::list($shipments) as $shipment) {
            $normalized[] = [
                'bc_id' => $this->string($shipment, 'id'),
                'number' => $this->string($shipment, 'number'),
                'external_document_number' => $this->string($shipment, 'externalDocumentNumber'),
            ];
        }

        return $this->sortedByNumber($normalized);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function normalizeInvoices(mixed $invoices): array
    {
        $normalized = [];

        foreach (SalesDocumentLines::list($invoices) as $invoice) {
            $normalized[] = [
                'bc_id' => $this->string($invoice, 'id'),
                'number' => $this->string($invoice, 'number'),
            ];
        }

        return $this->sortedByNumber($normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function sortedByNumber(array $documents): array
    {
        usort($documents, static fn (array $a, array $b): int => [$a['number'], $a['bc_id']] <=> [$b['number'], $b['bc_id']]);

        return array_values($documents);
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

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '' || str_starts_with(trim($value), self::BLANK_DATE)) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
