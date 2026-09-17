<?php

namespace Tests\Feature\Sync\Payload;

use App\DocumentAttachments\AttachmentParentType;
use App\Models\Customer;
use App\Models\DocumentAttachment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Sync\Payload\AttachmentsPayload;
use App\Sync\Payload\CustomerWebsitePayloadBuilder;
use App\Sync\Payload\SalesOrderWebsitePayloadBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The attachments a record's website payload carries.
 *
 * The ordering is the load-bearing part. The payload is hashed to decide
 * whether a delivery is owed, so a list whose order moved between reads would
 * open delivery work that changes nothing on the website.
 */
class AttachmentsPayloadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_carries_the_id_and_the_file_name(): void
    {
        $product = Product::factory()->create();

        DocumentAttachment::factory()->forProduct($product)->create([
            'bc_id' => 'ffffffff-0000-0000-0000-000000000001',
            'file_name' => 'safety-data-sheet.pdf',
        ]);

        $this->assertSame(
            [['bc_id' => 'ffffffff-0000-0000-0000-000000000001', 'file_name' => 'safety-data-sheet.pdf']],
            AttachmentsPayload::for(AttachmentParentType::Product, $product->bc_id),
        );
    }

    public function test_a_record_with_no_files_carries_an_empty_list(): void
    {
        $product = Product::factory()->create();

        $this->assertSame([], AttachmentsPayload::for(AttachmentParentType::Product, $product->bc_id));
    }

    public function test_an_empty_parent_id_carries_an_empty_list(): void
    {
        $this->assertSame([], AttachmentsPayload::for(AttachmentParentType::Product, ''));
    }

    /**
     * Sorted by filename then id, so the same files always hash identically
     * however Business Central happened to return them.
     */
    public function test_the_list_is_ordered_deterministically(): void
    {
        $product = Product::factory()->create();

        DocumentAttachment::factory()->forProduct($product)->create(['file_name' => 'zebra.pdf']);
        DocumentAttachment::factory()->forProduct($product)->create(['file_name' => 'alpha.pdf']);

        $this->assertSame(
            ['alpha.pdf', 'zebra.pdf'],
            array_column(AttachmentsPayload::for(AttachmentParentType::Product, $product->bc_id), 'file_name'),
        );
    }

    /**
     * A Business Central id is only unique within a parent type, so a customer
     * sharing a product's id must not collect that product's files.
     */
    public function test_it_does_not_mix_parents_that_share_an_id(): void
    {
        $product = Product::factory()->create(['bc_id' => 'aaaaaaaa-0000-0000-0000-000000000001']);
        $customer = Customer::factory()->create(['bc_id' => 'aaaaaaaa-0000-0000-0000-000000000001']);

        DocumentAttachment::factory()->forProduct($product)->create(['file_name' => 'product.pdf']);

        $this->assertSame([], AttachmentsPayload::for(AttachmentParentType::Customer, $customer->bc_id));
    }

    public function test_a_customers_payload_carries_its_attachments(): void
    {
        $customer = Customer::factory()->create();

        DocumentAttachment::factory()->forCustomer($customer)->create(['file_name' => 'credit-application.pdf']);

        $payload = app(CustomerWebsitePayloadBuilder::class)->build($customer);

        $this->assertSame(['credit-application.pdf'], array_column($payload['attachments'], 'file_name'));
    }

    public function test_a_sales_orders_payload_carries_its_attachments(): void
    {
        $salesOrder = SalesOrder::factory()->create();

        DocumentAttachment::factory()->forSalesOrder($salesOrder)->create(['file_name' => 'delivery-note.pdf']);

        $payload = app(SalesOrderWebsitePayloadBuilder::class)->build($salesOrder);

        $this->assertSame(['delivery-note.pdf'], array_column($payload['attachments'], 'file_name'));
    }
}
