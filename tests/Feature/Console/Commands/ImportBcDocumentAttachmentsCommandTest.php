<?php

namespace Tests\Feature\Console\Commands;

use App\DocumentAttachments\AttachmentParentType;
use App\Jobs\ImportBcDocumentAttachment;
use App\Models\DocumentAttachment;
use App\Models\SyncCheckpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The document attachment sweep.
 *
 * Every run reads the whole endpoint, so the assertions are about coverage and
 * grouping rather than about a watermark. The grouping is what matters most: a
 * complete sweep hands each parent its whole set, because only a whole-set
 * write can drop a file deleted in Business Central.
 */
class ImportBcDocumentAttachmentsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    /** Standard API, not a custom page: no publisher or group in the path. */
    private const URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/v2.0/companies(company-guid)/documentAttachments';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com', 'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc', 'client_secret' => 'secret-abc', 'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid', 'api_version' => 'v2.0', 'http_timeout' => 30, 'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Queue::fake([ImportBcDocumentAttachment::class]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count, string $parentType = 'Item'): array
    {
        return array_map(fn (int $n): array => [
            'id' => sprintf('%08d-0000-0000-0000-000000000001', $n),
            'fileName' => "document-{$n}.pdf",
            'parentId' => 'aaaaaaaa-0000-0000-0000-000000000001',
            'parentType' => $parentType,
            'lastModifiedDateTime' => '2026-04-19T22:00:32.733Z',
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
                'value' => array_slice($rows, (int) ($request['$skip'] ?? 0), isset($request['$top']) ? (int) $request['$top'] : null),
            ]),
        ]);
    }

    public function test_it_reads_the_standard_api(): void
    {
        $this->fake($this->rows(1));

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), self::URL) || str_starts_with($r->url(), self::TOKEN_URL));
        Queue::assertPushed(ImportBcDocumentAttachment::class, 1);
    }

    /**
     * Only the three parent types the website can store are asked for. Without
     * this filter the sweep would pull every attachment in the company —
     * purchase documents, journals, resources — to queue rows nothing can place.
     */
    public function test_it_asks_only_for_the_parent_types_it_can_place(): void
    {
        $this->fake($this->rows(1));

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200])->assertExitCode(0);

        Http::assertSent(function (Request $r): bool {
            if (! str_starts_with($r->url(), self::URL)) {
                return true;
            }

            $filter = (string) ($r['$filter'] ?? '');

            return str_contains($filter, "parentType eq 'Item'")
                && str_contains($filter, "parentType eq 'Customer'")
                && str_contains($filter, "parentType eq 'Sales Order'");
        });
    }

    public function test_a_complete_sweep_hands_each_parent_its_whole_set(): void
    {
        $this->fake($this->rows(450));

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcDocumentAttachment::class, 1);
        Queue::assertPushed(
            ImportBcDocumentAttachment::class,
            fn (ImportBcDocumentAttachment $job): bool => $job->replaceAll === true
                && $job->row['parentType'] === AttachmentParentType::Product->value
                && count($job->row['rows']) === 450,
        );
    }

    /**
     * A Business Central id is only unique within a parent type, so two records
     * of different types sharing one must never be grouped together.
     */
    public function test_it_groups_by_parent_type_as_well_as_id(): void
    {
        $rows = $this->rows(2);
        $rows[1]['parentType'] = 'Customer';
        $this->fake($rows);

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(ImportBcDocumentAttachment::class, 2);
    }

    /**
     * A capped run saw only part of the endpoint. Replacing a set from it would
     * delete every file whose row fell outside the cap.
     */
    public function test_a_capped_run_stays_add_only_per_row(): void
    {
        $this->fake($this->rows(5));

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200, '--top' => 3])->assertExitCode(0);

        Queue::assertPushed(ImportBcDocumentAttachment::class, 3);
        Queue::assertPushed(
            ImportBcDocumentAttachment::class,
            fn (ImportBcDocumentAttachment $job): bool => $job->replaceAll === false,
        );
    }

    /**
     * A record whose every file was deleted no longer appears in the sweep at
     * all, so nothing in the fetched rows would ever clear it.
     */
    public function test_it_clears_a_parent_that_no_longer_has_any_attachment(): void
    {
        DocumentAttachment::factory()->create([
            'parent_type' => AttachmentParentType::Product->value,
            'parent_bc_id' => 'eeeeeeee-0000-0000-0000-000000000009',
        ]);

        $this->fake($this->rows(1));

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200])->assertExitCode(0);

        Queue::assertPushed(
            ImportBcDocumentAttachment::class,
            fn (ImportBcDocumentAttachment $job): bool => $job->replaceAll === true
                && $job->row['parentId'] === 'eeeeeeee-0000-0000-0000-000000000009'
                && $job->row['rows'] === [],
        );
    }

    public function test_it_ignores_a_parent_type_it_cannot_place(): void
    {
        $rows = $this->rows(2);
        $rows[1]['parentType'] = 'Purchase Invoice';
        $this->fake($rows);

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200])
            ->expectsOutputToContain('does not store')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcDocumentAttachment::class, 1);
    }

    /**
     * There is no watermark to advance — every run is full — but the run
     * timestamps are what makes a stalled sweep visible.
     */
    public function test_it_records_the_run_without_setting_a_watermark(): void
    {
        $this->fake($this->rows(2));

        $this->artisan('bc:import-document-attachments', ['--page-size' => 200])->assertExitCode(0);

        $checkpoint = SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_DOCUMENT_ATTACHMENTS);

        $this->assertFalse($checkpoint->isSet());
        $this->assertNotNull($checkpoint->last_run_at);
        $this->assertSame(2, $checkpoint->last_run_rows);
    }

    public function test_force_is_refused_alongside_a_cap(): void
    {
        $this->artisan('bc:import-document-attachments', ['--force' => true, '--top' => 5])
            ->expectsOutputToContain('--force cannot be combined with --top')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_one_parent_can_be_fetched_on_its_own(): void
    {
        $this->fake($this->rows(3));

        $this->artisan('bc:import-document-attachments', ['--parent' => 'aaaaaaaa-0000-0000-0000-000000000001'])
            ->assertExitCode(0);

        Queue::assertPushed(
            ImportBcDocumentAttachment::class,
            fn (ImportBcDocumentAttachment $job): bool => $job->replaceAll === true
                && count($job->row['rows']) === 3,
        );
    }
}
