<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcContact;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incremental contact synchronisation.
 *
 * The paging and checkpoint machinery is the same as for customers and is
 * covered there; what is particular to contacts is the Person filter, which
 * has to survive being combined with the incremental one.
 */
class ImportBcContactsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const CONTACTS_URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)/contactsExt';

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
        Queue::fake([ImportBcContact::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $n, string $modified = '2026-05-13T04:56:29.260Z'): array
    {
        return [
            'id' => sprintf('%08d-1a3a-f111-bec2-00224810e61c', $n),
            'number' => sprintf('CT%06d', $n),
            'type' => 'Person',
            'displayName' => "Contact {$n}",
            'lastModifiedDateTime' => $modified,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count, string $modified = '2026-05-13T04:56:29.260Z'): array
    {
        return array_map(fn (int $n): array => $this->row($n, $modified), range(1, $count));
    }

    private function checkpoint(): SyncCheckpoint
    {
        return SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_CONTACTS)->refresh();
    }

    /**
     * Serve rows a page at a time, honouring $top, $skip and the since part
     * of $filter as the API does.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeContacts(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CONTACTS_URL.'*' => function (Request $request) use ($rows) {
                $top = isset($request['$top']) ? (int) $request['$top'] : null;
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
        if ($filter === null || ! preg_match('/lastModifiedDateTime gt (\S+)$/', $filter, $m)) {
            return $rows;
        }

        $since = CarbonImmutable::parse($m[1]);

        return array_filter($rows, fn (array $row): bool => CarbonImmutable::parse($row['lastModifiedDateTime'])->greaterThan($since));
    }

    /**
     * @return array<int, string|null>
     */
    private function sentFilters(): array
    {
        $filters = [];

        Http::assertSent(function (Request $request) use (&$filters): bool {
            if (str_starts_with($request->url(), self::CONTACTS_URL)) {
                $filters[] = $request['$filter'] ?? null;
            }

            return true;
        });

        return $filters;
    }

    public function test_a_first_run_fetches_every_person_contact(): void
    {
        $this->fakeContacts($this->rows(3));

        $this->artisan('bc:import-contacts', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcContact::class, 3);
    }

    /**
     * The one rule that stays in Business Central.
     */
    public function test_every_request_filters_to_person_contacts(): void
    {
        $this->fakeContacts($this->rows(1));

        $this->artisan('bc:import-contacts', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame(["type eq 'Person'"], $this->sentFilters());
    }

    public function test_an_incremental_run_combines_the_person_filter_with_the_checkpoint(): void
    {
        $this->fakeContacts($this->rows(2));
        $this->artisan('bc:import-contacts', ['--page-size' => 200])->assertExitCode(0);

        $this->fakeContacts($this->rows(2));
        $this->artisan('bc:import-contacts', ['--page-size' => 200])->assertExitCode(0);

        $filters = $this->sentFilters();

        $this->assertSame("type eq 'Person' and lastModifiedDateTime gt 2026-05-13T04:56:29.260Z", end($filters));
        Queue::assertPushed(ImportBcContact::class, 2);
    }

    public function test_a_complete_first_sync_saves_the_latest_timestamp_with_milliseconds(): void
    {
        $this->fakeContacts([$this->row(1, '2026-05-13T04:56:29.260Z'), $this->row(2, '2026-05-14T00:00:00.123Z')]);

        $this->artisan('bc:import-contacts', ['--page-size' => 200])->assertExitCode(0);

        $this->assertSame('2026-05-14T00:00:00.123Z', $this->checkpoint()->last_modified_at->toIso8601ZuluString('millisecond'));
    }

    public function test_a_capped_run_never_advances_the_checkpoint(): void
    {
        $this->fakeContacts($this->rows(5));

        $this->artisan('bc:import-contacts', ['--page-size' => 200, '--top' => 2])->assertExitCode(0);

        Queue::assertPushed(ImportBcContact::class, 2);
        $this->assertFalse($this->checkpoint()->isSet());
    }

    public function test_number_asks_business_central_for_that_person_only(): void
    {
        $this->fakeContacts([$this->row(7)]);

        $this->artisan('bc:import-contacts', ['--number' => 'CT000007'])->assertExitCode(0);

        $this->assertSame(["type eq 'Person' and number eq 'CT000007'"], $this->sentFilters());
        Queue::assertPushed(ImportBcContact::class, 1);
        $this->assertFalse($this->checkpoint()->isSet());
    }

    public function test_force_requires_full(): void
    {
        $this->artisan('bc:import-contacts', ['--force' => true])
            ->expectsOutputToContain('--force requires --full')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_force_passes_the_flag_to_every_job(): void
    {
        $this->fakeContacts($this->rows(2));

        $this->artisan('bc:import-contacts', ['--full' => true, '--force' => true, '--no-interaction' => true])->assertExitCode(0);

        Queue::assertPushed(ImportBcContact::class, fn (ImportBcContact $job): bool => $job->force === true);
        Queue::assertPushed(ImportBcContact::class, 2);
    }

    public function test_a_normal_run_does_not_force(): void
    {
        $this->fakeContacts($this->rows(1));

        $this->artisan('bc:import-contacts')->assertExitCode(0);

        Queue::assertPushed(ImportBcContact::class, fn (ImportBcContact $job): bool => $job->force === false);
    }
}
