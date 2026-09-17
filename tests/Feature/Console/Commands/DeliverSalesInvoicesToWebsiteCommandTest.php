<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\DeliverSalesInvoiceToWebsite;
use App\Models\SalesInvoice;
use App\Models\SyncRecord;
use App\Sync\SalesInvoiceSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Opening ledger work for sales invoices that have none.
 */
class DeliverSalesInvoicesToWebsiteCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverSalesInvoiceToWebsite::class]);
    }

    private function ledger(): SalesInvoiceSyncLedger
    {
        return app(SalesInvoiceSyncLedger::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function salesInvoice(array $attributes = []): SalesInvoice
    {
        return SalesInvoice::factory()->create($attributes);
    }

    // ------------------------------------------------------------ dry run

    public function test_it_reports_without_sending_by_default(): void
    {
        $this->salesInvoice(['number' => 'INV000001']);

        $this->artisan('website:deliver-sales-invoices')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('INV000001')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRecord::query()->where('entity', 'sales_invoice')->count());
    }

    // ------------------------------------------------------------- send

    public function test_send_opens_a_pending_full_delivery_for_an_invoice_with_no_ledger_row(): void
    {
        $salesInvoice = $this->salesInvoice(['number' => 'INV000001']);

        $this->assertNull($this->ledger()->find($salesInvoice));

        $this->artisan('website:deliver-sales-invoices', ['--send' => true])
            ->expectsOutputToContain('Queued 1 invoice(s)')
            ->assertExitCode(0);

        $record = $this->ledger()->find($salesInvoice);

        $this->assertNotNull($record);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame('sales_invoice', $record->entity);

        Queue::assertPushed(
            DeliverSalesInvoiceToWebsite::class,
            fn (DeliverSalesInvoiceToWebsite $job): bool => $job->bcId === $salesInvoice->bc_id,
        );
    }

    public function test_send_queues_every_invoice_that_needs_one(): void
    {
        foreach (range(1, 3) as $n) {
            $this->salesInvoice(['number' => sprintf('INV%06d', $n)]);
        }

        $this->artisan('website:deliver-sales-invoices', ['--send' => true])->assertExitCode(0);

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 3);
    }

    // --------------------------------------------------------- selection

    public function test_number_limits_the_run_to_those_invoices(): void
    {
        $wanted = $this->salesInvoice(['number' => 'INV000001']);
        $this->salesInvoice(['number' => 'INV000002']);

        $this->artisan('website:deliver-sales-invoices', ['--send' => true, '--number' => ['INV000001']])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 1);
        $this->assertNotNull($this->ledger()->find($wanted));
    }

    public function test_number_selects_a_credit_memo_too(): void
    {
        $memo = SalesInvoice::factory()->creditMemo()->create(['number' => 'CM104006']);
        $this->salesInvoice(['number' => 'INV000001']);

        $this->artisan('website:deliver-sales-invoices', ['--send' => true, '--number' => ['CM104006']])
            ->expectsOutputToContain('Credit memo')
            ->assertExitCode(0);

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 1);
        $this->assertNotNull($this->ledger()->find($memo));
    }

    public function test_limit_caps_the_run(): void
    {
        foreach (range(1, 4) as $n) {
            $this->salesInvoice(['number' => sprintf('INV%06d', $n)]);
        }

        $this->artisan('website:deliver-sales-invoices', ['--send' => true, '--limit' => 2])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 2);
    }

    public function test_an_unknown_number_matches_nothing(): void
    {
        $this->salesInvoice(['number' => 'INV000001']);

        $this->artisan('website:deliver-sales-invoices', ['--send' => true, '--number' => ['NOPE']])
            ->expectsOutputToContain('No sales invoices match those numbers')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_a_non_positive_limit_is_refused(): void
    {
        $this->artisan('website:deliver-sales-invoices', ['--limit' => 0])
            ->expectsOutputToContain('--limit must be a positive integer')
            ->assertExitCode(1);
    }

    // ----------------------------------------------------- already synced

    public function test_an_invoice_already_delivered_is_left_alone(): void
    {
        $salesInvoice = $this->salesInvoice();
        $record = $this->ledger()->markPending($salesInvoice, []);
        $this->ledger()->markSynced($record);

        $this->artisan('website:deliver-sales-invoices', ['--send' => true])
            ->expectsOutputToContain('1 already up to date')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
