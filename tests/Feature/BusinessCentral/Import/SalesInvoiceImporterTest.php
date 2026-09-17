<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\SalesInvoiceImporter;
use App\Models\SalesInvoice;
use App\SalesInvoices\SalesInvoiceKind;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Normalising one Business Central posted sales invoice, header and lines.
 */
class SalesInvoiceImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => '974b40cf-a5b0-f111-aaa9-6045bde73f9b',
            'number' => 'INV103044',
            'orderNumber' => 'SO101164',
            'invoiceDate' => '2026-09-15',
            'orderDate' => '2026-09-14',
            'customerId' => 'cabd0691-8521-f111-8340-7ced8d32d199',
            'customerName' => 'Sara Is Woo Woo Ltd',
            'totalAmountExcludingTax' => 85.25,
            'totalTaxAmount' => 12.79,
            'totalAmountIncludingTax' => 98.04,
            'externalDocumentNumber' => 'PO-9',
            'shipToAddressLine1' => '55 Fake Street',
            'shipToAddressLine2' => '',
            'shipToCity' => 'Oamaru',
            'shipToState' => 'Otago',
            'shipToPostCode' => '9495',
            'workDescription' => 'Customer Note: Leave at the back gate.',
            'SystemCreatedAt' => '2026-09-15T01:36:05.47Z',
            'lastModifiedDateTime' => '2026-09-15T01:36:07.28Z',
            'lines' => [$this->bcLine()],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bcLine(array $overrides = []): array
    {
        return array_replace([
            'id' => 'line-0001',
            'documentId' => '974b40cf-a5b0-f111-aaa9-6045bde73f9b',
            'sequence' => 10000,
            'lineType' => 'Item',
            'itemId' => 'item-aaaa',
            'lineObjectNumber' => 'CT35',
            'description' => 'Hailea Electronic Thermometer',
            'quantity' => 11,
            'unitPrice' => 7.75,
        ], $overrides);
    }

    /**
     * A postedSalesCreditMemosExt row: no orderNumber, orderDate or
     * invoiceDate; creditMemoDate, returnOrderNumber and documentApiId instead.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function creditMemoRow(array $overrides = []): array
    {
        return array_replace([
            'id' => '3e18e2f7-298a-f111-8072-7ced8da08480',
            'documentApiId' => '47519c62-298a-f111-8072-7ced8da08480',
            'number' => 'CM104006',
            'returnOrderNumber' => 'RO000001',
            'creditMemoDate' => '2026-07-28',
            'customerId' => '8e431cfe-a51d-f111-8340-7ced8d32d199',
            'customerName' => 'Animates - Head Office Account',
            'totalAmountExcludingTax' => 100,
            'totalTaxAmount' => 15,
            'totalAmountIncludingTax' => 115,
            'externalDocumentNumber' => 'RB01',
            'shipToAddressLine1' => '21 McGiven Drive',
            'shipToAddressLine2' => 'Ridgewood',
            'shipToCity' => 'New Plymouth',
            'shipToState' => 'Taranaki',
            'shipToPostCode' => '4371',
            'workDescription' => '',
            'SystemCreatedAt' => '2026-07-28T02:13:58.163Z',
            'lastModifiedDateTime' => '2026-07-28T02:13:58.937Z',
            'lines' => [$this->bcLine(['lineType' => 'Account', 'itemId' => '00000000-0000-0000-0000-000000000000', 'lineObjectNumber' => '6931', 'description' => 'Rebates - Contractual', 'quantity' => 1, 'unitPrice' => 100])],
        ], $overrides);
    }

    private function importer(): SalesInvoiceImporter
    {
        return app(SalesInvoiceImporter::class);
    }

    // ------------------------------------------------------------ kind

    public function test_an_invoice_row_is_stored_as_an_invoice(): void
    {
        $invoice = $this->importer()->import($this->row())->salesInvoice;

        $this->assertSame(SalesInvoiceKind::Invoice, $invoice->kind());
        $this->assertNull($invoice->document_api_id);
    }

    public function test_a_credit_memo_row_maps_its_own_keys(): void
    {
        $memo = $this->importer()->import($this->creditMemoRow(), SalesInvoiceKind::CreditMemo)->salesInvoice;

        $this->assertSame(SalesInvoiceKind::CreditMemo, $memo->kind());
        $this->assertSame('CM104006', $memo->number);
        $this->assertSame('2026-07-28', $memo->invoice_date->toDateString());
        $this->assertNull($memo->order_date);
        $this->assertSame('RO000001', $memo->order_number);
        $this->assertSame('47519c62-298a-f111-8072-7ced8da08480', $memo->document_api_id);
        $this->assertSame('Sales Credit Memo CM104006 for Animates - Head Office Account', $memo->title());
    }

    /**
     * Some memos carry no documentApiId; their page id is then the only GUID
     * to read lines by and to hand the website for the PDF link.
     */
    public function test_a_credit_memo_without_a_document_api_id_falls_back_to_its_id(): void
    {
        $memo = $this->importer()->import($this->creditMemoRow(['documentApiId' => '']), SalesInvoiceKind::CreditMemo)->salesInvoice;

        $this->assertSame('3e18e2f7-298a-f111-8072-7ced8da08480', $memo->document_api_id);
    }

    /**
     * A G/L line — a rebate, a prepayment — is what most credit memos carry.
     * It is kept, with no item, so the memo still delivers an item row.
     */
    public function test_an_account_line_is_kept_without_an_item(): void
    {
        $memo = $this->importer()->import($this->creditMemoRow(), SalesInvoiceKind::CreditMemo)->salesInvoice;

        $this->assertCount(1, $memo->lines());
        $this->assertSame('', $memo->lines()[0]['item_bc_id']);
        $this->assertSame('6931', $memo->lines()[0]['item_number']);
        $this->assertSame('Rebates - Contractual', $memo->lines()[0]['description']);
    }

    public function test_the_kind_is_not_reported_as_a_change_on_reimport(): void
    {
        $this->importer()->import($this->creditMemoRow(), SalesInvoiceKind::CreditMemo);

        $result = $this->importer()->import($this->creditMemoRow(['externalDocumentNumber' => 'RB02']), SalesInvoiceKind::CreditMemo);

        $this->assertSame(['external_document_number'], $result->changedFields);
        $this->assertSame(SalesInvoiceKind::CreditMemo, $result->salesInvoice->kind());
    }

    /**
     * A posted document never changes kind. A row arriving as the other kind
     * is a wiring mistake and must not silently flip the website's status.
     */
    public function test_a_document_is_never_reimported_as_the_other_kind(): void
    {
        $this->importer()->import($this->creditMemoRow(), SalesInvoiceKind::CreditMemo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a Credit memo; refusing to re-import it as a Invoice');

        $this->importer()->import($this->creditMemoRow());
    }

    public function test_it_stores_an_invoice_from_a_business_central_row(): void
    {
        $result = $this->importer()->import($this->row());
        $invoice = $result->salesInvoice;

        $this->assertTrue($result->created);
        $this->assertSame('INV103044', $invoice->number);
        $this->assertSame('SO101164', $invoice->order_number);
        $this->assertSame('cabd0691-8521-f111-8340-7ced8d32d199', $invoice->customer_bc_id);
        $this->assertSame('2026-09-15', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-09-14', $invoice->order_date->toDateString());
        $this->assertSame('PO-9', $invoice->external_document_number);
        $this->assertSame('85.25000', $invoice->fresh()->total_amount_excluding_tax);
        $this->assertSame('55 Fake Street, Oamaru Otago 9495', $invoice->shipToAddress());
        $this->assertSame('2026-09-15T01:36:05.470Z', $invoice->fresh()->bc_created_at->toIso8601ZuluString('millisecond'));
    }

    public function test_the_bc_id_is_the_identity(): void
    {
        $this->importer()->import($this->row());
        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->created);
        $this->assertSame(1, SalesInvoice::count());
    }

    public function test_a_row_without_an_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer()->import($this->row(['id' => '']));
    }

    public function test_it_keeps_the_millisecond_precision_business_central_sent(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertSame(
            '2026-09-15T01:36:07.280Z',
            $result->salesInvoice->fresh()->bc_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_a_blank_business_central_date_is_null(): void
    {
        $invoice = $this->importer()->import($this->row(['orderDate' => '0001-01-01']))->salesInvoice;

        $this->assertNull($invoice->order_date);
    }

    /**
     * A posted invoice line has no shipping quantities. They come through as
     * zero so the line has the same shape as an order line.
     */
    public function test_lines_are_normalised_with_zero_shipping_quantities(): void
    {
        $invoice = $this->importer()->import($this->row())->salesInvoice;

        $this->assertEquals([[
            'bc_id' => 'line-0001',
            'sequence' => 10000,
            'item_bc_id' => 'item-aaaa',
            'item_number' => 'CT35',
            'description' => 'Hailea Electronic Thermometer',
            'quantity' => 11.0,
            'unit_price' => 7.75,
            'quantity_to_ship' => 0.0,
            'quantity_shipped' => 0.0,
            'quantity_invoiced' => 0.0,
        ]], $invoice->lines());
    }

    public function test_comment_lines_are_dropped(): void
    {
        $invoice = $this->importer()->import($this->row(['lines' => [
            $this->bcLine(['id' => 'c', 'lineType' => 'Comment', 'description' => 'Thanks for your order']),
            $this->bcLine(),
        ]]))->salesInvoice;

        $this->assertCount(1, $invoice->lines());
    }

    public function test_lines_are_sorted_by_sequence_then_id(): void
    {
        $lines = [
            $this->bcLine(['id' => 'b', 'sequence' => 20000]),
            $this->bcLine(['id' => 'a', 'sequence' => 10000]),
        ];

        $this->importer()->import($this->row(['lines' => $lines]));
        $result = $this->importer()->import($this->row(['lines' => array_reverse($lines)]));

        $this->assertSame(['a', 'b'], array_column($result->salesInvoice->lines(), 'bc_id'));
        $this->assertFalse($result->changed());
    }

    public function test_a_row_without_lines_keeps_the_stored_ones(): void
    {
        $row = $this->row();
        $this->importer()->import($row);

        unset($row['lines']);
        $result = $this->importer()->import(array_replace($row, ['externalDocumentNumber' => 'PO-10']));

        $this->assertCount(1, $result->salesInvoice->lines());
        $this->assertSame(['external_document_number'], $result->changedFields);
    }

    public function test_the_raw_payload_holds_the_header_only(): void
    {
        $invoice = $this->importer()->import($this->row())->salesInvoice;

        $this->assertArrayNotHasKey('lines', $invoice->bc_payload);
        $this->assertSame('INV103044', $invoice->bc_payload['number']);
    }

    public function test_an_unchanged_row_reports_no_change(): void
    {
        $this->importer()->import($this->row());

        $this->assertFalse($this->importer()->import($this->row())->changed());
    }

    public function test_the_title_follows_the_legacy_wording(): void
    {
        $invoice = $this->importer()->import($this->row())->salesInvoice;
        $this->assertSame('Sales Invoice INV103044 for Sara Is Woo Woo Ltd', $invoice->title());

        $invoice = $this->importer()->import($this->row(['id' => 'other-id', 'number' => '', 'customerName' => '']))->salesInvoice;
        $this->assertSame('Sales Invoice other-id for Unknown Customer', $invoice->title());
    }
}
