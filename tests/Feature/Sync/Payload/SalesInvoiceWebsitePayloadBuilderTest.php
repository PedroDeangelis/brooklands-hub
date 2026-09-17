<?php

namespace Tests\Feature\Sync\Payload;

use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Sync\Payload\SalesInvoiceWebsitePayloadBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The payload the website is given for a posted sales invoice.
 */
class SalesInvoiceWebsitePayloadBuilderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function builder(): SalesInvoiceWebsitePayloadBuilder
    {
        return app(SalesInvoiceWebsitePayloadBuilder::class);
    }

    public function test_the_payload_carries_exactly_these_keys(): void
    {
        $payload = $this->builder()->build(SalesInvoice::factory()->make());

        $this->assertSame(
            ['bc_id', 'created_at', 'customer_bc_id', 'customer_name', 'customer_note', 'document_api_id',
                'invoice_date', 'items', 'last_updated', 'number', 'order_date', 'order_number', 'po_number', 'related_order_bc_id',
                'shipping_address', 'status', 'title', 'total_amount_excluding_tax', 'total_amount_including_tax',
                'total_tax_amount'],
            collect(array_keys($payload))->sort()->values()->all(),
        );

        $this->assertArrayNotHasKey('bc_payload', $payload);
    }

    /**
     * The status is the one field the website routes on to tell an invoice
     * from a credit memo, and document_api_id is what it builds a credit
     * memo's PDF link from.
     */
    public function test_the_status_and_document_api_id_follow_the_kind(): void
    {
        $invoice = $this->builder()->build(SalesInvoice::factory()->make());

        $this->assertSame('invoice', $invoice['status']);
        $this->assertSame('', $invoice['document_api_id']);

        $memo = $this->builder()->build(SalesInvoice::factory()->creditMemo()->make(['document_api_id' => '47519c62-298a-f111-8072-7ced8da08480']));

        $this->assertSame('credit_memo', $memo['status']);
        $this->assertSame('47519c62-298a-f111-8072-7ced8da08480', $memo['document_api_id']);
    }

    public function test_a_credit_memo_title_uses_the_legacy_wording(): void
    {
        $memo = SalesInvoice::factory()->creditMemo()->make(['number' => 'CM104006', 'customer_name' => 'Animates']);

        $this->assertSame('Sales Credit Memo CM104006 for Animates', $this->builder()->build($memo)['title']);
    }

    public function test_the_title_is_decided_here(): void
    {
        $invoice = SalesInvoice::factory()->make(['number' => 'INV103044', 'customer_name' => 'Woo Woo Ltd']);

        $this->assertSame('Sales Invoice INV103044 for Woo Woo Ltd', $this->builder()->build($invoice)['title']);
    }

    /**
     * The order is sent as its number always, and as its Business Central id
     * while the order is still mirrored here. Business Central deletes an
     * order once fully invoiced, so the id can legitimately be missing.
     */
    public function test_the_related_order_is_resolved_by_number_when_it_is_still_here(): void
    {
        $order = SalesOrder::factory()->create(['number' => 'SO101164']);
        $invoice = SalesInvoice::factory()->forOrder($order)->make();

        $payload = $this->builder()->build($invoice);

        $this->assertSame('SO101164', $payload['order_number']);
        $this->assertSame($order->bc_id, $payload['related_order_bc_id']);
    }

    public function test_an_order_no_longer_here_leaves_the_id_empty_but_keeps_the_number(): void
    {
        $invoice = SalesInvoice::factory()->make(['order_number' => 'SO000001']);

        $payload = $this->builder()->build($invoice);

        $this->assertSame('SO000001', $payload['order_number']);
        $this->assertSame('', $payload['related_order_bc_id']);
    }

    public function test_items_use_the_repeater_field_names(): void
    {
        $invoice = SalesInvoice::factory()->withLines([SalesInvoiceFactory::line([
            'bc_id' => 'line-1', 'item_bc_id' => 'item-1', 'item_number' => 'CT35', 'description' => 'Thermometer',
            'quantity' => 11.0, 'unit_price' => 7.75,
        ])])->make();

        $this->assertSame([[
            'bc_id' => 'line-1',
            'item_bc_id' => 'item-1',
            'item_number' => 'CT35',
            'name' => 'Thermometer',
            'quantity' => 11.0,
            'price' => 7.75,
            'quantity_to_ship' => 0.0,
            'quantity_shipped' => 0.0,
            'quantity_invoiced' => 0.0,
        ]], $this->builder()->build($invoice)['items']);
    }

    public function test_timestamps_are_sent_in_the_website_timezone(): void
    {
        config()->set('sync.delivery.timezone', 'Pacific/Auckland');

        $invoice = SalesInvoice::factory()->make([
            'bc_created_at' => CarbonImmutable::parse('2026-09-15T01:36:05.47Z'),
            'bc_modified_at' => CarbonImmutable::parse('2026-09-15T01:36:07.28Z'),
        ]);

        $payload = $this->builder()->build($invoice);

        $this->assertSame('2026-09-15 13:36:05', $payload['created_at']);
        $this->assertSame('2026-09-15 13:36:07', $payload['last_updated']);
    }

    public function test_the_customer_note_is_lifted_from_the_work_description(): void
    {
        $invoice = SalesInvoice::factory()->make(['work_description' => 'Customer Note: Back gate.']);

        $this->assertSame('Back gate.', $this->builder()->build($invoice)['customer_note']);
    }
}
