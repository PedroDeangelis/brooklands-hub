<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcShipToAddress;
use App\Models\Customer;
use App\Models\SyncCheckpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The ship-to sweep. Every run reads the whole endpoint; nothing here is
 * incremental, so the assertions are about coverage and the sales API group.
 */
class ImportBcShipToAddressesCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/sales/v1.0/companies(company-guid)/shipToAddresses';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com', 'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc', 'client_secret' => 'secret-abc', 'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid', 'api_version' => 'v2.0', 'http_timeout' => 30, 'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Queue::fake([ImportBcShipToAddress::class]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count): array
    {
        return array_map(fn (int $n): array => [
            'id' => sprintf('%08d-3b3c-f111-bec4-00224810e61c', $n),
            'customerNo' => 'BROOKLAN',
            'code' => sprintf('SHIP%03d', $n),
            'address' => "{$n} Test Street",
            'county' => 'Taranaki',
            'isRural' => false,
        ], range(1, $count));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fake(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            // No $top means every row, as the API behaves: the --customer query
            // asks for a filtered set without paging.
            self::URL.'*' => fn (Request $request) => Http::response([
                'value' => array_slice($rows, (int) ($request['$skip'] ?? 0), isset($request['$top']) ? (int) $request['$top'] : null),
            ]),
        ]);
    }

    public function test_it_reads_the_sales_api_group(): void
    {
        $this->fake($this->rows(1));

        $this->artisan('bc:import-ship-to-addresses', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), self::URL) || str_starts_with($r->url(), self::TOKEN_URL));
        Queue::assertPushed(ImportBcShipToAddress::class, 1);
    }

    /**
     * A complete sweep hands each customer its whole list as one replace job,
     * because only a whole-list write can drop an address deleted in Business
     * Central. All 450 fixture rows belong to one customer, so: one job.
     */
    public function test_a_complete_sweep_replaces_each_customers_list_whole(): void
    {
        $this->fake($this->rows(450));

        $this->artisan('bc:import-ship-to-addresses', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcShipToAddress::class, 1);
        Queue::assertPushed(
            ImportBcShipToAddress::class,
            fn (ImportBcShipToAddress $job): bool => $job->replaceAll === true
                && $job->row['customerNo'] === 'BROOKLAN'
                && count($job->row['rows']) === 450,
        );
    }

    public function test_a_complete_sweep_groups_by_customer(): void
    {
        $rows = $this->rows(4);
        $rows[2]['customerNo'] = 'OTHER';
        $rows[3]['customerNo'] = 'OTHER';
        $this->fake($rows);

        $this->artisan('bc:import-ship-to-addresses', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcShipToAddress::class, 2);
    }

    /**
     * A capped run saw only part of the endpoint. Replacing a list from it
     * would wipe the addresses it did not see, so it stays add-only per row.
     */
    public function test_a_capped_run_stays_add_only_per_row(): void
    {
        $this->fake($this->rows(5));

        $this->artisan('bc:import-ship-to-addresses', ['--page-size' => 200, '--top' => 3])->assertExitCode(0);

        Queue::assertPushed(ImportBcShipToAddress::class, 3);
        Queue::assertPushed(
            ImportBcShipToAddress::class,
            fn (ImportBcShipToAddress $job): bool => $job->replaceAll === false,
        );
    }

    /**
     * A customer whose every address was deleted no longer appears in the
     * sweep. It must still be cleared, or the addresses live on forever.
     */
    public function test_a_customer_missing_from_the_sweep_is_cleared(): void
    {
        Customer::factory()->create([
            'number' => 'GONE',
            'shipping_addresses' => [['bc_id' => 'x', 'code' => 'OLD']],
        ]);
        $this->fake($this->rows(1));

        $this->artisan('bc:import-ship-to-addresses', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(
            ImportBcShipToAddress::class,
            fn (ImportBcShipToAddress $job): bool => $job->replaceAll === true
                && $job->row['customerNo'] === 'GONE'
                && $job->row['rows'] === [],
        );
    }

    /**
     * A sweep has no "since": it must never send a filter, or a ship-to whose
     * customer arrived late would never be retried.
     */
    public function test_it_never_sends_a_since_filter(): void
    {
        $this->fake($this->rows(2));

        $this->artisan('bc:import-ship-to-addresses', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(function (Request $r): bool {
            if (str_starts_with($r->url(), self::URL)) {
                $this->assertNull($r['$filter'] ?? null);
            }

            return true;
        });
    }

    public function test_it_never_advances_a_since_checkpoint(): void
    {
        $this->fake($this->rows(2));

        $this->artisan('bc:import-ship-to-addresses', ['--page-size' => 200])->assertExitCode(0);

        $this->assertNull(SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_SHIP_TO_ADDRESSES)->last_modified_at);
    }

    public function test_force_needs_no_full_and_passes_the_flag(): void
    {
        $this->fake($this->rows(2));

        $this->artisan('bc:import-ship-to-addresses', ['--force' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcShipToAddress::class, fn (ImportBcShipToAddress $job): bool => $job->force === true);
    }

    public function test_customer_replaces_that_customers_list_whole(): void
    {
        $this->fake($this->rows(3));

        $this->artisan('bc:import-ship-to-addresses', ['--customer' => 'BROOKLAN'])->assertExitCode(0);

        Queue::assertPushed(ImportBcShipToAddress::class, 1);
        Queue::assertPushed(
            ImportBcShipToAddress::class,
            fn (ImportBcShipToAddress $job): bool => $job->replaceAll === true && count($job->row['rows']) === 3,
        );
    }

    public function test_customer_fetches_that_customers_addresses_only(): void
    {
        $this->fake($this->rows(3));

        $this->artisan('bc:import-ship-to-addresses', ['--customer' => "O'Brien"])->assertExitCode(0);

        Http::assertSent(function (Request $r): bool {
            if (str_starts_with($r->url(), self::URL)) {
                $this->assertSame("customerNo eq 'O''Brien'", $r['$filter']);
            }

            return true;
        });
    }
}
