<?php

namespace App\BusinessCentral\Import;

use App\BusinessCentral\SalesCreditMemosQuery;
use App\Models\SalesInvoice;
use App\SalesInvoices\SalesInvoiceKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns a Business Central posted sales invoice or credit memo, header and
 * lines, into a local SalesInvoice.
 *
 * The row is a postedSalesInvoicesExt or postedSalesCreditMemosExt header,
 * optionally enriched by the matching detail fetcher with `lines`. The lines
 * are only written when the key is present: a row without them leaves
 * whatever is already stored, so a header-only import can never wipe a
 * document's items.
 *
 * One importer for both kinds on purpose. Everything the ledger depends on —
 * identity, when lines are written, what counts as a change — must be the
 * same for an invoice and a credit memo, and the two pages differ in only
 * four header keys, which normalize() maps by kind.
 *
 * There is no status to derive. A posted document is what it is; the website
 * shows it as an invoice or a credit memo according to its kind.
 */
class SalesInvoiceImporter
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
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable BC id.
     */
    public function import(array $row, SalesInvoiceKind $kind = SalesInvoiceKind::Invoice): SalesInvoiceImportResult
    {
        $bcId = $this->string($row, 'id');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central row is missing an "id".');
        }

        $invoice = SalesInvoice::firstOrNew(['bc_id' => $bcId]);
        $created = ! $invoice->exists;

        // A posted document never changes kind. A row arriving as the other
        // kind can only be a wiring mistake, and flipping it would silently
        // change what the website shows the customer.
        if (! $created && $invoice->kind !== $kind->value) {
            throw new InvalidArgumentException(sprintf(
                'Business Central document %s is a %s; refusing to re-import it as a %s.',
                $bcId,
                $invoice->kind()->label(),
                $kind->label(),
            ));
        }

        $invoice->fill($this->normalize($row, $kind));

        if (array_key_exists('lines', $row)) {
            $invoice->lines = SalesDocumentLines::normalize($row['lines']);
        } elseif ($created) {
            $invoice->lines = [];
        }

        $changedFields = $created
            ? $this->withoutIgnored(array_keys($invoice->getAttributes()))
            : $this->withoutIgnored(array_keys($invoice->getDirty()));

        sort($changedFields);

        $invoice->save();

        return new SalesInvoiceImportResult($invoice, $created, $changedFields);
    }

    /**
     * Map a Business Central header row onto SalesInvoice columns.
     *
     * The two pages agree on everything except the date, the order reference
     * and the standard-API GUID. A credit memo's date is its creditMemoDate,
     * stored in invoice_date; its order reference is the return order it was
     * posted from; and it carries a documentApiId that an invoice does not
     * need, because an invoice's standard GUID is its id.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row, SalesInvoiceKind $kind = SalesInvoiceKind::Invoice): array
    {
        $byKind = match ($kind) {
            SalesInvoiceKind::Invoice => [
                'order_number' => $this->string($row, 'orderNumber'),
                'invoice_date' => $this->date($row['invoiceDate'] ?? null),
                'order_date' => $this->date($row['orderDate'] ?? null),
                'document_api_id' => null,
            ],
            SalesInvoiceKind::CreditMemo => [
                'order_number' => $this->string($row, 'returnOrderNumber'),
                'invoice_date' => $this->date($row['creditMemoDate'] ?? null),
                'order_date' => null,
                'document_api_id' => SalesCreditMemosQuery::documentApiId($row) ?: null,
            ],
        };

        return $byKind + [
            'bc_id' => $this->string($row, 'id'),
            'number' => $this->string($row, 'number'),
            'kind' => $kind->value,
            'customer_bc_id' => SalesDocumentLines::guid($row['customerId'] ?? null) ?: null,
            'customer_name' => $this->string($row, 'customerName'),
            'external_document_number' => $this->string($row, 'externalDocumentNumber'),
            'total_amount_excluding_tax' => $this->decimal($row['totalAmountExcludingTax'] ?? null),
            'total_tax_amount' => $this->decimal($row['totalTaxAmount'] ?? null),
            'total_amount_including_tax' => $this->decimal($row['totalAmountIncludingTax'] ?? null),
            'ship_to_address_1' => $this->string($row, 'shipToAddressLine1'),
            'ship_to_address_2' => $this->string($row, 'shipToAddressLine2'),
            'ship_to_city' => $this->string($row, 'shipToCity'),
            'ship_to_state' => $this->string($row, 'shipToState'),
            'ship_to_post_code' => $this->string($row, 'shipToPostCode'),
            'work_description' => $this->string($row, 'workDescription'),
            'bc_created_at' => $this->timestamp($row['SystemCreatedAt'] ?? null),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => array_diff_key($row, ['lines' => true]),
        ];
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
