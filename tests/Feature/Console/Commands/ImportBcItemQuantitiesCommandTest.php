<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcProductQuantity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportBcItemQuantitiesCommandTest extends TestCase
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
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '798c5fa0-3d1c-f111-8341-6045bde65a16',
            'number' => 'AA27',
            'type' => 'Inventory',
            'inventory' => 42,
            'qtyOnSalesOrder' => 8,
            'qtyOnPurchOrder' => 2210,
            'qtyOnTransferOrder' => 0,
            'lastModifiedDateTime' => '2026-05-06T01:56:04.173Z',
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeBusinessCentral(array $rows): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::QUANTITIES_URL.'*' => Http::response(['value' => $rows]),
        ]);
    }

    public function test_dispatches_one_import_job_per_quantity_row(): void
    {
        Queue::fake([ImportBcProductQuantity::class]);
        $this->fakeBusinessCentral([$this->row(), $this->row(['id' => 'other-guid', 'number' => 'SF180'])]);

        $this->artisan('bc:import-item-quantities', ['--top' => 2])
            ->expectsOutputToContain('Queued 2 row(s)')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProductQuantity::class, 2);
        Queue::assertPushed(ImportBcProductQuantity::class, function (ImportBcProductQuantity $job): bool {
            return $job->row['number'] === 'AA27' && $job->row['inventory'] === 42;
        });
    }

    public function test_it_does_not_store_the_quantity_itself(): void
    {
        // Normalisation and persistence belong to the job, not the command.
        Queue::fake([ImportBcProductQuantity::class]);
        $this->fakeBusinessCentral([$this->row()]);

        $this->artisan('bc:import-item-quantities', ['--top' => 1])->assertExitCode(0);

        $this->assertDatabaseCount('product_quantities', 0);
    }

    public function test_it_reads_the_quantities_page_with_the_shared_query(): void
    {
        Queue::fake([ImportBcProductQuantity::class]);
        $this->fakeBusinessCentral([$this->row()]);

        $this->artisan('bc:import-item-quantities', ['--top' => 1])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::QUANTITIES_URL)
                && str_contains((string) $request['$select'], 'qtyOnTransferOrder')
                && str_contains((string) $request['$select'], 'type')
                // Ordered by id: the only total ordering this page offers,
                // since lastModifiedDateTime is unset on most rows.
                && $request['$orderby'] === 'id asc'
                // No website eligibility filtering in the query.
                && ! isset($request['$filter']);
        });
    }

    public function test_it_reports_when_business_central_returns_nothing(): void
    {
        Queue::fake([ImportBcProductQuantity::class]);
        $this->fakeBusinessCentral([]);

        $this->artisan('bc:import-item-quantities', ['--top' => 1])
            ->expectsOutputToContain('No quantity rows returned')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_non_positive_top(): void
    {
        Http::preventStrayRequests();

        $this->artisan('bc:import-item-quantities', ['--top' => 0])
            ->expectsOutputToContain('--top must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_it_reports_a_business_central_failure(): void
    {
        Queue::fake([ImportBcProductQuantity::class]);
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::QUANTITIES_URL.'*' => Http::response('server exploded', 500),
        ]);

        $this->artisan('bc:import-item-quantities', ['--top' => 1])->assertExitCode(1);

        Queue::assertNothingPushed();
    }
}
