<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcProduct;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incremental and full product synchronisation.
 *
 * The behaviour that matters is when the command stops fetching and when it is
 * allowed to move the checkpoint. Getting either wrong loses products silently,
 * so both are asserted from several directions.
 */
class ImportBcItemsPaginationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const ITEMS_URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)/itemsExt';

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
        Queue::fake([ImportBcProduct::class]);
    }

    /**
     * One Business Central row.
     *
     * @return array<string, mixed>
     */
    private function row(int $n, string $modified = '2026-03-12T11:06:22.503Z'): array
    {
        return [
            'id' => sprintf('%08d-3d1c-f111-8341-6045bde65a16', $n),
            'number' => sprintf('SKU%04d', $n),
            'displayName' => "Product {$n}",
            'type' => 'Inventory',
            'unitPrice' => 10,
            'gppg' => 'FINISHED GOODS',
            'lastModifiedDateTime' => $modified,
        ];
    }

    /**
     * Serve rows a page at a time, honouring $top, $skip and $filter as the
     * API does.
     *
     * The $filter is applied rather than ignored, because the incremental
     * boundary is the behaviour under test: a fake that replays every row
     * regardless would pass just as happily with a broken comparison.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeCatalogue(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => function (Request $request) use ($rows) {
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
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count, string $modified = '2026-03-12T11:06:22.503Z'): array
    {
        return array_map(fn (int $n): array => $this->row($n, $modified), range(1, $count));
    }

    private function checkpoint(): SyncCheckpoint
    {
        return SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEMS)->refresh();
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
            if (str_starts_with($request->url(), self::ITEMS_URL)) {
                $filters[] = $request['$filter'] ?? null;
            }

            return true;
        });

        return $filters;
    }

    private function itemRequestCount(): int
    {
        return count($this->sentFilters());
    }

    // ------------------------------------------------------- first / complete

    public function test_a_null_checkpoint_fetches_the_complete_catalogue(): void
    {
        $this->fakeCatalogue($this->rows(450));

        $this->artisan('bc:import-items', ['--page-size' => 200])
            ->expectsOutputToContain('No checkpoint yet')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 450);
        $this->assertSame(3, $this->itemRequestCount(), 'expected 200 + 200 + 50');
    }

    public function test_a_first_run_sends_no_since_filter(): void
    {
        $this->fakeCatalogue($this->rows(10));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame([null], $this->sentFilters());
    }

    public function test_a_complete_first_sync_saves_the_latest_timestamp(): void
    {
        $this->fakeCatalogue([
            $this->row(1, '2026-03-01T00:00:00Z'),
            $this->row(2, '2026-05-20T09:30:00Z'),
            $this->row(3, '2026-04-10T12:00:00Z'),
        ]);

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        // The maximum seen, not the last row's.
        $this->assertSame(
            '2026-05-20T09:30:00+00:00',
            $this->checkpoint()->last_modified_at->toIso8601String(),
        );
    }

    /**
     * 5,000 changed products must all be fetched, not just the first page.
     */
    public function test_a_large_result_set_is_fetched_completely(): void
    {
        $this->fakeCatalogue($this->rows(5000));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 5000);
        // 25 full pages, plus one more to learn the result set has ended.
        $this->assertSame(26, $this->itemRequestCount());
    }

    public function test_more_than_one_page_causes_more_than_one_request(): void
    {
        $this->fakeCatalogue($this->rows(201));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(2, $this->itemRequestCount());
        Queue::assertPushed(ImportBcProduct::class, 201);
    }

    /**
     * An exact multiple of the page size needs one more request to learn the
     * result set has ended.
     */
    public function test_an_exact_page_multiple_is_not_cut_short(): void
    {
        $this->fakeCatalogue($this->rows(400));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 400);
        $this->assertSame(3, $this->itemRequestCount());
    }

    // ----------------------------------------------------------- incremental

    public function test_an_incremental_run_with_nothing_changed_queues_nothing(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);
        $this->fakeCatalogue([]);

        $this->artisan('bc:import-items', ['--page-size' => 200])
            ->expectsOutputToContain('No items have changed')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_one_changed_product_queues_one_job(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);
        $this->fakeCatalogue([$this->row(1, '2026-05-02T08:00:00Z')]);

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 1);
        $this->assertSame(
            '2026-05-02T08:00:00+00:00',
            $this->checkpoint()->last_modified_at->toIso8601String(),
        );
    }

    /**
     * The boundary is exclusive.
     *
     * Products sharing the checkpoint's exact timestamp have, by definition,
     * already been fetched: the checkpoint only reaches a timestamp once an
     * uncapped run has fetched that timestamp's entire result set. Returning
     * them again is what stopped the checkpoint ever moving past the newest
     * record in the catalogue.
     */
    public function test_the_since_filter_is_exclusive_of_the_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00.000']);
        $this->fakeCatalogue($this->rows(3, '2026-05-01T00:00:00Z'));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(
            ['lastModifiedDateTime gt 2026-05-01T00:00:00.000Z'],
            array_slice($this->sentFilters(), 0, 1),
        );
    }

    /**
     * The invariant that makes an exclusive boundary safe.
     *
     * The checkpoint never moves between pages, so 250 products sharing one
     * millisecond are all dispatched before it can reach that millisecond.
     * Excluding them next time therefore drops nothing.
     */
    public function test_records_sharing_a_timestamp_are_all_fetched_before_the_checkpoint_moves(): void
    {
        // 250 products all changed in the same millisecond: more than one page.
        $this->fakeCatalogue($this->rows(250, '2026-05-01T00:00:00.080Z'));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 250);
        $this->assertSame(
            '2026-05-01T00:00:00.080Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    /**
     * The reported bug, end to end.
     *
     * 450 products share one millisecond and span three pages. Every one is
     * dispatched, the checkpoint lands on that millisecond, and the next run
     * returns none of them — where the inclusive filter returned all 450 and
     * could never advance.
     */
    public function test_a_multi_page_timestamp_collision_is_fetched_once_and_not_repeated(): void
    {
        $collision = $this->rows(450, '2026-09-17T05:33:47.080Z');

        $this->fakeCatalogue($collision);
        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        // 200 + 200 + 50, then the pager stops on the short page.
        Queue::assertPushed(ImportBcProduct::class, 450);
        $this->assertSame(3, $this->itemRequestCount());
        $this->assertSame(
            '2026-09-17T05:33:47.080Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );

        // Second run: Business Central has nothing strictly newer.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => []]),
        ]);

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        // Still 450: the second run dispatched nothing on top of the first.
        Queue::assertPushed(ImportBcProduct::class, 450);
        $this->assertSame(
            ['lastModifiedDateTime gt 2026-09-17T05:33:47.080Z'],
            $this->sentFilters(),
        );
    }

    /**
     * Scale: 5,000 products on one millisecond must all be fetched, not just
     * the first page, before the checkpoint is allowed to move.
     */
    public function test_five_thousand_products_sharing_one_timestamp_are_all_fetched(): void
    {
        $this->fakeCatalogue($this->rows(5000, '2026-09-17T05:33:47.080Z'));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 5000);
        // 25 full pages, plus one more to learn the result set has ended.
        $this->assertSame(26, $this->itemRequestCount());
        $this->assertSame(
            '2026-09-17T05:33:47.080Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    /**
     * A failure partway through a collision must not strand the rest of it.
     *
     * The checkpoint stays where it was, so the retry re-reads the complete
     * set rather than resuming past the products the failed run never saw.
     */
    public function test_a_retry_after_a_mid_collision_failure_sees_the_complete_set_again(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00.000']);

        $rows = $this->rows(450, '2026-09-17T05:33:47.080Z');
        $calls = 0;

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => function (Request $request) use ($rows, &$calls) {
                $calls++;

                // The second page fails, after the first has been queued.
                if ($calls === 2) {
                    return Http::response(['error' => 'boom'], 500);
                }

                $top = (int) ($request['$top'] ?? 0);
                $skip = (int) ($request['$skip'] ?? 0);

                return Http::response(['value' => array_slice($rows, $skip, $top)]);
            },
        ]);

        $this->artisan('bc:import-items', ['--page-size' => 200])
            ->expectsOutputToContain('checkpoint was not advanced')
            ->assertExitCode(1);

        $this->assertSame(
            '2026-05-01T00:00:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );

        // The retry starts from the untouched checkpoint and sees all 450.
        // The failed run queued its first page, so 200 + 450 have been pushed.
        $this->fakeCatalogue($rows);

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 650);
        $this->assertSame(
            '2026-09-17T05:33:47.080Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    /**
     * The precision Business Central sent must survive the database.
     *
     * Asserted by re-reading the model, so a column or serialisation that
     * floors to whole seconds fails here rather than silently in production.
     */
    public function test_fractional_seconds_survive_storage(): void
    {
        $this->fakeCatalogue([$this->row(1, '2026-09-17T05:33:47.08Z')]);

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(
            '2026-09-17T05:33:47.080Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
        $this->assertSame(
            '2026-09-17 05:33:47.080',
            $this->checkpoint()->last_modified_at->format('Y-m-d H:i:s.v'),
        );
    }

    /**
     * Business Central must receive the fraction, not a floored second.
     *
     * A whole-second filter would sit before the record the checkpoint came
     * from, and the exclusive comparison would return it on every run.
     */
    public function test_business_central_receives_the_exact_fractional_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-09-17 05:33:47.080']);
        $this->fakeCatalogue([]);

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(
            ['lastModifiedDateTime gt 2026-09-17T05:33:47.080Z'],
            $this->sentFilters(),
        );
    }

    /**
     * The ordering must be total, or $skip would repeat and skip rows when the
     * server broke timestamp ties differently between calls.
     */
    public function test_paging_orders_by_timestamp_and_id(): void
    {
        $this->fakeCatalogue($this->rows(5));

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::ITEMS_URL)) {
                return true;
            }

            return $request['$orderby'] === 'lastModifiedDateTime asc,id asc';
        });
    }

    public function test_each_page_skips_what_has_already_been_fetched(): void
    {
        $this->fakeCatalogue($this->rows(25));

        $this->artisan('bc:import-items', ['--page-size' => 10])->assertExitCode(0);

        $skips = [];

        Http::assertSent(function (Request $request) use (&$skips): bool {
            if (str_starts_with($request->url(), self::ITEMS_URL)) {
                $skips[] = (int) ($request['$skip'] ?? 0);
            }

            return true;
        });

        $this->assertSame([0, 10, 20], $skips);
    }

    // --------------------------------------------------------------- failure

    /**
     * A run that dies midway has not seen the whole result set, so advancing
     * would step over the pages it never fetched.
     */
    public function test_a_failure_on_a_later_page_does_not_advance_the_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);

        $page = 0;
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => function () use (&$page) {
                $page++;

                return $page === 1
                    ? Http::response(['value' => $this->rows(200, '2026-06-01T00:00:00Z')])
                    : Http::response('gateway timeout', 504);
            },
        ]);

        $this->artisan('bc:import-items', ['--page-size' => 200])
            ->expectsOutputToContain('checkpoint was not advanced')
            ->assertExitCode(1);

        // The first page's jobs were queued and are harmless; the checkpoint
        // stayed put so the next run re-reads from the same place.
        Queue::assertPushed(ImportBcProduct::class, 200);
        $this->assertSame(
            '2026-05-01T00:00:00+00:00',
            $this->checkpoint()->last_modified_at->toIso8601String(),
        );
    }

    public function test_a_failure_on_the_first_page_leaves_a_null_checkpoint_null(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response('boom', 500),
        ]);

        $this->artisan('bc:import-items', ['--page-size' => 200])->assertExitCode(1);

        $this->assertNull($this->checkpoint()->last_modified_at);
    }

    // ------------------------------------------------------------ manual cap

    public function test_top_dispatches_at_most_that_many_in_total(): void
    {
        $this->fakeCatalogue($this->rows(5000));

        $this->artisan('bc:import-items', ['--top' => 20, '--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 20);
    }

    /**
     * A cap must not be read as a page size that repeats until exhaustion.
     */
    public function test_top_never_pages_through_the_whole_catalogue(): void
    {
        $this->fakeCatalogue($this->rows(5000));

        $this->artisan('bc:import-items', ['--top' => 20])->assertExitCode(0);

        $this->assertSame(1, $this->itemRequestCount(), 'a capped run should need one request');
        Queue::assertPushed(ImportBcProduct::class, 20);
    }

    public function test_top_with_a_smaller_page_size_pages_up_to_the_cap(): void
    {
        $this->fakeCatalogue($this->rows(5000));

        $this->artisan('bc:import-items', ['--top' => 50, '--page-size' => 20])->assertExitCode(0);

        // 20, 20, 10 then stop.
        $this->assertSame(3, $this->itemRequestCount());
        Queue::assertPushed(ImportBcProduct::class, 50);
    }

    public function test_a_capped_run_never_advances_the_checkpoint(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);
        $this->fakeCatalogue($this->rows(5000, '2026-06-01T00:00:00Z'));

        $this->artisan('bc:import-items', ['--top' => 20])
            ->expectsOutputToContain('checkpoint was left unchanged')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-05-01T00:00:00+00:00',
            $this->checkpoint()->last_modified_at->toIso8601String(),
        );
    }

    public function test_a_capped_run_on_a_null_checkpoint_leaves_it_null(): void
    {
        $this->fakeCatalogue($this->rows(100));

        $this->artisan('bc:import-items', ['--top' => 20])->assertExitCode(0);

        $this->assertNull($this->checkpoint()->last_modified_at);
    }

    public function test_a_non_positive_page_size_is_refused(): void
    {
        $this->artisan('bc:import-items', ['--page-size' => 0])
            ->expectsOutputToContain('--page-size must be a positive integer')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------ single SKU

    /**
     * A targeted lookup for manual testing: one named record, nothing else.
     */
    public function test_sku_queues_only_that_product(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->row(57)]]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'SKU0057'])
            ->expectsOutputToContain('Queued 1 item(s)')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 1);
        Queue::assertPushed(
            ImportBcProduct::class,
            fn (ImportBcProduct $job): bool => $job->row['number'] === 'SKU0057',
        );
    }

    public function test_sku_asks_business_central_for_that_number_only(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->row(57)]]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'KP57'])->assertExitCode(0);

        $this->assertSame(["number eq 'KP57'"], $this->sentFilters());
    }

    /**
     * No paging, no ordering, no since-filter: there is no result set to walk.
     */
    public function test_sku_makes_exactly_one_request(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->row(57)]]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'KP57'])->assertExitCode(0);

        $this->assertSame(1, $this->itemRequestCount());
    }

    public function test_sku_does_not_fetch_unrelated_products(): void
    {
        // The whole catalogue is available, but only the named row comes back
        // because Business Central applies the filter.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => function (Request $request) {
                $this->assertSame("number eq 'SKU0003'", $request['$filter']);
                $this->assertArrayNotHasKey('$skip', $request->data());

                return Http::response(['value' => [$this->row(3)]]);
            },
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'SKU0003'])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 1);
    }

    public function test_a_missing_sku_queues_nothing_and_says_so(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => []]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'NOPE999'])
            ->expectsOutputToContain('No Business Central item has the number "NOPE999"')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_sku_leaves_an_existing_checkpoint_untouched(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            // Newer than the checkpoint: advancing would be plausible but wrong.
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->row(57, '2026-09-01T00:00:00Z')]]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'KP57'])
            ->expectsOutputToContain('checkpoint was left unchanged')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-05-01T00:00:00+00:00',
            $this->checkpoint()->last_modified_at->toIso8601String(),
        );
    }

    /**
     * A null checkpoint must stay null: one product proves nothing about the
     * catalogue, and treating it as a complete sync would skip everything else.
     */
    public function test_sku_leaves_a_null_checkpoint_null(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->row(57, '2026-09-01T00:00:00Z')]]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'KP57'])->assertExitCode(0);

        $this->assertNull($this->checkpoint()->last_modified_at);
    }

    public function test_sku_ignores_the_checkpoint_when_fetching(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-09-01 00:00:00']);

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            // Older than the checkpoint, so an incremental run would not see it.
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->row(57, '2026-01-01T00:00:00Z')]]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => 'KP57'])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, 1);
        $this->assertSame(["number eq 'KP57'"], $this->sentFilters());
    }

    /**
     * A quote would end the OData string literal and malform the filter.
     */
    public function test_a_sku_containing_a_quote_is_escaped(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => []]),
        ]);

        $this->artisan('bc:import-items', ['--sku' => "O'BRIEN"])->assertExitCode(0);

        $this->assertSame(["number eq 'O''BRIEN'"], $this->sentFilters());
    }

    // --------------------------------------------------------------- full run

    public function test_full_ignores_the_checkpoint_and_fetches_everything(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);
        $this->fakeCatalogue($this->rows(450, '2026-01-01T00:00:00Z'));

        $this->artisan('bc:import-items', ['--full' => true, '--page-size' => 200])
            ->expectsOutputToContain('Full reconciliation')
            ->assertExitCode(0);

        // No since filter at all, and everything fetched.
        $this->assertSame([null, null, null], $this->sentFilters());
        Queue::assertPushed(ImportBcProduct::class, 450);
    }

    /**
     * The nightly run re-reads old records, so their timestamps are older than
     * the incremental runs have already reached. It must not drag the
     * checkpoint backwards and cause the whole catalogue to be re-fetched.
     */
    public function test_full_never_moves_the_checkpoint_backwards(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);
        $this->fakeCatalogue($this->rows(10, '2026-01-01T00:00:00Z'));

        $this->artisan('bc:import-items', ['--full' => true])
            ->expectsOutputToContain('saw nothing newer')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-05-01T00:00:00+00:00',
            $this->checkpoint()->last_modified_at->toIso8601String(),
        );
    }

    public function test_full_advances_the_checkpoint_when_it_sees_something_newer(): void
    {
        $this->checkpoint()->update(['last_modified_at' => '2026-05-01 00:00:00']);
        $this->fakeCatalogue([$this->row(1, '2026-07-01T00:00:00Z')]);

        $this->artisan('bc:import-items', ['--full' => true])->assertExitCode(0);

        $this->assertSame(
            '2026-07-01T00:00:00+00:00',
            $this->checkpoint()->last_modified_at->toIso8601String(),
        );
    }

    public function test_full_records_when_it_last_ran(): void
    {
        $this->fakeCatalogue($this->rows(5));

        $this->artisan('bc:import-items', ['--full' => true])->assertExitCode(0);

        $this->assertNotNull($this->checkpoint()->last_full_sync_at);
    }

    // ------------------------------------------------------------ checkpoints

    /**
     * Each entity keeps its own position, so products cannot drag quantities
     * forward past records they have not fetched.
     */
    public function test_each_entity_keeps_an_independent_checkpoint(): void
    {
        $this->fakeCatalogue($this->rows(5, '2026-06-01T00:00:00Z'));

        $this->artisan('bc:import-items')->assertExitCode(0);

        $this->assertNotNull(
            SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEMS)->last_modified_at,
        );
        $this->assertNull(
            SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEM_QUANTITIES)->last_modified_at,
        );
    }

    public function test_the_run_statistics_are_recorded(): void
    {
        $this->fakeCatalogue($this->rows(250));

        $this->artisan('bc:import-items', ['--page-size' => 100])->assertExitCode(0);

        $checkpoint = $this->checkpoint();

        $this->assertSame(250, $checkpoint->last_run_rows);
        $this->assertSame(3, $checkpoint->last_run_pages);
        $this->assertNotNull($checkpoint->last_run_at);
    }
}
