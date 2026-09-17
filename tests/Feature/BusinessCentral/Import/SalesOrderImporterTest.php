<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\SalesOrderImporter;
use App\Models\SalesOrder;
use App\SalesOrders\SalesOrderStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Normalising one Business Central sales order, header and documents.
 */
class SalesOrderImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const EMPTY_GUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => '8a2d5c1e-1b2c-4d3e-9f10-1112131415aa',
            'number' => 'SO000123',
            'orderDate' => '2026-06-14',
            'customerId' => '71431cfe-a51d-f111-8340-7ced8d32d199',
            'customerName' => '3 Stone Veterinary Services',
            'totalAmountExcludingTax' => 100.5,
            'totalTaxAmount' => 15.075,
            'totalAmountIncludingTax' => 115.575,
            'fullyShipped' => false,
            'status' => 'Released',
            'externalDocumentNumber' => 'PO-77',
            'shipToAddressLine1' => '372 Bond Road',
            'shipToAddressLine2' => '',
            'shipToCity' => 'Te Awamutu',
            'shipToState' => '',
            'shipToPostCode' => '3800',
            'workDescription' => 'Customer Note: Leave at the back gate.',
            'SystemCreatedAt' => '2026-06-14T20:07:51.167Z',
            'lastModifiedDateTime' => '2026-06-15T00:23:21.653Z',
            'lines' => [$this->bcLine()],
            'salesShipments' => [],
            'salesInvoices' => [],
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
            'sequence' => 10000,
            'lineType' => 'Item',
            'itemId' => 'item-aaaa',
            'lineObjectNumber' => 'ITEM0001',
            'description' => 'Drench 5L',
            'quantity' => 2,
            'unitPrice' => 50.25,
            'shipQuantity' => 2,
            'shippedQuantity' => 0,
            'invoicedQuantity' => 0,
        ], $overrides);
    }

    private function importer(): SalesOrderImporter
    {
        return app(SalesOrderImporter::class);
    }

    // ----------------------------------------------------------- header

    public function test_it_stores_an_order_from_a_business_central_row(): void
    {
        $result = $this->importer()->import($this->row());
        $order = $result->salesOrder;

        $this->assertTrue($result->created);
        $this->assertSame('SO000123', $order->number);
        $this->assertSame('71431cfe-a51d-f111-8340-7ced8d32d199', $order->customer_bc_id);
        $this->assertSame('3 Stone Veterinary Services', $order->customer_name);
        $this->assertSame('2026-06-14', $order->order_date->toDateString());
        $this->assertSame('Released', $order->bc_status);
        $this->assertSame('PO-77', $order->external_document_number);
        $this->assertSame('100.50000', $order->fresh()->total_amount_excluding_tax);
        $this->assertSame('372 Bond Road, Te Awamutu 3800', $order->shipToAddress());
        $this->assertSame('2026-06-14T20:07:51.167Z', $order->fresh()->bc_created_at->toIso8601ZuluString('millisecond'));
    }

    public function test_the_bc_id_is_the_identity(): void
    {
        $this->importer()->import($this->row());
        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->created);
        $this->assertSame(1, SalesOrder::count());
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
            '2026-06-15T00:23:21.653Z',
            $result->salesOrder->fresh()->bc_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_a_blank_business_central_date_is_null(): void
    {
        $order = $this->importer()->import($this->row(['orderDate' => '0001-01-01']))->salesOrder;

        $this->assertNull($order->order_date);
    }

    public function test_the_empty_guid_customer_is_stored_as_no_customer(): void
    {
        $order = $this->importer()->import($this->row(['customerId' => self::EMPTY_GUID]))->salesOrder;

        $this->assertNull($order->customer_bc_id);
    }

    /**
     * The documents live in their own columns; keeping them in the raw
     * payload too would double the row for nothing.
     */
    public function test_the_raw_payload_holds_the_header_only(): void
    {
        $order = $this->importer()->import($this->row())->salesOrder;

        $this->assertArrayNotHasKey('lines', $order->bc_payload);
        $this->assertArrayNotHasKey('salesShipments', $order->bc_payload);
        $this->assertArrayNotHasKey('salesInvoices', $order->bc_payload);
        $this->assertSame('SO000123', $order->bc_payload['number']);
    }

    // ------------------------------------------------------------ lines

    public function test_lines_are_normalised(): void
    {
        $order = $this->importer()->import($this->row())->salesOrder;

        // assertEquals, not assertSame: the JSON column hands a whole float
        // back as an int. The payload builder casts every quantity to float.
        $this->assertEquals([[
            'bc_id' => 'line-0001',
            'sequence' => 10000,
            'item_bc_id' => 'item-aaaa',
            'item_number' => 'ITEM0001',
            'description' => 'Drench 5L',
            'quantity' => 2.0,
            'unit_price' => 50.25,
            'quantity_to_ship' => 2.0,
            'quantity_shipped' => 0.0,
            'quantity_invoiced' => 0.0,
        ]], $order->lines());
    }

    public function test_comment_lines_are_dropped(): void
    {
        $order = $this->importer()->import($this->row(['lines' => [
            $this->bcLine(['id' => 'c', 'lineType' => 'Comment', 'description' => 'Please pack carefully']),
            $this->bcLine(),
        ]]))->salesOrder;

        $this->assertCount(1, $order->lines());
        $this->assertSame('line-0001', $order->lines()[0]['bc_id']);
    }

    public function test_a_line_with_nothing_on_it_is_dropped(): void
    {
        $order = $this->importer()->import($this->row(['lines' => [
            $this->bcLine(['id' => 'blank', 'itemId' => self::EMPTY_GUID, 'lineObjectNumber' => '', 'description' => '', 'quantity' => 0, 'unitPrice' => 0]),
            $this->bcLine(),
        ]]))->salesOrder;

        $this->assertCount(1, $order->lines());
    }

    public function test_the_empty_guid_item_is_stored_as_no_item(): void
    {
        $order = $this->importer()->import($this->row(['lines' => [
            $this->bcLine(['itemId' => self::EMPTY_GUID, 'lineObjectNumber' => 'FREIGHT', 'description' => 'Freight']),
        ]]))->salesOrder;

        $this->assertSame('', $order->lines()[0]['item_bc_id']);
        $this->assertSame('FREIGHT', $order->lines()[0]['item_number']);
    }

    /**
     * A reorder from Business Central must not read as a change, or every
     * fetch would open a delivery.
     */
    public function test_lines_are_sorted_by_sequence_then_id(): void
    {
        $lines = [
            $this->bcLine(['id' => 'b', 'sequence' => 20000]),
            $this->bcLine(['id' => 'a', 'sequence' => 10000]),
        ];

        $this->importer()->import($this->row(['lines' => $lines]));
        $result = $this->importer()->import($this->row(['lines' => array_reverse($lines)]));

        $this->assertSame(['a', 'b'], array_column($result->salesOrder->lines(), 'bc_id'));
        $this->assertFalse($result->changed());
    }

    public function test_a_business_central_collection_is_unwrapped(): void
    {
        $order = $this->importer()->import($this->row(['lines' => ['value' => [$this->bcLine()]]]))->salesOrder;

        $this->assertCount(1, $order->lines());
    }

    /**
     * A row that carries no documents at all leaves what is stored, so a
     * header-only import can never wipe an order's items.
     */
    public function test_a_row_without_documents_keeps_the_stored_ones(): void
    {
        $row = $this->row();
        $this->importer()->import($row);

        unset($row['lines'], $row['salesShipments'], $row['salesInvoices']);
        $result = $this->importer()->import(array_replace($row, ['externalDocumentNumber' => 'PO-78']));

        $this->assertCount(1, $result->salesOrder->lines());
        $this->assertSame(['external_document_number'], $result->changedFields);
    }

    public function test_an_empty_lines_list_clears_the_lines(): void
    {
        $this->importer()->import($this->row());
        $result = $this->importer()->import($this->row(['lines' => []]));

        $this->assertSame([], $result->salesOrder->lines());
        $this->assertContains('lines', $result->changedFields);
    }

    // ------------------------------------------------- shipments, invoices

    public function test_shipments_and_invoices_are_normalised_and_sorted(): void
    {
        $order = $this->importer()->import($this->row([
            'salesShipments' => [
                ['id' => 's2', 'number' => 'SH002', 'externalDocumentNumber' => 'PO-77'],
                ['id' => 's1', 'number' => 'SH001', 'externalDocumentNumber' => 'PO-77'],
            ],
            'salesInvoices' => [
                ['id' => 'i1', 'number' => 'INV001'],
            ],
        ]))->salesOrder;

        $this->assertSame([
            ['bc_id' => 's1', 'number' => 'SH001', 'external_document_number' => 'PO-77'],
            ['bc_id' => 's2', 'number' => 'SH002', 'external_document_number' => 'PO-77'],
        ], $order->shipments());

        $this->assertSame([['bc_id' => 'i1', 'number' => 'INV001']], $order->invoices());
    }

    // ------------------------------------------------------------ status

    public function test_the_website_status_is_derived_and_stored(): void
    {
        $order = $this->importer()->import($this->row())->salesOrder;
        $this->assertSame(SalesOrderStatus::Processing, $order->websiteStatus());

        $result = $this->importer()->import($this->row(['lines' => [
            $this->bcLine(['shippedQuantity' => 2, 'invoicedQuantity' => 2]),
        ]]));

        $this->assertSame(SalesOrderStatus::Completed, $result->salesOrder->websiteStatus());
        $this->assertContains('website_status', $result->changedFields);
    }

    public function test_the_status_is_derived_from_the_stored_lines_when_the_row_has_none(): void
    {
        $this->importer()->import($this->row(['lines' => [
            $this->bcLine(['shippedQuantity' => 2, 'invoicedQuantity' => 2]),
        ]]));

        $row = $this->row();
        unset($row['lines'], $row['salesShipments'], $row['salesInvoices']);

        $this->assertSame(SalesOrderStatus::Completed, $this->importer()->import($row)->salesOrder->websiteStatus());
    }

    // ------------------------------------------------------------ change

    public function test_an_unchanged_row_reports_no_change(): void
    {
        $this->importer()->import($this->row());

        $this->assertFalse($this->importer()->import($this->row())->changed());
    }

    public function test_a_changed_field_is_reported(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row(['shipToCity' => 'Hamilton']));

        $this->assertSame(['ship_to_city'], $result->changedFields);
    }

    public function test_the_title_follows_the_legacy_wording(): void
    {
        $order = $this->importer()->import($this->row())->salesOrder;
        $this->assertSame('Sales Order SO000123 for 3 Stone Veterinary Services', $order->title());

        $order = $this->importer()->import($this->row(['id' => 'other-id', 'number' => '', 'customerName' => '']))->salesOrder;
        $this->assertSame('Sales Order other-id for Unknown Customer', $order->title());
    }
}
