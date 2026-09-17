<?php

namespace Tests\Feature\Console\Commands;

use App\BusinessCentral\ItemQuantitiesQuery;
use App\Jobs\ImportBcProductQuantity;
use App\Models\SyncCheckpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweeping the Business Central quantity endpoint.
 *
 * Every run reads the whole endpoint: its lastModifiedDateTime tracks the item
 * record rather than stock movement and is unset on most rows, so there is no
 * usable incremental signal. These tests pin that the sweep is complete, that
 * the query carries no website rules, and that a capped manual run cannot be
 * mistaken for a finished sweep.
 */
class ImportBcItemQuantitiesPaginationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const QUANTITIES_URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)/itemQuantities';

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
        Queue::fake([ImportBcProductQuantity::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $n): array
    {
        return [
            'id' => sprintf('%08d-3d1c-f111-8341-6045bde65a16', $n),
            'number' => sprintf('SKU%04d', $n),
            'type' => 'Inventory',
            'inventory' => $n,
            'qtyOnSalesOrder' => 0,
            'qtyOnPurchOrder' => 0,
            'qtyOnTransferOrder' => 0,
            'nextPurchaseReceiptDate' => '0001-01-01',
            'nextTransferReceiptDate' => '0001-01-01',
            // Most real rows carry this sentinel, which is why there is no
            // incremental fetch.
            'lastModifiedDateTime' => '0001-01-01T00:00:00Z',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count): array
    {
        return array_map(fn (int $n): array => $this->row($n), range(1, $count));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeEndpoint(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::QUANTITIES_URL.'*' => function (Request $request) use ($rows) {
                $top = (int) ($request['$top'] ?? 0);
                $skip = (int) ($request['$skip'] ?? 0);

                return Http::response(['value' => array_slice($rows, $skip, $top)]);
            },
        ]);
    }

    private function requestCount(): int
    {
        $count = 0;

        Http::assertSent(function (Request $request) use (&$count): bool {
            if (str_starts_with($request->url(), self::QUANTITIES_URL)) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    private function checkpoint(): SyncCheckpoint
    {
        return SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEM_QUANTITIES)->refresh();
    }

    // ----------------------------------------------------------- the query

    /**
     * Whether a product belongs on the website is decided in Laravel, from data
     * already imported. Filtering here would hide rows from that decision.
     */
    public function test_the_query_carries_no_website_eligibility_filter(): void
    {
        $query = ItemQuantitiesQuery::page(200);

        $this->assertArrayNotHasKey('$filter', $query);

        $encoded = json_encode($query) ?: '';

        foreach (['unitPrice', 'FINISHED GOODS', 'gppg', 'Non_x002D_Inventory'] as $rule) {
            $this->assertStringNotContainsString($rule, $encoded, "query still mentions {$rule}");
        }
    }

    public function test_the_query_class_no_longer_defines_a_business_filter(): void
    {
        $this->assertFalse(
            defined(ItemQuantitiesQuery::class.'::FILTER'),
            'the business eligibility FILTER constant should be gone',
        );
    }

    public function test_the_sweep_sends_no_filter_at_all(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-quantities')->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::QUANTITIES_URL)) {
                return true;
            }

            return ! isset($request['$filter']);
        });
    }

    /**
     * Ordering by the change timestamp would put thousands of identical
     * sentinel values first and leave paging to the server's tie-breaking.
     */
    public function test_paging_orders_by_id(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-quantities')->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::QUANTITIES_URL)) {
                return true;
            }

            return $request['$orderby'] === 'id asc';
        });
    }

    // -------------------------------------------------------------- sweeping

    public function test_a_sweep_reads_the_whole_endpoint(): void
    {
        $this->fakeEndpoint($this->rows(450));

        $this->artisan('bc:import-item-quantities', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProductQuantity::class, 450);
        $this->assertSame(3, $this->requestCount());
    }

    public function test_more_than_one_page_causes_more_than_one_request(): void
    {
        $this->fakeEndpoint($this->rows(201));

        $this->artisan('bc:import-item-quantities', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(2, $this->requestCount());
        Queue::assertPushed(ImportBcProductQuantity::class, 201);
    }

    /**
     * A large endpoint is read completely, not just its first page.
     */
    public function test_five_thousand_rows_are_all_fetched(): void
    {
        $this->fakeEndpoint($this->rows(5000));

        $this->artisan('bc:import-item-quantities', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProductQuantity::class, 5000);
        // 25 full pages, plus one more to learn the endpoint has ended.
        $this->assertSame(26, $this->requestCount());
    }

    /**
     * Every row shares the sentinel timestamp, so nothing may be lost to it.
     */
    public function test_identical_timestamps_spanning_pages_are_not_lost(): void
    {
        $this->fakeEndpoint($this->rows(450));

        $this->artisan('bc:import-item-quantities', ['--page-size' => 200])->assertExitCode(0);

        $seen = [];

        Queue::assertPushed(ImportBcProductQuantity::class, function (ImportBcProductQuantity $job) use (&$seen): bool {
            $seen[] = $job->row['number'];

            return true;
        });

        $this->assertCount(450, array_unique($seen));
    }

    public function test_each_page_skips_what_has_already_been_fetched(): void
    {
        $this->fakeEndpoint($this->rows(25));

        $this->artisan('bc:import-item-quantities', ['--page-size' => 10])->assertExitCode(0);

        $skips = [];

        Http::assertSent(function (Request $request) use (&$skips): bool {
            if (str_starts_with($request->url(), self::QUANTITIES_URL)) {
                $skips[] = (int) ($request['$skip'] ?? 0);
            }

            return true;
        });

        $this->assertSame([0, 10, 20], $skips);
    }

    // --------------------------------------------------------------- failure

    public function test_a_failure_on_a_later_page_is_reported(): void
    {
        $page = 0;
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::QUANTITIES_URL.'*' => function () use (&$page) {
                $page++;

                return $page === 1
                    ? Http::response(['value' => $this->rows(200)])
                    : Http::response('gateway timeout', 504);
            },
        ]);

        $this->artisan('bc:import-item-quantities', ['--page-size' => 200])
            ->expectsOutputToContain('Fetch failed after queueing 200 row(s)')
            ->assertExitCode(1);

        // Rows fetched before the failure are queued and harmless.
        Queue::assertPushed(ImportBcProductQuantity::class, 200);
    }

    /**
     * A failed sweep must not be recorded as a completed one.
     */
    public function test_a_failed_sweep_is_not_recorded(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::QUANTITIES_URL.'*' => Http::response('boom', 500),
        ]);

        $this->artisan('bc:import-item-quantities')->assertExitCode(1);

        $this->assertNull($this->checkpoint()->last_full_sync_at);
    }

    /**
     * The next sweep reads the whole endpoint again, so nothing is stranded.
     */
    public function test_a_retry_after_a_failure_sees_the_rows_again(): void
    {
        $attempt = 0;
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::QUANTITIES_URL.'*' => function (Request $request) use (&$attempt) {
                $attempt++;

                if ($attempt === 1) {
                    return Http::response('boom', 500);
                }

                $top = (int) ($request['$top'] ?? 0);
                $skip = (int) ($request['$skip'] ?? 0);

                return Http::response(['value' => array_slice($this->rows(10), $skip, $top)]);
            },
        ]);

        $this->artisan('bc:import-item-quantities')->assertExitCode(1);
        Queue::assertNothingPushed();

        $this->artisan('bc:import-item-quantities')->assertExitCode(0);
        Queue::assertPushed(ImportBcProductQuantity::class, 10);
    }

    // ------------------------------------------------------------ manual cap

    public function test_top_stops_at_that_many_rows_in_total(): void
    {
        $this->fakeEndpoint($this->rows(5000));

        $this->artisan('bc:import-item-quantities', ['--top' => 20])->assertExitCode(0);

        Queue::assertPushed(ImportBcProductQuantity::class, 20);
        $this->assertSame(1, $this->requestCount());
    }

    public function test_top_with_a_smaller_page_size_pages_up_to_the_cap(): void
    {
        $this->fakeEndpoint($this->rows(5000));

        $this->artisan('bc:import-item-quantities', ['--top' => 50, '--page-size' => 20])->assertExitCode(0);

        // 20, 20, 10 then stop.
        $this->assertSame(3, $this->requestCount());
        Queue::assertPushed(ImportBcProductQuantity::class, 50);
    }

    /**
     * A manual test must not make the schedule look like it swept everything.
     */
    public function test_a_capped_run_is_not_recorded_as_a_sweep(): void
    {
        $this->fakeEndpoint($this->rows(5000));

        $this->artisan('bc:import-item-quantities', ['--top' => 20])
            ->expectsOutputToContain('not recorded as a full sweep')
            ->assertExitCode(0);

        $this->assertNull($this->checkpoint()->last_full_sync_at);
        $this->assertSame(0, $this->checkpoint()->last_run_rows);
    }

    public function test_a_non_positive_page_size_is_refused(): void
    {
        $this->artisan('bc:import-item-quantities', ['--page-size' => 0])
            ->expectsOutputToContain('--page-size must be a positive integer')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- full

    public function test_full_reads_the_whole_endpoint(): void
    {
        $this->fakeEndpoint($this->rows(450));

        $this->artisan('bc:import-item-quantities', ['--full' => true, '--page-size' => 200])
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductQuantity::class, 450);
        $this->assertSame(3, $this->requestCount());
    }

    public function test_a_completed_sweep_is_recorded(): void
    {
        $this->fakeEndpoint($this->rows(250));

        $this->artisan('bc:import-item-quantities', ['--page-size' => 100])->assertExitCode(0);

        $checkpoint = $this->checkpoint();

        $this->assertSame(250, $checkpoint->last_run_rows);
        $this->assertSame(3, $checkpoint->last_run_pages);
        $this->assertNotNull($checkpoint->last_full_sync_at);
    }

    /**
     * Quantities keep their own row, so a sweep cannot disturb the item
     * checkpoint that the product incremental sync depends on.
     */
    public function test_a_sweep_leaves_the_item_checkpoint_alone(): void
    {
        SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEMS)
            ->update(['last_modified_at' => '2026-05-01 00:00:00']);

        $this->fakeEndpoint($this->rows(10));

        $this->artisan('bc:import-item-quantities')->assertExitCode(0);

        $this->assertSame(
            '2026-05-01T00:00:00+00:00',
            SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEMS)->last_modified_at->toIso8601String(),
        );
    }

    /**
     * Nothing about the quantity sweep may advance a timestamp position: there
     * is none, and inventing one would suggest incremental coverage it has not.
     */
    public function test_the_quantity_checkpoint_never_gains_a_timestamp(): void
    {
        $this->fakeEndpoint($this->rows(10));

        $this->artisan('bc:import-item-quantities')->assertExitCode(0);

        $this->assertNull($this->checkpoint()->last_modified_at);
    }

    // ------------------------------------------------------------------ force

    /**
     * Unlike the item and campaign imports, this one needs no --full: it
     * already sweeps the whole endpoint on every run, so there is no narrower
     * fetch for --force to contradict.
     */
    public function test_force_does_not_require_full(): void
    {
        $this->fakeEndpoint($this->rows(2));

        $this->artisan('bc:import-item-quantities', ['--force' => true])
            ->expectsOutputToContain('Forcing re-delivery')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductQuantity::class, 2);
    }

    public function test_force_cannot_be_combined_with_top(): void
    {
        $this->fakeEndpoint($this->rows(3));

        $this->artisan('bc:import-item-quantities', ['--force' => true, '--top' => 1])
            ->expectsOutputToContain('--force cannot be combined with --top')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_force_cannot_be_combined_with_sku(): void
    {
        $this->fakeEndpoint($this->rows(1));

        $this->artisan('bc:import-item-quantities', ['--force' => true, '--sku' => 'AA27'])
            ->expectsOutputToContain('--force cannot be combined with --sku')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_declining_the_confirmation_fetches_nothing(): void
    {
        $this->fakeEndpoint($this->rows(2));

        $this->artisan('bc:import-item-quantities', ['--force' => true])
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutputToContain('Nothing was fetched or queued')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_force_passes_the_flag_to_every_job(): void
    {
        $this->fakeEndpoint($this->rows(2));

        $this->artisan('bc:import-item-quantities', ['--force' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(
            ImportBcProductQuantity::class,
            fn (ImportBcProductQuantity $job): bool => $job->force === true,
        );
    }

    public function test_a_normal_run_does_not_force(): void
    {
        $this->fakeEndpoint($this->rows(1));

        $this->artisan('bc:import-item-quantities', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(
            ImportBcProductQuantity::class,
            fn (ImportBcProductQuantity $job): bool => $job->force === false,
        );
    }
}
