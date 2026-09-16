<?php

namespace Tests\Feature\Console\Commands;

use App\Jobs\ImportBcProduct;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportBcItemsCommandTest extends TestCase
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
    }

    public function test_dispatches_one_import_job_for_the_fetched_item(): void
    {
        Queue::fake([ImportBcProduct::class]);
        $this->fakeBusinessCentral([$this->row()]);

        $this->artisan('bc:import-items', ['--top' => 1])
            ->expectsOutputToContain('Queued POLY1')
            ->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, function (ImportBcProduct $job): bool {
            return $job->row['id'] === 'ab3349b2-3d1c-f111-8341-6045bde65a16'
                && $job->row['number'] === 'POLY1';
        });
    }

    public function test_does_not_save_the_product_itself(): void
    {
        // Normalisation and persistence belong to the job, not the command.
        Queue::fake([ImportBcProduct::class]);
        $this->fakeBusinessCentral([$this->row()]);

        $this->artisan('bc:import-items', ['--top' => 1])->assertExitCode(0);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_passes_the_top_option_through_to_business_central(): void
    {
        Queue::fake([ImportBcProduct::class]);
        $this->fakeBusinessCentral([$this->row(), $this->row('OTHER1', 'bb3349b2-3d1c-f111-8341-6045bde65a16')]);

        $this->artisan('bc:import-items', ['--top' => 2])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::ITEMS_URL)
                && $request['$top'] === 2;
        });

        Queue::assertPushed(ImportBcProduct::class, 2);
    }

    public function test_forwards_the_complete_row_including_nested_collections_to_the_job(): void
    {
        Queue::fake([ImportBcProduct::class]);
        $this->fakeBusinessCentral([$this->row()]);

        $this->artisan('bc:import-items', ['--top' => 1])->assertExitCode(0);

        Queue::assertPushed(ImportBcProduct::class, function (ImportBcProduct $job): bool {
            return $job->row['itemDefaultDimensions'][0]['dimensionCode'] === 'DEPARTMENT';
        });
    }

    public function test_warns_and_dispatches_nothing_when_no_item_matches(): void
    {
        Queue::fake([ImportBcProduct::class]);
        $this->fakeBusinessCentral([]);

        $this->artisan('bc:import-items', ['--top' => 1])
            ->expectsOutputToContain('No items returned')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_rejects_a_non_positive_top_option(): void
    {
        Queue::fake([ImportBcProduct::class]);

        $this->artisan('bc:import-items', ['--top' => 0])
            ->expectsOutputToContain('--top must be a positive integer')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_exits_non_zero_and_dispatches_nothing_when_business_central_fails(): void
    {
        Queue::fake([ImportBcProduct::class]);
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response('gateway timeout', 504),
        ]);

        $this->artisan('bc:import-items', ['--top' => 1])
            ->expectsOutputToContain('HTTP 504')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeBusinessCentral(array $rows): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => $rows]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sku = 'POLY1', string $id = 'ab3349b2-3d1c-f111-8341-6045bde65a16'): array
    {
        return [
            'id' => $id,
            'number' => $sku,
            'displayName' => 'Tropical Fish 1 Poly Bin with Lid',
            'type' => 'Inventory',
            'unitPrice' => 2,
            'lastModifiedDateTime' => '2026-03-12T11:06:22.503Z',
            'itemDefaultDimensions' => [['dimensionCode' => 'DEPARTMENT']],
        ];
    }
}
