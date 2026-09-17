<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\Import\CustomerImporter;
use App\Jobs\DeliverCustomerToWebsite;
use App\Jobs\ImportBcCustomer;
use App\Models\Customer;
use App\Sync\CustomerSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing one customer row and opening the delivery it calls for.
 */
class ImportBcCustomerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverCustomerToWebsite::class]);
    }

    /**
     * @param  array<int, string>  $customers
     * @return array<string, mixed>
     */
    private function row(string $city = 'Te Awamutu', string $blocked = '_x0020_'): array
    {
        return [
            'id' => '71431cfe-a51d-f111-8340-7ced8d32d199',
            'number' => '3STONEVE',
            'displayName' => '3 Stone Veterinary Services',
            'type' => 'Company',
            'city' => $city,
            'blocked' => $blocked,
            'customerPriceGroup' => 'LIST PRICE',
            'customerDiscGroup' => 'LIST',
            'lastModifiedDateTime' => '2026-06-15T00:23:21.653Z',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row, bool $force = false): void
    {
        (new ImportBcCustomer($row, $force))->handle(
            app(CustomerImporter::class),
            app(CustomerSyncLedger::class),
        );
    }

    public function test_a_new_customer_opens_a_delivery(): void
    {
        $this->import($this->row());

        $customer = Customer::first();

        $this->assertNotNull(app(CustomerSyncLedger::class)->find($customer));
        Queue::assertPushed(DeliverCustomerToWebsite::class, 1);
    }

    /**
     * The guard that stops an unchanged re-import queueing a delivery on every
     * pass: the ledger already wants exactly this, so there is no new work.
     */
    public function test_an_unchanged_reimport_dispatches_nothing(): void
    {
        $this->import($this->row());
        Queue::assertPushed(DeliverCustomerToWebsite::class, 1);

        $this->import($this->row());

        // Still 1: the second import opened no new work.
        Queue::assertPushed(DeliverCustomerToWebsite::class, 1);
    }

    public function test_a_changed_customer_opens_another_delivery(): void
    {
        $this->import($this->row());

        $this->import($this->row(city: 'Hamilton'));

        Queue::assertPushed(DeliverCustomerToWebsite::class, 2);
    }

    // ------------------------------------------------------------------ force

    /**
     * The whole point of --force: a row that has not moved is delivered anyway,
     * so a website that has drifted can be put back in step.
     */
    public function test_force_dispatches_an_unchanged_customer(): void
    {
        $this->import($this->row());
        Queue::assertPushed(DeliverCustomerToWebsite::class, 1);

        // Normally this second import would open nothing.
        $this->import($this->row(), force: true);

        Queue::assertPushed(DeliverCustomerToWebsite::class, 2);
    }

    public function test_force_still_reports_an_upsert(): void
    {
        $this->import($this->row(), force: true);

        $this->assertSame(
            WebsiteAction::Upsert,
            app(CustomerSyncLedger::class)->find(Customer::first())->action,
        );
    }

    /**
     * A block is a changed field, never a different instruction: customers are
     * always upserted and the website decides what a block restricts.
     */
    public function test_a_block_opens_an_upsert_carrying_the_flag(): void
    {
        $this->import($this->row());
        $this->import($this->row(blocked: 'All'));

        $record = app(CustomerSyncLedger::class)->find(Customer::first());

        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame(['blocked'], $record->changed_fields);
        $this->assertSame('All', $record->payload['blocked']);
    }
}
