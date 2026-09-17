<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\Import\ProductMarketingTextImporter;
use App\Jobs\DeliverProductToWebsite;
use App\Jobs\ImportBcProductMarketingText;
use App\Models\Product;
use App\Models\ProductMarketingText;
use App\Sync\SyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class ImportBcProductMarketingTextTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BC_ID = '798c5fa0-3d1c-f111-8341-6045bde65a16';

    /**
     * Marketing copy describes a product, so one has to exist for it to attach to.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->create(['bc_id' => self::BC_ID, 'sku' => 'AA27']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'itemId' => self::BC_ID,
            'itemNo' => 'AA27',
            'marketingText' => 'An air stone oxygenates a tank. It is quiet.',
            'lastModifiedDateTime' => '2026-04-17T03:38:41.39Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importRow(array $row, bool $force = false): void
    {
        (new ImportBcProductMarketingText($row, $force))->handle(
            app(ProductMarketingTextImporter::class),
            app(SyncLedger::class),
        );
    }

    public function test_it_stores_the_marketing_text_row(): void
    {
        $this->importRow($this->row());

        $this->assertDatabaseCount('product_marketing_texts', 1);
        $this->assertSame('AA27', ProductMarketingText::first()->sku);
    }

    public function test_reimporting_the_same_row_updates_it_in_place(): void
    {
        $this->importRow($this->row());
        $this->importRow($this->row(['marketingText' => 'Rewritten copy.']));

        $this->assertDatabaseCount('product_marketing_texts', 1);
        $this->assertSame('Rewritten copy.', ProductMarketingText::first()->marketing_text);
    }

    public function test_it_is_queued_rather_than_run_inline(): void
    {
        Queue::fake();

        ImportBcProductMarketingText::dispatch($this->row());

        Queue::assertPushed(ImportBcProductMarketingText::class);
    }

    public function test_it_is_tagged_for_horizon_by_record(): void
    {
        $tags = (new ImportBcProductMarketingText($this->row()))->tags();

        $this->assertContains('bc-product-marketing-text', $tags);
        $this->assertContains('bc:'.self::BC_ID, $tags);
        $this->assertContains('sku:AA27', $tags);
    }

    public function test_a_row_without_an_item_id_fails_the_job(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importRow($this->row(['itemId' => '']));
    }

    /**
     * Changed copy means the website is holding something out of date, so the
     * edit has to open a delivery. This is the whole point of the flow.
     */
    public function test_changed_copy_dispatches_a_delivery(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        $this->importRow($this->row());
        Queue::assertPushed(DeliverProductToWebsite::class, 1);

        $this->importRow($this->row(['marketingText' => 'Rewritten copy.']));

        Queue::assertPushed(DeliverProductToWebsite::class, 2);
    }

    /**
     * Unchanged copy must open nothing, or every run would re-send the whole
     * catalogue's descriptions.
     */
    public function test_unchanged_copy_dispatches_nothing(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        $this->importRow($this->row());
        Queue::assertPushed(DeliverProductToWebsite::class, 1);

        $this->importRow($this->row());

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    /**
     * Business Central moves this timestamp when the item record is saved, not
     * only when the copy is edited, so it must not open a delivery by itself.
     */
    public function test_a_moved_timestamp_alone_dispatches_nothing(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        $this->importRow($this->row());
        Queue::assertPushed(DeliverProductToWebsite::class, 1);

        $this->importRow($this->row(['lastModifiedDateTime' => '2026-08-01T00:00:00Z']));

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    public function test_force_dispatches_a_delivery_when_the_copy_has_not_changed(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        $this->importRow($this->row());
        Queue::assertPushed(DeliverProductToWebsite::class, 1);

        // Identical copy: normally this opens nothing at all.
        $this->importRow($this->row());
        Queue::assertPushed(DeliverProductToWebsite::class, 1);

        $this->importRow($this->row(), force: true);

        Queue::assertPushed(DeliverProductToWebsite::class, 2);
    }

    /**
     * Copy can arrive before the item it describes, so a row with no product is
     * skipped rather than stored as an orphan.
     */
    public function test_it_stores_nothing_for_a_row_with_no_matching_product(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        $this->importRow($this->row(['itemId' => 'ffffffff-0000-0000-0000-000000000009']));

        $this->assertDatabaseCount('product_marketing_texts', 0);
        Queue::assertNothingPushed();
    }

    /**
     * A row with no matching product still has nothing to deliver, forced or
     * not: the skip above the gate is about identity, not about change.
     */
    public function test_force_does_not_deliver_a_row_with_no_matching_product(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        $this->importRow($this->row(['itemId' => 'ffffffff-0000-0000-0000-000000000009']), force: true);

        Queue::assertNothingPushed();
    }
}
