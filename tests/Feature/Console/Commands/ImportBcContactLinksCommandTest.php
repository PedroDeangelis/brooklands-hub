<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcContactLink;
use App\Models\Contact;
use App\Models\SyncCheckpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The contact link sweep. Every run reads the whole standard-API page;
 * nothing here is incremental, so the assertions are about coverage, the
 * standard API base, and unlinking what the sweep no longer sees.
 */
class ImportBcContactLinksCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/v2.0/companies(company-guid)/customerContacts';

    private const CUSTOMER = '136ebb7f-791e-f111-8340-7ced8d3493eb';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com', 'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc', 'client_secret' => 'secret-abc', 'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid', 'api_version' => 'v2.0', 'http_timeout' => 30, 'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Queue::fake([ImportBcContactLink::class]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count): array
    {
        return array_map(fn (int $n): array => [
            'id' => sprintf('%08d-1a3a-f111-bec2-00224810e61c', $n),
            'customerId' => self::CUSTOMER,
            'customerName' => 'Brooklands Staff Sales',
            'email' => "contact{$n}@example.com",
        ], range(1, $count));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fake(array $rows): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::URL.'*' => fn (Request $request) => Http::response([
                'value' => array_slice(
                    self::applyIdFilter($rows, $request['$filter'] ?? null),
                    (int) ($request['$skip'] ?? 0),
                    isset($request['$top']) ? (int) $request['$top'] : null,
                ),
            ]),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function applyIdFilter(array $rows, ?string $filter): array
    {
        if ($filter === null || ! preg_match('/^id eq (\S+)$/', $filter, $m)) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => $row['id'] === $m[1]));
    }

    public function test_it_reads_the_standard_api(): void
    {
        $this->fake($this->rows(1));

        $this->artisan('bc:import-contact-links', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), self::URL) || str_starts_with($r->url(), self::TOKEN_URL));
        Queue::assertPushed(ImportBcContactLink::class, 1);
    }

    public function test_a_complete_sweep_queues_every_row_across_pages(): void
    {
        $this->fake($this->rows(450));

        $this->artisan('bc:import-contact-links', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcContactLink::class, 450);
        Queue::assertPushed(ImportBcContactLink::class, fn (ImportBcContactLink $job): bool => $job->row['customerId'] === self::CUSTOMER);
    }

    /**
     * A contact detached in Business Central no longer appears on the page,
     * so the only way its link can go is for the sweep to notice its absence.
     */
    public function test_a_complete_sweep_unlinks_contacts_it_did_not_see(): void
    {
        $seen = Contact::factory()->create(['bc_id' => '00000001-1a3a-f111-bec2-00224810e61c', 'customer_bc_id' => self::CUSTOMER]);
        $gone = Contact::factory()->create(['customer_bc_id' => self::CUSTOMER]);
        Contact::factory()->create(['customer_bc_id' => null]);

        $this->fake($this->rows(1));

        $this->artisan('bc:import-contact-links', ['--page-size' => 200])
            ->expectsOutputToContain('1 contact(s) are no longer linked')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcContactLink::class, 2);
        Queue::assertPushed(
            ImportBcContactLink::class,
            fn (ImportBcContactLink $job): bool => $job->row['id'] === $gone->bc_id && $job->row['customerId'] === '',
        );
        Queue::assertNotPushed(
            ImportBcContactLink::class,
            fn (ImportBcContactLink $job): bool => $job->row['id'] === $seen->bc_id && $job->row['customerId'] === '',
        );
    }

    /**
     * A capped run saw only part of the page and must not unlink anything.
     */
    public function test_a_capped_run_never_unlinks(): void
    {
        Contact::factory()->create(['customer_bc_id' => self::CUSTOMER]);
        $this->fake($this->rows(5));

        $this->artisan('bc:import-contact-links', ['--page-size' => 200, '--top' => 3])->assertExitCode(0);

        Queue::assertPushed(ImportBcContactLink::class, 3);
        Queue::assertNotPushed(ImportBcContactLink::class, fn (ImportBcContactLink $job): bool => $job->row['customerId'] === '');
        $this->assertNull(SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_CONTACT_LINKS)->last_run_at);
    }

    public function test_a_complete_sweep_is_recorded(): void
    {
        $this->fake($this->rows(2));

        $this->artisan('bc:import-contact-links', ['--page-size' => 200])->assertExitCode(0);

        $checkpoint = SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_CONTACT_LINKS)->refresh();

        $this->assertNotNull($checkpoint->last_full_sync_at);
        $this->assertSame(2, $checkpoint->last_run_rows);
    }

    public function test_contact_fetches_that_one_link_by_the_contacts_id(): void
    {
        Contact::factory()->create(['bc_id' => '00000002-1a3a-f111-bec2-00224810e61c', 'number' => 'CT000002']);
        $this->fake($this->rows(3));

        $this->artisan('bc:import-contact-links', ['--contact' => 'CT000002'])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => ! str_starts_with($r->url(), self::URL)
            || $r['$filter'] === 'id eq 00000002-1a3a-f111-bec2-00224810e61c');
        Queue::assertPushed(ImportBcContactLink::class, 1);
        Queue::assertPushed(ImportBcContactLink::class, fn (ImportBcContactLink $job): bool => $job->row['id'] === '00000002-1a3a-f111-bec2-00224810e61c');
    }

    public function test_contact_with_no_link_in_business_central_unlinks(): void
    {
        Contact::factory()->create(['bc_id' => '00000009-1a3a-f111-bec2-00224810e61c', 'number' => 'CT000009', 'customer_bc_id' => self::CUSTOMER]);
        $this->fake($this->rows(3));

        $this->artisan('bc:import-contact-links', ['--contact' => 'CT000009'])
            ->expectsOutputToContain('unlinking')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcContactLink::class, fn (ImportBcContactLink $job): bool => $job->row['customerId'] === '');
    }

    public function test_contact_that_has_not_been_imported_is_explained(): void
    {
        $this->artisan('bc:import-contact-links', ['--contact' => 'NOPE'])
            ->expectsOutputToContain('import it first')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_force_cannot_be_combined_with_top(): void
    {
        $this->artisan('bc:import-contact-links', ['--force' => true, '--top' => 1])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_force_passes_the_flag_to_every_job(): void
    {
        $this->fake($this->rows(2));

        $this->artisan('bc:import-contact-links', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);

        Queue::assertPushed(ImportBcContactLink::class, fn (ImportBcContactLink $job): bool => $job->force === true);
    }
}
