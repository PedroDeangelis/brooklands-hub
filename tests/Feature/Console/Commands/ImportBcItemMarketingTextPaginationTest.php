<?php

namespace Tests\Feature\Console\Commands;

use App\BusinessCentral\ItemMarketingTextQuery;
use App\Jobs\ImportBcProductMarketingText;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Paging and checkpointing the Business Central marketing text endpoint.
 *
 * Incremental, unlike the quantity sweep: this page's lastModifiedDateTime is
 * genuine, so a "changed since" fetch sees every edit. These tests pin that the
 * filter is sent and is exclusive, that paging is stable, and that a run which
 * did not see the whole result set cannot move the checkpoint.
 */
class ImportBcItemMarketingTextPaginationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const MARKETING_URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)/marketingTextExt';

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
        Queue::fake([ImportBcProductMarketingText::class]);
    }

    /**
     * A row shaped as Business Central returns it, one minute apart so the
     * incremental filter has something to bite on.
     *
     * @return array<string, mixed>
     */
    private function row(int $n): array
    {
        return [
            'itemId' => sprintf('%08d-3d1c-f111-8341-6045bde65a16', $n),
            'itemNo' => sprintf('SKU%04d', $n),
            'marketingText' => sprintf('Copy for item %d. More detail follows.', $n),
            'lastModifiedDateTime' => CarbonImmutable::parse('2026-04-01T00:00:00Z')
                ->addMinutes($n)
                ->toIso8601ZuluString('millisecond'),
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
     * Serve rows a page at a time, honouring $top, $skip and $filter.
     *
     * The filter is applied rather than ignored, because the incremental
     * behaviour under test is precisely what it excludes.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeEndpoint(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::MARKETING_URL.'*' => function (Request $request) use ($rows) {
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

    private function requestCount(): int
    {
        $count = 0;

        Http::assertSent(function (Request $request) use (&$count): bool {
            if (str_starts_with($request->url(), self::MARKETING_URL)) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    private function checkpoint(): SyncCheckpoint
    {
        return SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEM_MARKETING_TEXT)->refresh();
    }

    /**
     * The $filter values sent, in order.
     *
     * @return array<int, string|null>
     */
    private function filtersSent(): array
    {
        $filters = [];

        Http::assertSent(function (Request $request) use (&$filters): bool {
            if (str_starts_with($request->url(), self::MARKETING_URL)) {
                $filters[] = $request['$filter'] ?? null;
            }

            return true;
        });

        return $filters;
    }

    // ------------------------------------------------------------- the query

    /**
     * Whether a product belongs on the website is decided in Laravel, from data
     * already imported. Filtering here would hide rows from that decision.
     */
    public function test_the_query_carries_no_website_eligibility_filter(): void
    {
        $this->assertArrayNotHasKey('$filter', ItemMarketingTextQuery::page(200));
    }

    /**
     * Timestamps are not unique, so ordering by them alone is partial and $skip
     * would repeat and skip rows as the server broke ties differently.
     */
    public function test_paging_orders_by_timestamp_then_id(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-marketing-text')->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::MARKETING_URL)) {
                return true;
            }

            return $request['$orderby'] === 'lastModifiedDateTime asc,itemId asc';
        });
    }

    public function test_the_query_selects_the_fields_the_import_reads(): void
    {
        $select = ItemMarketingTextQuery::page(200)['$select'];

        foreach (['itemId', 'itemNo', 'marketingText', 'lastModifiedDateTime'] as $field) {
            $this->assertStringContainsString($field, $select);
        }
    }

    public function test_the_single_item_query_filters_on_item_no(): void
    {
        $this->assertSame("itemNo eq 'AA27'", ItemMarketingTextQuery::forSku('AA27')['$filter']);
    }

    /**
     * A single quote ends an OData literal, so one inside the value must be
     * doubled or the request is rejected as malformed.
     */
    public function test_the_single_item_query_escapes_quotes(): void
    {
        $this->assertSame("itemNo eq 'O''BRIEN'", ItemMarketingTextQuery::forSku("O'BRIEN")['$filter']);
    }

    // ---------------------------------------------------------------- paging

    public function test_a_first_run_fetches_everything_and_sends_no_filter(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-marketing-text')
            ->expectsOutputToContain('No checkpoint yet')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductMarketingText::class, 5);
        $this->assertSame([null], $this->filtersSent());
    }

    public function test_more_than_one_page_causes_more_than_one_request(): void
    {
        $this->fakeEndpoint($this->rows(250));

        $this->artisan('bc:import-item-marketing-text', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(2, $this->requestCount());
        Queue::assertPushed(ImportBcProductMarketingText::class, 250);
    }

    public function test_a_full_page_is_followed_by_one_more_request(): void
    {
        $this->fakeEndpoint($this->rows(400));

        $this->artisan('bc:import-item-marketing-text', ['--page-size' => 200])->assertExitCode(0);

        // 200, 200, then a third that comes back empty and ends the run.
        $this->assertSame(3, $this->requestCount());
        Queue::assertPushed(ImportBcProductMarketingText::class, 400);
    }

    // ----------------------------------------------------------- incremental

    public function test_the_checkpoint_advances_to_the_newest_row_fetched(): void
    {
        $rows = $this->rows(5);
        $this->fakeEndpoint($rows);

        $this->artisan('bc:import-item-marketing-text')->assertExitCode(0);

        $this->assertSame(
            CarbonImmutable::parse($rows[4]['lastModifiedDateTime'])->toIso8601ZuluString('millisecond'),
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_a_second_run_sends_the_checkpoint_as_a_since_filter(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-marketing-text')->assertExitCode(0);
        $this->artisan('bc:import-item-marketing-text')->assertExitCode(0);

        $filters = $this->filtersSent();

        $this->assertNull($filters[0]);
        $this->assertStringStartsWith('lastModifiedDateTime gt ', (string) end($filters));
    }

    /**
     * Rows sharing the checkpoint's exact timestamp have already been fetched:
     * the checkpoint only reaches a timestamp once an uncapped run has read its
     * whole result set. An inclusive filter would return the newest row forever.
     */
    public function test_the_since_filter_is_exclusive_of_the_checkpoint(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-marketing-text')->assertExitCode(0);
        Queue::assertPushed(ImportBcProductMarketingText::class, 5);

        // Nothing has changed since, so the second run must queue nothing.
        $this->artisan('bc:import-item-marketing-text')
            ->expectsOutputToContain('No marketing text has changed')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductMarketingText::class, 5);
    }

    /**
     * Milliseconds are kept: flooring to whole seconds would put the filter
     * before the row the checkpoint came from, and "gt" would return it forever.
     */
    public function test_the_since_filter_keeps_milliseconds(): void
    {
        $this->fakeEndpoint($this->rows(5));
        $this->checkpoint()->update(['last_modified_at' => '2026-04-01 00:02:30.123']);

        $this->artisan('bc:import-item-marketing-text')->assertExitCode(0);

        $this->assertSame(
            'lastModifiedDateTime gt 2026-04-01T00:02:30.123Z',
            $this->filtersSent()[0],
        );
    }

    public function test_only_rows_newer_than_the_checkpoint_are_queued(): void
    {
        $this->fakeEndpoint($this->rows(10));
        $this->checkpoint()->update(['last_modified_at' => '2026-04-01 00:07:00.000']);

        $this->artisan('bc:import-item-marketing-text')->assertExitCode(0);

        // Rows 8, 9 and 10 are the only ones after 00:07.
        Queue::assertPushed(ImportBcProductMarketingText::class, 3);
    }

    // ------------------------------------------------------ guarding the mark

    /**
     * A capped run stopped early by design and has not seen the whole result
     * set, so advancing from it would step over rows it never fetched.
     */
    public function test_a_capped_run_does_not_advance_the_checkpoint(): void
    {
        $this->fakeEndpoint($this->rows(10));

        $this->artisan('bc:import-item-marketing-text', ['--top' => 3])
            ->expectsOutputToContain('checkpoint was left unchanged')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductMarketingText::class, 3);
        $this->assertNull($this->checkpoint()->last_modified_at);
    }

    /**
     * A full run re-reads old rows, whose timestamps are older than whatever
     * the incremental runs have already reached.
     */
    public function test_a_full_run_never_drags_the_checkpoint_backwards(): void
    {
        $this->fakeEndpoint($this->rows(5));
        $this->checkpoint()->update(['last_modified_at' => '2026-12-01 00:00:00.000']);

        $this->artisan('bc:import-item-marketing-text', ['--full' => true])
            ->expectsOutputToContain('saw nothing newer')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-12-01T00:00:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    public function test_a_full_run_ignores_the_checkpoint_when_fetching(): void
    {
        $this->fakeEndpoint($this->rows(5));
        $this->checkpoint()->update(['last_modified_at' => '2026-04-01 00:03:00.000']);

        $this->artisan('bc:import-item-marketing-text', ['--full' => true])
            ->expectsOutputToContain('Full reconciliation')
            ->assertExitCode(0);

        $this->assertSame([null], $this->filtersSent());
        Queue::assertPushed(ImportBcProductMarketingText::class, 5);
    }

    /**
     * Whatever was queued has been queued, but the checkpoint must stay put so
     * the next run re-reads rather than stepping over the unfetched pages.
     */
    public function test_a_failed_fetch_leaves_the_checkpoint_alone(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::MARKETING_URL.'*' => Http::response('server exploded', 500),
        ]);

        $this->checkpoint()->update(['last_modified_at' => '2026-04-01 00:03:00.000']);

        $this->artisan('bc:import-item-marketing-text')
            ->expectsOutputToContain('checkpoint was not advanced')
            ->assertExitCode(1);

        $this->assertSame(
            '2026-04-01T00:03:00.000Z',
            $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    // --------------------------------------------------------- manual options

    public function test_a_single_sku_run_queues_only_that_row(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::MARKETING_URL.'*' => Http::response(['value' => [$this->row(1)]]),
        ]);

        $this->artisan('bc:import-item-marketing-text', ['--sku' => 'SKU0001'])
            ->expectsOutputToContain('left unchanged')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductMarketingText::class, 1);
        $this->assertNull($this->checkpoint()->last_modified_at);
    }

    public function test_an_unknown_sku_is_reported_rather_than_failing(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::MARKETING_URL.'*' => Http::response(['value' => []]),
        ]);

        $this->artisan('bc:import-item-marketing-text', ['--sku' => 'NOPE'])
            ->expectsOutputToContain('No Business Central marketing text row')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * A forced run re-delivers what it fetches, so it only means anything when
     * it fetches everything.
     */
    public function test_force_requires_full(): void
    {
        $this->artisan('bc:import-item-marketing-text', ['--force' => true])
            ->expectsOutputToContain('--force requires --full')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /**
     * Declining must fetch nothing at all, not fetch and then decline to
     * deliver: the confirmation is asked before the first request.
     */
    public function test_declining_the_force_confirmation_queues_nothing(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-marketing-text', ['--full' => true, '--force' => true])
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutputToContain('Nothing was fetched or queued')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_force_cannot_be_combined_with_sku(): void
    {
        $this->artisan('bc:import-item-marketing-text', ['--force' => true, '--full' => true, '--sku' => 'AA27'])
            ->expectsOutputToContain('--force cannot be combined with --sku')
            ->assertExitCode(1);
    }

    public function test_force_cannot_be_combined_with_top(): void
    {
        $this->artisan('bc:import-item-marketing-text', ['--force' => true, '--full' => true, '--top' => 5])
            ->expectsOutputToContain('--force cannot be combined with --top')
            ->assertExitCode(1);
    }

    public function test_force_queues_every_row_for_redelivery(): void
    {
        $this->fakeEndpoint($this->rows(5));

        $this->artisan('bc:import-item-marketing-text', ['--full' => true, '--force' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductMarketingText::class, 5);

        Queue::assertPushed(
            ImportBcProductMarketingText::class,
            fn (ImportBcProductMarketingText $job): bool => $job->force === true,
        );
    }
}
