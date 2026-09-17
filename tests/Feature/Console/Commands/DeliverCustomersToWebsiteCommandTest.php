<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\DeliverCustomerToWebsite;
use App\Models\Customer;
use App\Models\SyncRecord;
use App\Sync\CustomerSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Opening ledger work for customers that have none.
 *
 * This is the path that rescues customers imported before delivery existed:
 * they have rows in the customers table but nothing in the ledger, and nothing
 * in Business Central will change to make them reconcile on their own.
 */
class DeliverCustomersToWebsiteCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverCustomerToWebsite::class]);
    }

    private function ledger(): CustomerSyncLedger
    {
        return app(CustomerSyncLedger::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes);
    }

    // ------------------------------------------------------------ dry run

    /**
     * Delivering a customer writes to the live site, so sending has
     * to be asked for rather than being what happens by default.
     */
    public function test_it_reports_without_sending_by_default(): void
    {
        $this->customer(['number' => 'CUST0001']);

        $this->artisan('website:deliver-customers')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('CUST0001')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRecord::query()->where('entity', 'customer')->count());
    }

    public function test_a_dry_run_opens_no_ledger_rows(): void
    {
        $this->customer();
        $this->customer(['number' => 'CUST0002', 'bc_id' => fake()->uuid()]);

        $this->artisan('website:deliver-customers')->assertExitCode(0);

        $this->assertSame(0, SyncRecord::query()->count());
    }

    // ------------------------------------------------------------- send

    /**
     * The Stage 1 rescue: customers already imported must be able to become
     * pending full deliveries without anyone touching Business Central.
     */
    public function test_send_opens_a_pending_full_delivery_for_a_customer_with_no_ledger_row(): void
    {
        $customer = $this->customer(['number' => 'CUST0001']);

        $this->assertNull($this->ledger()->find($customer));

        $this->artisan('website:deliver-customers', ['--send' => true])
            ->expectsOutputToContain('Queued 1 customer(s)')
            ->assertExitCode(0);

        $record = $this->ledger()->find($customer);

        $this->assertNotNull($record);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame('customer', $record->entity);
        $this->assertSame('website', $record->channel);

        Queue::assertPushed(
            DeliverCustomerToWebsite::class,
            fn (DeliverCustomerToWebsite $job): bool => $job->bcId === $customer->bc_id,
        );
    }

    public function test_send_queues_every_customer_that_needs_one(): void
    {
        foreach (range(1, 3) as $n) {
            $this->customer(['number' => sprintf('CUST%04d', $n), 'bc_id' => fake()->uuid()]);
        }

        $this->artisan('website:deliver-customers', ['--send' => true])->assertExitCode(0);

        Queue::assertPushed(DeliverCustomerToWebsite::class, 3);
        $this->assertSame(3, SyncRecord::query()->where('entity', 'customer')->count());
    }

    // --------------------------------------------------------- selection

    public function test_number_limits_the_run_to_those_customers(): void
    {
        $wanted = $this->customer(['number' => 'CUST0001']);
        $this->customer(['number' => 'CUST0002', 'bc_id' => fake()->uuid()]);

        $this->artisan('website:deliver-customers', ['--send' => true, '--number' => ['CUST0001']])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverCustomerToWebsite::class, 1);
        $this->assertNotNull($this->ledger()->find($wanted));
        $this->assertSame(1, SyncRecord::query()->where('entity', 'customer')->count());
    }

    public function test_limit_caps_the_run(): void
    {
        foreach (range(1, 4) as $n) {
            $this->customer(['number' => sprintf('CUST%04d', $n), 'bc_id' => fake()->uuid()]);
        }

        $this->artisan('website:deliver-customers', ['--send' => true, '--limit' => 2])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverCustomerToWebsite::class, 2);
    }

    public function test_an_unknown_code_matches_nothing(): void
    {
        $this->customer(['number' => 'CUST0001']);

        $this->artisan('website:deliver-customers', ['--send' => true, '--number' => ['NOPE']])
            ->expectsOutputToContain('No customers match those numbers')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_a_non_positive_limit_is_refused(): void
    {
        $this->artisan('website:deliver-customers', ['--limit' => 0])
            ->expectsOutputToContain('--limit must be a positive integer')
            ->assertExitCode(1);
    }

    // ----------------------------------------------------- already synced

    /**
     * A customer the website already holds must not be re-sent: the plan says
     * there is nothing to do, and the command trusts the plan.
     */
    public function test_a_customer_already_delivered_is_left_alone(): void
    {
        $customer = $this->customer();
        $record = $this->ledger()->markPending($customer, []);
        $this->ledger()->markSynced($record);

        $this->artisan('website:deliver-customers', ['--send' => true])
            ->expectsOutputToContain('1 already up to date')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
