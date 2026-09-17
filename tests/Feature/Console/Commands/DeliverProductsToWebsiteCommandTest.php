<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Sync\DeliveryCandidate;
use App\Sync\DeliveryQueue;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Dispatching deliveries for the products that are actually owed one.
 */
class DeliverProductsToWebsiteCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverProductToWebsite::class]);
    }

    private function ledger(): SyncLedger
    {
        return app(SyncLedger::class);
    }

    /**
     * An eligible product with nothing delivered yet: a Full is owed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'type' => 'Inventory',
            'price' => 10,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_payload' => [],
        ], $attributes));
    }

    /**
     * Put a product in the state that follows a successful delivery.
     */
    private function delivered(Product $product): SyncRecord
    {
        $record = $this->ledger()->reconcile($product);
        $this->ledger()->markSynced($record);

        return $record->refresh();
    }

    // ---------------------------------------------------------------- dry run

    public function test_a_dry_run_dispatches_nothing(): void
    {
        $this->product(['sku' => 'AAA1']);
        $this->product(['sku' => 'BBB1']);

        $this->artisan('website:deliver-products', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * The summary groups by delivery type and names examples. Asserted through
     * the queue the command reads rather than the rendered table, which the
     * console formatter may wrap.
     */
    public function test_the_summary_groups_candidates_by_delivery_type(): void
    {
        $this->product(['sku' => 'KEEP1']);
        $this->product(['sku' => 'DROP1', 'gppg' => 'RETIRED']);
        $this->delivered($this->product(['sku' => 'DONE1']));

        $buckets = app(DeliveryQueue::class)->candidates()
            ->groupBy(fn (DeliveryCandidate $c): string => $c->bucket())
            ->map->count();

        $this->assertSame(1, $buckets->get('full'));
        $this->assertSame(1, $buckets->get('remove'));
        $this->assertSame(1, $buckets->get('none'));

        $this->artisan('website:deliver-products', ['--dry-run' => true])
            ->expectsOutputToContain('2 job(s) would be dispatched')
            ->assertExitCode(0);
    }

    /**
     * A dry run must not open ledger rows either: it is a report, not a step.
     */
    public function test_a_dry_run_leaves_the_ledger_untouched(): void
    {
        $this->product(['sku' => 'AAA1']);

        $this->artisan('website:deliver-products', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseCount('sync_records', 0);
    }

    // ----------------------------------------------------------------- filters

    public function test_the_sku_option_narrows_what_is_considered(): void
    {
        $this->product(['sku' => 'WANTED1']);
        $this->product(['sku' => 'OTHER1']);

        $this->artisan('website:deliver-products', ['--sku' => ['WANTED1']])->assertExitCode(0);

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
        Queue::assertPushed(
            DeliverProductToWebsite::class,
            fn (DeliverProductToWebsite $job): bool => $job->bcId === Product::query()
                ->where('sku', 'WANTED1')->sole()->bc_id,
        );
    }

    public function test_several_skus_can_be_given(): void
    {
        $this->product(['sku' => 'A1']);
        $this->product(['sku' => 'B1']);
        $this->product(['sku' => 'C1']);

        $this->artisan('website:deliver-products', ['--sku' => ['A1', 'B1']])->assertExitCode(0);

        Queue::assertPushed(DeliverProductToWebsite::class, 2);
    }

    public function test_an_unknown_sku_dispatches_nothing(): void
    {
        $this->product(['sku' => 'A1']);

        $this->artisan('website:deliver-products', ['--sku' => ['NOPE']])
            ->expectsOutputToContain('No products match')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_the_limit_option_caps_the_number_dispatched(): void
    {
        foreach (range(1, 5) as $n) {
            $this->product(['sku' => "SKU{$n}"]);
        }

        $this->artisan('website:deliver-products', ['--limit' => 2])
            ->expectsOutputToContain('Dispatched 2')
            ->assertExitCode(0);

        Queue::assertPushed(DeliverProductToWebsite::class, 2);
    }

    public function test_the_limit_reports_how_many_remain(): void
    {
        foreach (range(1, 5) as $n) {
            $this->product(['sku' => "SKU{$n}"]);
        }

        $this->artisan('website:deliver-products', ['--limit' => 2])
            ->expectsOutputToContain('3 more still waiting')
            ->assertExitCode(0);
    }

    public function test_a_non_positive_limit_is_refused(): void
    {
        $this->product(['sku' => 'A1']);

        $this->artisan('website:deliver-products', ['--limit' => 0])
            ->expectsOutputToContain('--limit must be a positive integer')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------- dispatching

    public function test_a_full_delivery_is_dispatched(): void
    {
        $this->product(['sku' => 'NEW1']);

        $this->artisan('website:deliver-products')->assertExitCode(0);

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    public function test_a_partial_delivery_is_dispatched(): void
    {
        $product = $this->product(['sku' => 'CHANGED1']);
        $this->delivered($product);

        $product->update(['price' => 22]);

        $this->artisan('website:deliver-products')
            ->expectsOutputToContain('partial')
            ->assertExitCode(0);

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    public function test_a_removal_is_dispatched(): void
    {
        $product = $this->product(['sku' => 'GONE1']);
        $this->delivered($product);

        $product->update(['gppg' => 'RETIRED']);

        $this->artisan('website:deliver-products')
            ->expectsOutputToContain('remove')
            ->assertExitCode(0);

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    // ------------------------------------------------------------------ skips

    public function test_a_synced_product_is_not_dispatched(): void
    {
        $this->delivered($this->product(['sku' => 'DONE1']));

        $this->artisan('website:deliver-products')
            ->expectsOutputToContain('Nothing to deliver')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * A conflict needs data corrected; re-sending would collide identically.
     */
    public function test_a_conflicted_product_is_not_dispatched(): void
    {
        $product = $this->product(['sku' => 'CLASH1']);
        $record = $this->ledger()->reconcile($product);
        $this->ledger()->markConflicted($record, [['code' => 'sku_conflict', 'field' => 'sku']], 'clash');

        $this->artisan('website:deliver-products')
            ->expectsOutputToContain('Conflicts (skipped)')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * Automatic retry is a separate decision, so failures are left alone.
     */
    public function test_a_failed_product_is_not_dispatched(): void
    {
        $product = $this->product(['sku' => 'BROKE1']);
        $record = $this->ledger()->reconcile($product);
        $this->ledger()->markFailed($record, 'boom');

        $this->artisan('website:deliver-products')
            ->expectsOutputToContain('Failed (skipped)')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * A job already holds the record, so dispatching again would duplicate it.
     */
    public function test_a_syncing_product_is_not_dispatched(): void
    {
        $product = $this->product(['sku' => 'INFLIGHT1']);
        $record = $this->ledger()->reconcile($product);
        $this->ledger()->markSyncing($record);

        $this->artisan('website:deliver-products')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * The plan is rebuilt from current state, so a stale pending row whose
     * product now matches the website is not dispatched on its status alone.
     */
    public function test_a_stale_pending_record_matching_the_website_is_not_dispatched(): void
    {
        $product = $this->product(['sku' => 'STALE1']);
        $record = $this->delivered($product);

        // The status says pending, but nothing about the product has changed.
        $record->update(['status' => SyncStatus::Pending]);

        $this->artisan('website:deliver-products')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------- duplicates

    /**
     * Running twice must not repeat work the first run already opened.
     */
    public function test_running_twice_does_not_duplicate_delivered_work(): void
    {
        $product = $this->product(['sku' => 'ONCE1']);

        $this->artisan('website:deliver-products')->assertExitCode(0);
        Queue::assertPushed(DeliverProductToWebsite::class, 1);

        // The first run's job has since delivered successfully.
        $this->ledger()->markSynced($this->ledger()->find($product->fresh()));

        $this->artisan('website:deliver-products')
            ->expectsOutputToContain('Nothing to deliver')
            ->assertExitCode(0);

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    public function test_one_ledger_row_is_kept_per_product_across_runs(): void
    {
        $this->product(['sku' => 'ONE1']);

        $this->artisan('website:deliver-products')->assertExitCode(0);
        $this->artisan('website:deliver-products')->assertExitCode(0);

        $this->assertDatabaseCount('sync_records', 1);
    }

    public function test_the_ledger_row_is_opened_before_dispatching(): void
    {
        $product = $this->product(['sku' => 'OPEN1']);

        $this->artisan('website:deliver-products')->assertExitCode(0);

        $record = $this->ledger()->find($product->fresh());

        $this->assertNotNull($record);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
    }

    // ----------------------------------------------------------------- empty

    public function test_an_empty_catalogue_is_reported_rather_than_failing(): void
    {
        $this->artisan('website:deliver-products')
            ->expectsOutputToContain('No products have been imported yet')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * Nothing runs on a schedule yet: enabling bulk delivery is a deliberate
     * decision, not something that should arrive by being forgotten about.
     */
    public function test_the_command_is_not_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->doesntExpectOutputToContain('website:deliver-products');
    }
}
