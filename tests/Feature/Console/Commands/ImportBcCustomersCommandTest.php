<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcCustomer;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incremental customer synchronisation.
 *
 * The behaviour that matters is when the command stops fetching and when it is
 * allowed to move the checkpoint, exactly as for items. A customer that stops
 * updating does not look broken — the website keeps showing the last promotion
 * it was told about — so the boundary is asserted from several directions.
 */
class ImportBcCustomersCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const CUSTOMERS_URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)/customersExt';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com',
            'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc',
            'client_secret' => 'secret-abc',
            'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid',
            'api_version' => 'v2.0',
            'http_timeout' => 30,
            'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Queue::fake([ImportBcCustomer::class]);
    }

    /**
     * One Business Central customer row.
     *
     * @param  array<int, string>  $customers
     * @return array<string, mixed>
     */
    private function row(int $n, string $modified = '2026-06-15T00:23:21.653Z'): array
    {
        return [
            'id' => sprintf('%08d-a51d-f111-8340-7ced8d32d199', $n),
            'number' => sprintf('CUST%04d', $n),
            'displayName' => "Customer {$n}",
            'type' => 'Company',
            'blocked' => '_x0020_',
            'customerPriceGroup' => 'LIST PRICE',
            'customerDiscGroup' => 'LIST',
            'lastModifiedDateTime' => $modified,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count, string $modified = '2026-06-15T00:23:21.653Z'): array
    {
        return array_map(fn (int $n): array => $this->row($n, $modified), range(1, $count));
    }

    private function checkpoint(): SyncCheckpoint
    {
        return SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_CUSTOMERS)->refresh();
    }

    /**
     * Serve rows a page at a time, honouring $top, $skip and $filter as the API does.
     *
     * The $filter is applied rather than ignored, because the incremental
     * boundary is the behaviour under test: a fake that replays every row
     * regardless would pass just as happily with a broken comparison.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeCustomers(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOMERS_URL.'*' => function (Request $request) use ($rows) {
                $top = (int) ($request['$top'] ?? 0);
                $skip = (int) ($request['$skip'] ?? 0);

                return Http::response([
                    'value' => array_slice(
                        array_values(self::applySince($rows, $request['$filter'] ?? null)),
                        $skip,
                        $top,
                    ),
                ]);
            },
        ]);
    }

    /**
     * Apply a "lastModifiedDateTime gt|ge <literal>" filter, as the API would.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function applySince(array $rows, ?string $filter): array
    {
        if ($filter === null || ! preg_match('/^lastModifiedDateTime (gt|ge) (\S+)$/', $filter, $m)) {
            return $rows;
        }

        $since = CarbonImmutable::parse($m[2]);

        return array_filter($rows, fn (array $row): bool => $m[1] === 'gt'
            ? CarbonImmutable::parse($row['lastModifiedDateTime'])->greaterThan($since)
            : CarbonImmutable::parse($row['lastModifiedDateTime'])->greaterThanOrEqualTo($since));
    }

    /**
     * The $filter values sent, in order.
     *
     * @return array<int, string|null>
     */
    private function sentFilters(): array
    {
        $filters = [];

        Http::assertSent(function (Request $request) use (&$filters): bool {
            if (str_starts_with($request->url(), self::CUSTOMERS_URL)) {
                $filters[] = $request['$filter'] ?? null;
            }

            return true;
        });

        return $filters;
    }

    private function customerRequestCount(): int
    {
        return count($this->sentFilters());
    }

    // ---------------------------------------------------------------- fetching

    public function test_a_first_run_fetches_every_customer(): void
    {
        $this->fakeCustomers($this->rows(3));

        $this->artisan('bc:import-customers', ['--page-size' => 200])
            ->expectsOutputToContain('No checkpoint yet')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcCustomer::class, 3);
    }

    public function test_a_first_run_sends_no_since_filter(): void
    {
        $this->fakeCustomers($this->rows(2));

        $this->artisan('bc:import-customers', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame([null], $this->sentFilters());
    }

    public function test_paging_orders_by_timestamp_and_id(): void
    {
        $this->fakeCustomers($this->rows(3));

        $this->artisan('bc:import-customers', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (str_starts_with($request->url(), self::CUSTOMERS_URL)) {
                $this->assertSame('lastModifiedDateTime asc,id asc', $request['$orderby']);
            }

            return true;
        });
    }

    public function test_more_than_one_page_causes_more_than_one_request(): void
    {
        $this->fakeCustomers($this->rows(5));

        $this->artisan('bc:import-customers', ['--page-size' => 2])->assertExitCode(0);

        Queue::assertPushed(ImportBcCustomer::class, 5);
        $this->assertSame(3, $this->customerRequestCount());
    }

    // ------------------------------------------------------------- checkpoint

    public function test_a_complete_first_sync_saves_the_latest_timestamp(): void
    {
        $this->fakeCustomers([
            $this->row(1, '2026-03-01T00:00:00.000Z'),
            $this->row(2, '2026-05-20T09:30:00.500Z'),
            $this->row(3, '2026-04-10T12:00:00.000Z'),
        ]);

        $this->artisan('bc:import-customers', ['--page-size' => 200])->assertExitCode(0);

        // The maximum seen, not the last row's.
        $this->assertSame(
            '2026-05-20T09:30:00.500Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    /**
     * The precision Business Central sent must survive the database. At whole
     * seconds the checkpoint would sit before the record it came from, and the
     * strict "gt" filter would return that record on every run.
     */
    public function test_fractional_seconds_survive_storage(): void
    {
        $this->fakeCustomers([$this->row(1, '2026-09-17T05:33:47.08Z')]);

        $this->artisan('bc:import-customers', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(
            '2026-09-17T05:33:47.080Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_business_central_receives_the_exact_fractional_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-09-17 05:33:47.080']);
        $this->fakeCustomers([]);

        $this->artisan('bc:import-customers', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(
            ['lastModifiedDateTime gt 2026-09-17T05:33:47.080Z'],
            $this->sentFilters(),
        );
    }

    /**
     * The reported bug class, for customers: a second run must not re-fetch the
     * record the checkpoint came from.
     */
    public function test_a_second_run_does_not_refetch_the_boundary_customer(): void
    {
        $this->fakeCustomers([$this->row(1, '2026-06-15T00:23:21.653Z')]);
        $this->artisan('bc:import-customers', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcCustomer::class, 1);

        $this->artisan('bc:import-customers', ['--page-size' => 200])
            ->expectsOutputToContain('No customers have changed')
            ->assertExitCode(0);

        // Still 1: the second run dispatched nothing on top of the first.
        Queue::assertPushed(ImportBcCustomer::class, 1);
    }

    public function test_a_capped_run_never_advances_the_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-01-01 00:00:00.000']);
        $this->fakeCustomers($this->rows(3));

        $this->artisan('bc:import-customers', ['--page-size' => 200, '--top' => 2])
            ->expectsOutputToContain('checkpoint was left unchanged')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-01-01T00:00:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_a_failure_on_a_later_page_does_not_advance_the_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-01-01 00:00:00.000']);

        $rows = $this->rows(5);
        $calls = 0;

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOMERS_URL.'*' => function (Request $request) use ($rows, &$calls) {
                $calls++;

                if ($calls === 2) {
                    return Http::response(['error' => 'boom'], 500);
                }

                $top = (int) ($request['$top'] ?? 0);
                $skip = (int) ($request['$skip'] ?? 0);

                return Http::response(['value' => array_slice($rows, $skip, $top)]);
            },
        ]);

        $this->artisan('bc:import-customers', ['--page-size' => 2])
            ->expectsOutputToContain('checkpoint was not advanced')
            ->assertExitCode(1);

        $this->assertSame(
            '2026-01-01T00:00:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_a_full_run_ignores_the_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-12-01 00:00:00.000']);
        $this->fakeCustomers($this->rows(3));

        $this->artisan('bc:import-customers', ['--page-size' => 200, '--full' => true])
            ->expectsOutputToContain('Full reconciliation')
            ->assertExitCode(0);

        $this->assertSame([null], $this->sentFilters());
        Queue::assertPushed(ImportBcCustomer::class, 3);
    }

    /**
     * A full run re-reads old records, so it must never drag the checkpoint
     * back behind what the incremental runs have already reached.
     */
    public function test_a_full_run_does_not_drag_the_checkpoint_backwards(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-12-01 00:00:00.000']);
        $this->fakeCustomers($this->rows(2, '2026-06-15T00:23:21.653Z'));

        $this->artisan('bc:import-customers', ['--page-size' => 200, '--full' => true])
            ->expectsOutputToContain('saw nothing newer')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-12-01T00:00:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    // ------------------------------------------------------------------ --number

    public function test_number_queues_only_that_customer(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOMERS_URL.'*' => Http::response(['value' => [$this->row(2)]]),
        ]);

        $this->artisan('bc:import-customers', ['--number' => 'CUST0002'])
            ->expectsOutputToContain('checkpoint was left unchanged')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcCustomer::class, 1);
    }

    public function test_number_asks_business_central_for_that_code_only(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOMERS_URL.'*' => Http::response(['value' => []]),
        ]);

        $this->artisan('bc:import-customers', ['--number' => "O'Brien"])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (str_starts_with($request->url(), self::CUSTOMERS_URL)) {
                // The quote is doubled, or the filter would be malformed.
                $this->assertSame("number eq 'O''Brien'", $request['$filter']);
            }

            return true;
        });
    }

    public function test_number_leaves_an_existing_checkpoint_untouched(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-01-01 00:00:00.000']);

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOMERS_URL.'*' => Http::response(['value' => [$this->row(1, '2026-12-31T00:00:00.000Z')]]),
        ]);

        $this->artisan('bc:import-customers', ['--number' => 'CUST0001'])->assertExitCode(0);

        $this->assertSame(
            '2026-01-01T00:00:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    // ----------------------------------------------------------------- options

    public function test_a_non_positive_page_size_is_refused(): void
    {
        $this->artisan('bc:import-customers', ['--page-size' => 0])
            ->expectsOutputToContain('--page-size must be a positive integer')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_a_non_positive_top_is_refused(): void
    {
        $this->artisan('bc:import-customers', ['--top' => 0])
            ->expectsOutputToContain('--top must be a positive integer')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------ force

    public function test_force_requires_full(): void
    {
        $this->fakeCustomers($this->rows(1));

        $this->artisan('bc:import-customers', ['--force' => true])
            ->expectsOutputToContain('--force requires --full')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_force_cannot_be_combined_with_top(): void
    {
        $this->fakeCustomers($this->rows(3));

        $this->artisan('bc:import-customers', ['--full' => true, '--force' => true, '--top' => 1])
            ->expectsOutputToContain('--force cannot be combined with --top')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_force_cannot_be_combined_with_number(): void
    {
        $this->fakeCustomers($this->rows(1));

        $this->artisan('bc:import-customers', ['--full' => true, '--force' => true, '--number' => 'CUST0001'])
            ->expectsOutputToContain('--force cannot be combined with --number')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /**
     * A forced run re-sends the whole catalogue, so it says so and waits.
     */
    public function test_force_warns_and_asks_before_running(): void
    {
        $this->fakeCustomers($this->rows(2));

        $this->artisan('bc:import-customers', ['--full' => true, '--force' => true])
            ->expectsOutputToContain('Forcing re-delivery')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcCustomer::class, 2);
    }

    public function test_declining_the_confirmation_fetches_nothing(): void
    {
        $this->fakeCustomers($this->rows(2));

        $this->artisan('bc:import-customers', ['--full' => true, '--force' => true])
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutputToContain('Nothing was fetched or queued')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_force_passes_the_flag_to_every_job(): void
    {
        $this->fakeCustomers($this->rows(2));

        $this->artisan('bc:import-customers', ['--full' => true, '--force' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(
            ImportBcCustomer::class,
            fn (ImportBcCustomer $job): bool => $job->force === true,
        );
    }

    public function test_a_normal_run_does_not_force(): void
    {
        $this->fakeCustomers($this->rows(1));

        $this->artisan('bc:import-customers', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(
            ImportBcCustomer::class,
            fn (ImportBcCustomer $job): bool => $job->force === false,
        );
    }
}
