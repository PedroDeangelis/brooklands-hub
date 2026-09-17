<?php

namespace Tests\Feature\Sync\Payload;

use App\Models\SalesOrder;
use App\Sync\Payload\PayloadDiff;
use App\Sync\Payload\SalesOrderWebsitePayloadBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\SalesOrderFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The payload the website is given for a sales order.
 */
class SalesOrderWebsitePayloadBuilderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function builder(): SalesOrderWebsitePayloadBuilder
    {
        return app(SalesOrderWebsitePayloadBuilder::class);
    }

    public function test_the_payload_carries_exactly_these_keys(): void
    {
        $payload = $this->builder()->build(SalesOrder::factory()->make());

        $this->assertSame(
            ['attachments', 'bc_id', 'bc_status', 'created_at', 'customer_bc_id', 'customer_name', 'customer_note',
                'items', 'last_updated', 'number', 'order_date', 'po_number', 'sales_invoices', 'sales_shipments',
                'shipping_address', 'status', 'title', 'total_amount_excluding_tax', 'total_amount_including_tax',
                'total_tax_amount'],
            collect(array_keys($payload))->sort()->values()->all(),
        );

        $this->assertArrayNotHasKey('bc_payload', $payload);
        $this->assertArrayNotHasKey('work_description', $payload);
    }

    public function test_the_status_and_title_are_decided_here(): void
    {
        $order = SalesOrder::factory()->completed()->make(['number' => 'SO000123', 'customer_name' => 'Three Stone Vets']);

        $payload = $this->builder()->build($order);

        $this->assertSame('completed', $payload['status']);
        $this->assertSame('Released', $payload['bc_status']);
        $this->assertSame('Sales Order SO000123 for Three Stone Vets', $payload['title']);
    }

    public function test_items_use_the_repeater_field_names(): void
    {
        $order = SalesOrder::factory()->withLines([SalesOrderFactory::line([
            'bc_id' => 'line-1', 'item_bc_id' => 'item-1', 'item_number' => 'ITEM0001', 'description' => 'Drench 5L',
            'quantity' => 2.0, 'unit_price' => 50.25, 'quantity_to_ship' => 2.0, 'quantity_shipped' => 1.0, 'quantity_invoiced' => 0.0,
        ])])->make();

        $this->assertSame([[
            'bc_id' => 'line-1',
            'item_bc_id' => 'item-1',
            'item_number' => 'ITEM0001',
            'name' => 'Drench 5L',
            'quantity' => 2.0,
            'price' => 50.25,
            'quantity_to_ship' => 2.0,
            'quantity_shipped' => 1.0,
            'quantity_invoiced' => 0.0,
        ]], $this->builder()->build($order)['items']);
    }

    public function test_the_shipping_address_is_one_line(): void
    {
        $order = SalesOrder::factory()->make([
            'ship_to_address_1' => '372 Bond Road', 'ship_to_address_2' => 'Unit 2',
            'ship_to_city' => 'Te Awamutu', 'ship_to_state' => 'WKO', 'ship_to_post_code' => '3800',
        ]);

        $this->assertSame('372 Bond Road, Unit 2, Te Awamutu WKO 3800', $this->builder()->build($order)['shipping_address']);
    }

    public function test_the_customer_note_is_lifted_from_the_work_description(): void
    {
        $order = SalesOrder::factory()->make(['work_description' => "Customer Note: Back gate.\n\nInternal: call first"]);

        $this->assertSame('Back gate.', $this->builder()->build($order)['customer_note']);
    }

    /**
     * The website's text field holds a local wall-clock time, so that is what
     * is sent: a final website value, not a UTC instant for it to convert.
     */
    public function test_timestamps_are_sent_in_the_website_timezone(): void
    {
        config()->set('sync.delivery.timezone', 'Pacific/Auckland');

        $order = SalesOrder::factory()->make([
            'bc_created_at' => CarbonImmutable::parse('2026-06-14T20:07:51.167Z'),
            'bc_modified_at' => CarbonImmutable::parse('2026-06-15T00:23:21.653Z'),
        ]);

        $payload = $this->builder()->build($order);

        $this->assertSame('2026-06-15 08:07:51', $payload['created_at']);
        $this->assertSame('2026-06-15 12:23:21', $payload['last_updated']);
    }

    public function test_missing_dates_are_sent_as_empty_strings(): void
    {
        $order = SalesOrder::factory()->make(['order_date' => null, 'bc_created_at' => null, 'bc_modified_at' => null]);

        $payload = $this->builder()->build($order);

        $this->assertSame('', $payload['order_date']);
        $this->assertSame('', $payload['created_at']);
        $this->assertSame('', $payload['last_updated']);
    }

    public function test_amounts_are_numbers(): void
    {
        $order = SalesOrder::factory()->create(['total_amount_excluding_tax' => 100.5]);

        $this->assertSame(100.5, $this->builder()->build($order->fresh())['total_amount_excluding_tax']);
    }

    public function test_the_document_lists_are_atomic(): void
    {
        $this->assertTrue(PayloadDiff::isAtomic('items'));
        $this->assertTrue(PayloadDiff::isAtomic('sales_shipments'));
        $this->assertTrue(PayloadDiff::isAtomic('sales_invoices'));
    }
}
