<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\DocumentAttachmentImporter;
use App\DocumentAttachments\AttachmentParentType;
use App\Models\Customer;
use App\Models\DocumentAttachment;
use App\Models\Product;
use App\Models\SalesOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Storing a Business Central document attachment against its parent record.
 *
 * Two behaviours carry the weight here. Which local record a row is placed
 * against, because an attachment carries a parent id that is only unique within
 * its type — placing it by id alone would attach one record's documents to
 * another. And whether a change is reported, because that is what decides
 * whether the parent's website delivery is reopened.
 */
class DocumentAttachmentImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function importer(): DocumentAttachmentImporter
    {
        return app(DocumentAttachmentImporter::class);
    }

    public function test_it_stores_an_attachment_against_its_product(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->skipped);
        $this->assertTrue($result->changed);
        $this->assertTrue($this->product->is($result->parent));
        $this->assertSame(AttachmentParentType::Product, $result->parentType);

        $attachment = DocumentAttachment::sole();

        $this->assertSame('safety-data-sheet.pdf', $attachment->file_name);
        $this->assertSame(AttachmentParentType::Product->value, $attachment->parent_type);
        $this->assertSame($this->product->bc_id, $attachment->parent_bc_id);
    }

    public function test_it_places_a_customer_attachment_against_the_customer(): void
    {
        $customer = Customer::factory()->create(['bc_id' => 'bbbbbbbb-0000-0000-0000-000000000001']);

        $result = $this->importer()->import($this->row([
            'parentId' => $customer->bc_id,
            'parentType' => 'Customer',
        ]));

        $this->assertTrue($customer->is($result->parent));
        $this->assertSame(AttachmentParentType::Customer, $result->parentType);
    }

    public function test_it_places_a_sales_order_attachment_against_the_order(): void
    {
        $salesOrder = SalesOrder::factory()->create(['bc_id' => 'cccccccc-0000-0000-0000-000000000001']);

        $result = $this->importer()->import($this->row([
            'parentId' => $salesOrder->bc_id,
            'parentType' => 'Sales Order',
        ]));

        $this->assertTrue($salesOrder->is($result->parent));
        $this->assertSame(AttachmentParentType::SalesOrder, $result->parentType);
    }

    /**
     * An id is only unique within a parent type, so the type is what decides
     * where a row lands. Without it, this attachment would be placed on the
     * product that happens to share the customer's Business Central id.
     */
    public function test_it_does_not_place_a_customer_attachment_on_a_product_sharing_the_id(): void
    {
        $result = $this->importer()->import($this->row([
            'parentId' => $this->product->bc_id,
            'parentType' => 'Customer',
        ]));

        $this->assertTrue($result->skipped);
        $this->assertSame(0, DocumentAttachment::query()->count());
    }

    public function test_it_skips_a_parent_type_it_cannot_place(): void
    {
        $result = $this->importer()->import($this->row(['parentType' => 'Purchase Invoice']));

        $this->assertTrue($result->skipped);
        $this->assertNull($result->parentType);
        $this->assertSame(0, DocumentAttachment::query()->count());
    }

    /**
     * Nothing is stored for a parent that has not been imported. A stored
     * orphan is a row nothing would reconcile; the next sweep picks it up once
     * the parent exists.
     */
    public function test_it_skips_a_row_whose_parent_has_not_been_imported(): void
    {
        $result = $this->importer()->import($this->row(['parentId' => 'dddddddd-0000-0000-0000-000000000009']));

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('no local product', (string) $result->skipReason);
        $this->assertSame(0, DocumentAttachment::query()->count());
    }

    public function test_it_reports_no_change_when_the_same_row_is_imported_twice(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->changed);
        $this->assertSame(1, DocumentAttachment::query()->count());
    }

    /**
     * Business Central touches lastModifiedDateTime when the parent record is
     * saved, not only when a file is added. Treating that as a change would
     * open a delivery carrying an identical list.
     */
    public function test_a_moved_timestamp_alone_is_not_a_change(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row(['lastModifiedDateTime' => '2026-08-01T10:00:00Z']));

        $this->assertFalse($result->changed);
    }

    public function test_a_renamed_file_is_a_change(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row(['fileName' => 'safety-data-sheet-v2.pdf']));

        $this->assertTrue($result->changed);
        $this->assertSame('safety-data-sheet-v2.pdf', DocumentAttachment::sole()->file_name);
    }

    public function test_it_rejects_a_row_with_no_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer()->import($this->row(['id' => '']));
    }

    public function test_it_rejects_a_row_with_no_parent_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer()->import($this->row(['parentId' => '']));
    }

    /**
     * The whole point of replacing: a file deleted in Business Central simply
     * stops being returned, so only a sweep that sets the parent's set to
     * exactly what it saw can ever remove one.
     */
    public function test_replacing_removes_an_attachment_that_is_no_longer_returned(): void
    {
        $this->importer()->import($this->row(['id' => 'ffffffff-0000-0000-0000-000000000001']));
        $this->importer()->import($this->row(['id' => 'ffffffff-0000-0000-0000-000000000002', 'fileName' => 'manual.pdf']));

        $this->assertSame(2, DocumentAttachment::query()->count());

        $result = $this->importer()->replace(
            AttachmentParentType::Product,
            $this->product->bc_id,
            [$this->row(['id' => 'ffffffff-0000-0000-0000-000000000001'])],
        );

        $this->assertTrue($result->changed);
        $this->assertSame(['ffffffff-0000-0000-0000-000000000001'], DocumentAttachment::query()->pluck('bc_id')->all());
    }

    public function test_replacing_with_nothing_clears_the_parents_attachments(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->replace(AttachmentParentType::Product, $this->product->bc_id, []);

        $this->assertTrue($result->changed);
        $this->assertSame(0, DocumentAttachment::query()->count());
    }

    public function test_replacing_with_the_same_set_reports_no_change(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->replace(AttachmentParentType::Product, $this->product->bc_id, [$this->row()]);

        $this->assertFalse($result->changed);
    }

    /**
     * A replace must only ever touch the parent it names. Another record's
     * files are not part of the set being replaced.
     */
    public function test_replacing_leaves_another_parents_attachments_alone(): void
    {
        $other = Product::factory()->create(['bc_id' => 'aaaaaaaa-0000-0000-0000-000000000002']);

        $this->importer()->import($this->row());
        $this->importer()->import($this->row([
            'id' => 'ffffffff-0000-0000-0000-000000000002',
            'parentId' => $other->bc_id,
        ]));

        $this->importer()->replace(AttachmentParentType::Product, $this->product->bc_id, []);

        $this->assertSame(
            ['ffffffff-0000-0000-0000-000000000002'],
            DocumentAttachment::query()->pluck('bc_id')->all(),
        );
    }

    public function test_replacing_rejects_an_empty_parent_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer()->replace(AttachmentParentType::Product, '  ', []);
    }
}
