<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcSalesOrder;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incremental sales order synchronisation.
 *
 * The behaviour that matters is when the command stops fetching and when it is
 * allowed to move the checkpoint, exactly as for customers. Only the header is
 * fetched here; the documents are the job's concern.
 */
class ImportBcSalesOrdersCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const ORDERS_URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)/salesOrdersExt';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com', 'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc', 'client_secret' => 'secret-abc', 'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid', 'api_version' => 'v2.0', 'http_timeout' => 30, 'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Queue::fake([ImportBcSalesOrder::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $n, string $modified = '2026-06-15T00:23:21.653Z'): array
    {
        return [
            'id' => sprintf('%08d-a51d-f111-8340-7ced8d32d199', $n),
            'number' => sprintf('SO%06d', $n),
            'customerId' => '71431cfe-a51d-f111-8340-7ced8d32d199',
            'customerName' => "Customer {$n}",
            'status' => 'Released',
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
        return SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_SALES_ORDERS)->refresh();
    }

    /**
     * Serve rows a page at a time, honouring $top, $skip and $filter as the API does.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeOrders(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ORDERS_URL.'*' => function (Request $request) use ($rows) {
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
     * @return array<int, string|null>
     */
    private function sentFilters(): array
    {
        $filters = [];

        Http::assertSent(function (Request $request) use (&$filters): bool {
            if (str_starts_with($request->url(), self::ORDERS_URL)) {
                $filters[] = $request['$filter'] ?? null;
            }

            return true;
        });

        return $filters;
    }

    // ---------------------------------------------------------------- fetching

    public function test_a_first_run_fetches_every_order(): void
    {
        $this->fakeOrders($this->rows(3));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])
            ->expectsOutputToContain('No checkpoint yet')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcSalesOrder::class, 3);
    }

    public function test_a_first_run_sends_no_since_filter(): void
    {
        $this->fakeOrders($this->rows(2));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame([null], $this->sentFilters());
    }

    /**
     * The header page only. The documents are read by the job, so the
     * command must not touch the standard API.
     */
    public function test_the_command_reads_the_header_page_only(): void
    {
        $this->fakeOrders($this->rows(2));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (str_starts_with($request->url(), self::ORDERS_URL)) {
                $this->assertSame('lastModifiedDateTime asc,id asc', $request['$orderby']);
                $this->assertStringContainsString('workDescription', $request['$select']);
            }

            $this->assertStringNotContainsString('salesOrderLines', $request->url());

            return true;
        });
    }

    public function test_more_than_one_page_causes_more_than_one_request(): void
    {
        $this->fakeOrders($this->rows(5));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 2])->assertExitCode(0);

        Queue::assertPushed(ImportBcSalesOrder::class, 5);
        $this->assertCount(3, $this->sentFilters());
    }

    // ------------------------------------------------------------- checkpoint

    public function test_a_complete_first_sync_saves_the_latest_timestamp(): void
    {
        $this->fakeOrders([
            $this->row(1, '2026-03-01T00:00:00.000Z'),
            $this->row(2, '2026-05-20T09:30:00.500Z'),
            $this->row(3, '2026-04-10T12:00:00.000Z'),
        ]);

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(
            '2026-05-20T09:30:00.500Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_business_central_receives_the_exact_fractional_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-09-17 05:33:47.080']);
        $this->fakeOrders([]);

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(
            ['lastModifiedDateTime gt 2026-09-17T05:33:47.080Z'],
            $this->sentFilters(),
        );
    }

    public function test_a_second_run_does_not_refetch_the_boundary_order(): void
    {
        $this->fakeOrders([$this->row(1, '2026-06-15T00:23:21.653Z')]);
        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcSalesOrder::class, 1);

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])
            ->expectsOutputToContain('No sales orders have changed')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcSalesOrder::class, 1);
    }

    public function test_a_capped_run_never_advances_the_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-01-01 00:00:00.000']);
        $this->fakeOrders($this->rows(3));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200, '--top' => 2])
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
            self::ORDERS_URL.'*' => function (Request $request) use ($rows, &$calls) {
                $calls++;

                if ($calls === 2) {
                    return Http::response(['error' => 'boom'], 500);
                }

                return Http::response(['value' => array_slice($rows, (int) ($request['$skip'] ?? 0), (int) ($request['$top'] ?? 0))]);
            },
        ]);

        $this->artisan('bc:import-sales-orders', ['--page-size' => 2])
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
        $this->fakeOrders($this->rows(3));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200, '--full' => true])
            ->expectsOutputToContain('Full reconciliation')
            ->assertExitCode(0);

        $this->assertSame([null], $this->sentFilters());
        Queue::assertPushed(ImportBcSalesOrder::class, 3);
    }

    public function test_a_full_run_does_not_drag_the_checkpoint_backwards(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-12-01 00:00:00.000']);
        $this->fakeOrders($this->rows(2, '2026-06-15T00:23:21.653Z'));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200, '--full' => true])
            ->expectsOutputToContain('saw nothing newer')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-12-01T00:00:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    // ------------------------------------------------------------------ --number

    public function test_number_queues_only_that_order(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ORDERS_URL.'*' => Http::response(['value' => [$this->row(2)]]),
        ]);

        $this->artisan('bc:import-sales-orders', ['--number' => 'SO000002'])
            ->expectsOutputToContain('checkpoint was left unchanged')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcSalesOrder::class, 1);
    }

    public function test_number_asks_business_central_for_that_number_only(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ORDERS_URL.'*' => Http::response(['value' => []]),
        ]);

        $this->artisan('bc:import-sales-orders', ['--number' => "SO'1"])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (str_starts_with($request->url(), self::ORDERS_URL)) {
                $this->assertSame("number eq 'SO''1'", $request['$filter']);
            }

            return true;
        });
    }

    // ----------------------------------------------------------------- options

    public function test_a_non_positive_page_size_is_refused(): void
    {
        $this->artisan('bc:import-sales-orders', ['--page-size' => 0])
            ->expectsOutputToContain('--page-size must be a positive integer')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------ force

    public function test_force_requires_full(): void
    {
        $this->fakeOrders($this->rows(1));

        $this->artisan('bc:import-sales-orders', ['--force' => true])
            ->expectsOutputToContain('--force requires --full')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_force_passes_the_flag_to_every_job(): void
    {
        $this->fakeOrders($this->rows(2));

        $this->artisan('bc:import-sales-orders', ['--full' => true, '--force' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(
            ImportBcSalesOrder::class,
            fn (ImportBcSalesOrder $job): bool => $job->force === true,
        );
    }

    public function test_a_normal_run_does_not_force(): void
    {
        $this->fakeOrders($this->rows(1));

        $this->artisan('bc:import-sales-orders', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(
            ImportBcSalesOrder::class,
            fn (ImportBcSalesOrder $job): bool => $job->force === false,
        );
    }
}
