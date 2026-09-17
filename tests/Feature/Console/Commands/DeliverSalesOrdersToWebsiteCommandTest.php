<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\DeliverSalesOrderToWebsite;
use App\Models\SalesOrder;
use App\Models\SyncRecord;
use App\Sync\SalesOrderSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Opening ledger work for sales orders that have none.
 */
class DeliverSalesOrdersToWebsiteCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverSalesOrderToWebsite::class]);
    }

    private function ledger(): SalesOrderSyncLedger
    {
        return app(SalesOrderSyncLedger::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function salesOrder(array $attributes = []): SalesOrder
    {
        return SalesOrder::factory()->create($attributes);
    }

    // ------------------------------------------------------------ dry run

    public function test_it_reports_without_sending_by_default(): void
    {
        $this->salesOrder(['number' => 'SO000001']);

        $this->artisan('website:deliver-sales-orders')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('SO000001')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRecord::query()->where('entity', 'sales_order')->count());
    }

    // ------------------------------------------------------------- send

    public function test_send_opens_a_pending_full_delivery_for_an_order_with_no_ledger_row(): void
    {
        $salesOrder = $this->salesOrder(['number' => 'SO000001']);

        $this->assertNull($this->ledger()->find($salesOrder));

        $this->artisan('website:deliver-sales-orders', ['--send' => true])
            ->expectsOutputToContain('Queued 1 order(s)')
            ->assertExitCode(0);

        $record = $this->ledger()->find($salesOrder);

        $this->assertNotNull($record);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame('sales_order', $record->entity);

        Queue::assertPushed(
            DeliverSalesOrderToWebsite::class,
            fn (DeliverSalesOrderToWebsite $job): bool => $job->bcId === $salesOrder->bc_id,
        );
    }

    public function test_send_queues_every_order_that_needs_one(): void
    {
        foreach (range(1, 3) as $n) {
            $this->salesOrder(['number' => sprintf('SO%06d', $n)]);
        }

        $this->artisan('website:deliver-sales-orders', ['--send' => true])->assertExitCode(0);

        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 3);
    }

    // --------------------------------------------------------- selection

    public function test_number_limits_the_run_to_those_orders(): void
    {
        $wanted = $this->salesOrder(['number' => 'SO000001']);
        $this->salesOrder(['number' => 'SO000002']);

        $this->artisan('website:deliver-sales-orders', ['--send' => true, '--number' => ['SO000001']])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 1);
        $this->assertNotNull($this->ledger()->find($wanted));
    }

    public function test_limit_caps_the_run(): void
    {
        foreach (range(1, 4) as $n) {
            $this->salesOrder(['number' => sprintf('SO%06d', $n)]);
        }

        $this->artisan('website:deliver-sales-orders', ['--send' => true, '--limit' => 2])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 2);
    }

    public function test_an_unknown_number_matches_nothing(): void
    {
        $this->salesOrder(['number' => 'SO000001']);

        $this->artisan('website:deliver-sales-orders', ['--send' => true, '--number' => ['NOPE']])
            ->expectsOutputToContain('No sales orders match those numbers')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_a_non_positive_limit_is_refused(): void
    {
        $this->artisan('website:deliver-sales-orders', ['--limit' => 0])
            ->expectsOutputToContain('--limit must be a positive integer')
            ->assertExitCode(1);
    }

    // ----------------------------------------------------- already synced

    public function test_an_order_already_delivered_is_left_alone(): void
    {
        $salesOrder = $this->salesOrder();
        $record = $this->ledger()->markPending($salesOrder, []);
        $this->ledger()->markSynced($record);

        $this->artisan('website:deliver-sales-orders', ['--send' => true])
            ->expectsOutputToContain('1 already up to date')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
