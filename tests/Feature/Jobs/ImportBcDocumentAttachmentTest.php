<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\Import\DocumentAttachmentImporter;
use App\DocumentAttachments\AttachmentParentReconciler;
use App\DocumentAttachments\AttachmentParentType;
use App\Jobs\DeliverCustomerToWebsite;
use App\Jobs\DeliverProductToWebsite;
use App\Jobs\DeliverSalesOrderToWebsite;
use App\Jobs\ImportBcDocumentAttachment;
use App\Models\Customer;
use App\Models\DocumentAttachment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SyncRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing one attachment, and what it opens.
 *
 * An attachment is not delivered on its own: it is a field of the record it
 * hangs off. What matters here is that a changed file reopens that record's
 * delivery — and that an unchanged one does not, because a delivery carrying an
 * identical payload is pure cost.
 */
class ImportBcDocumentAttachmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverProductToWebsite::class, DeliverCustomerToWebsite::class, DeliverSalesOrderToWebsite::class]);

        $this->product = Product::factory()->create(['bc_id' => 'aaaaaaaa-0000-0000-0000-000000000001']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => 'ffffffff-0000-0000-0000-000000000001',
            'fileName' => 'safety-data-sheet.pdf',
            'parentId' => 'aaaaaaaa-0000-0000-0000-000000000001',
            'parentType' => 'Item',
            'lastModifiedDateTime' => '2026-04-19T22:00:32.733Z',
        ], $overrides);
    }

    public function test_it_stores_the_attachment_and_queues_its_products_delivery(): void
    {
        (new ImportBcDocumentAttachment($this->row()))->handle(
            app(DocumentAttachmentImporter::class),
            app(AttachmentParentReconciler::class),
        );

        $this->assertSame(1, DocumentAttachment::query()->count());

        Queue::assertPushed(
            DeliverProductToWebsite::class,
            fn (DeliverProductToWebsite $job): bool => $job->bcId === $this->product->bc_id,
        );
    }

    public function test_a_customer_attachment_queues_the_customers_delivery(): void
    {
        $customer = Customer::factory()->create(['bc_id' => 'bbbbbbbb-0000-0000-0000-000000000001']);

        $this->dispatchRow($this->row(['parentId' => $customer->bc_id, 'parentType' => 'Customer']));

        Queue::assertPushed(
            DeliverCustomerToWebsite::class,
            fn (DeliverCustomerToWebsite $job): bool => $job->bcId === $customer->bc_id,
        );
        Queue::assertNotPushed(DeliverProductToWebsite::class);
    }

    public function test_a_sales_order_attachment_queues_the_orders_delivery(): void
    {
        $salesOrder = SalesOrder::factory()->create(['bc_id' => 'cccccccc-0000-0000-0000-000000000001']);

        $this->dispatchRow($this->row(['parentId' => $salesOrder->bc_id, 'parentType' => 'Sales Order']));

        Queue::assertPushed(
            DeliverSalesOrderToWebsite::class,
            fn (DeliverSalesOrderToWebsite $job): bool => $job->bcId === $salesOrder->bc_id,
        );
    }

    /**
     * The second import changes nothing, so the website is already being asked
     * for exactly this and no delivery is owed.
     */
    public function test_an_unchanged_attachment_queues_no_delivery(): void
    {
        $this->dispatchRow($this->row());

        Queue::assertPushed(DeliverProductToWebsite::class, 1);

        $this->dispatchRow($this->row());

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    public function test_a_row_whose_parent_is_missing_stores_nothing_and_queues_nothing(): void
    {
        $this->dispatchRow($this->row(['parentId' => 'dddddddd-0000-0000-0000-000000000009']));

        $this->assertSame(0, DocumentAttachment::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_parent_type_it_cannot_place_stores_nothing_and_queues_nothing(): void
    {
        $this->dispatchRow($this->row(['parentType' => 'Purchase Invoice']));

        $this->assertSame(0, DocumentAttachment::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * Replacing is the only path that can remove a file, so it is the only one
     * that can notice a deletion and reopen the parent's delivery for it.
     */
    public function test_replacing_all_removes_a_vanished_file_and_queues_a_delivery(): void
    {
        $this->dispatchRow($this->row());

        $this->dispatchRow([
            'parentType' => AttachmentParentType::Product->value,
            'parentId' => $this->product->bc_id,
            'rows' => [],
        ], replaceAll: true);

        $this->assertSame(0, DocumentAttachment::query()->count());
        Queue::assertPushed(DeliverProductToWebsite::class, 2);
    }

    /**
     * A forced run re-delivers whatever the record says: unchanged is exactly
     * what a drifted website is most likely to be missing.
     */
    public function test_forcing_queues_a_delivery_even_when_nothing_changed(): void
    {
        $this->dispatchRow($this->row());
        $this->dispatchRow($this->row(), force: true);

        Queue::assertPushed(DeliverProductToWebsite::class, 2);
    }

    public function test_it_opens_a_ledger_row_for_the_parent(): void
    {
        $this->dispatchRow($this->row());

        $this->assertDatabaseHas('sync_records', [
            'entity' => 'product',
            'bc_id' => $this->product->bc_id,
        ]);

        $record = SyncRecord::query()->where('bc_id', $this->product->bc_id)->sole();

        $this->assertContains('attachments', $record->changed_fields);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function dispatchRow(array $row, bool $force = false, bool $replaceAll = false): void
    {
        (new ImportBcDocumentAttachment($row, $force, $replaceAll))->handle(
            app(DocumentAttachmentImporter::class),
            app(AttachmentParentReconciler::class),
        );
    }
}
