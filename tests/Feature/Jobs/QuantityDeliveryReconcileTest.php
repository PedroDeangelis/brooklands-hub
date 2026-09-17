<?php

namespace Tests\Feature\Jobs;

use App\Jobs\DeliverProductToWebsite;
use App\Jobs\ImportBcProductQuantity;
use App\Models\Product;
use App\Models\ProductQuantity;
use App\Sync\Payload\DeliveryType;
use App\Sync\SyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A stock change is a website change.
 *
 * Quantities feed website_quantity and stock_status, so a figure moving can
 * change what the website should hold. These tests pin that the import turns a
 * stock movement into delivery work, and that an unchanged figure does not.
 */
class QuantityDeliveryReconcileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BC_ID = '798c5fa0-3d1c-f111-8341-6045bde65a16';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverProductToWebsite::class]);
    }

    private function ledger(): SyncLedger
    {
        return app(SyncLedger::class);
    }

    private function product(): Product
    {
        return Product::factory()->create([
            'bc_id' => self::BC_ID,
            'sku' => 'AA27',
            'type' => 'Inventory',
            'price' => 10,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_payload' => [
                'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 50]],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => self::BC_ID,
            'number' => 'AA27',
            'type' => 'Inventory',
            'inventory' => 0,
            'qtyOnSalesOrder' => 0,
            'qtyOnPurchOrder' => 0,
            'qtyOnTransferOrder' => 0,
            'nextPurchaseReceiptDate' => '0001-01-01',
            'nextTransferReceiptDate' => '0001-01-01',
            'lastModifiedDateTime' => '0001-01-01T00:00:00Z',
        ], $overrides);
    }

    private function import(array $overrides = []): void
    {
        ImportBcProductQuantity::dispatchSync($this->row($overrides));
    }

    public function test_a_stock_arrival_queues_a_delivery(): void
    {
        $product = $this->product();
        $this->import(['inventory' => 0]);
        $this->ledger()->markSynced($this->ledger()->find($product->fresh()));

        Queue::fake([DeliverProductToWebsite::class]);

        // Stock arrives.
        $this->import(['inventory' => 3]);

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    /**
     * The website decisions a stock change produces, which is what WordPress
     * eventually receives as a partial.
     */
    public function test_a_stock_change_produces_the_expected_website_changes(): void
    {
        $product = $this->product();
        $this->import(['inventory' => 0]);
        $this->ledger()->markSynced($this->ledger()->find($product->fresh()));

        $this->import(['inventory' => 3]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(DeliveryType::Partial, $plan->type);
        $this->assertSame(
            ['stock_status', 'website_quantity'],
            array_keys($plan->diff->changes),
        );
        $this->assertSame('instock', $plan->diff->changes['stock_status']);
        $this->assertSame(3.0, $plan->diff->changes['website_quantity']);
    }

    public function test_stock_running_out_takes_the_product_out_of_stock(): void
    {
        $product = $this->product();
        $this->import(['inventory' => 5]);
        $this->ledger()->markSynced($this->ledger()->find($product->fresh()));

        $this->import(['inventory' => 0]);

        $changes = $this->ledger()->plan($product->fresh())->diff->changes;

        $this->assertSame('outofstock', $changes['stock_status']);
        $this->assertSame(0.0, $changes['website_quantity']);
    }

    /**
     * An unchanged figure must not manufacture work: this is what makes a
     * frequent full sweep affordable.
     */
    public function test_an_unchanged_figure_queues_no_delivery(): void
    {
        $product = $this->product();
        $this->import(['inventory' => 5]);
        $this->ledger()->markSynced($this->ledger()->find($product->fresh()));

        Queue::fake([DeliverProductToWebsite::class]);

        $this->import(['inventory' => 5]);

        Queue::assertNothingPushed();
        $this->assertSame(DeliveryType::None, $this->ledger()->plan($product->fresh())->type);
    }

    public function test_repeated_sweeps_of_unchanged_stock_stay_quiet(): void
    {
        $product = $this->product();
        $this->import(['inventory' => 5]);
        $this->ledger()->markSynced($this->ledger()->find($product->fresh()));

        Queue::fake([DeliverProductToWebsite::class]);

        foreach (range(1, 3) as $ignored) {
            $this->import(['inventory' => 5]);
        }

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------- missing product

    public function test_a_row_without_a_product_queues_no_delivery(): void
    {
        // No product created at all.
        $this->import(['inventory' => 5]);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('product_quantities', 0);
    }

    public function test_a_row_without_a_product_opens_no_ledger_row(): void
    {
        $this->import(['inventory' => 5]);

        $this->assertDatabaseCount('sync_records', 0);
    }

    /**
     * The next sweep picks the row up once the item import has caught up,
     * which is why skipping is safe rather than lossy.
     */
    public function test_the_row_lands_once_its_product_arrives(): void
    {
        $this->import(['inventory' => 5]);
        $this->assertDatabaseCount('product_quantities', 0);

        $product = $this->product();
        $this->import(['inventory' => 5]);

        $this->assertDatabaseCount('product_quantities', 1);
        $this->assertSame(5.0, (float) ProductQuantity::query()->sole()->inventory);
        $this->assertNotNull($this->ledger()->find($product->fresh()));
    }
}
