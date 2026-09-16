<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class TestBusinessCentralItemsCommandTest extends TestCase
{
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

    public function test_prints_the_single_item_returned_by_business_central(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->item()]]),
        ]);

        // expectsOutputToContain consumes one written line per expectation, and the
        // whole item prints as a single JSON write, so assert the payload once here
        // and check the individual fields on the decoded output below.
        $this->artisan('bc:test-items')
            ->expectsOutputToContain('"number": "KR29"')
            ->assertExitCode(0);
    }

    public function test_prints_the_item_as_pretty_printed_json_including_nested_collections(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->item()]]),
        ]);

        $output = $this->runCommandAndCaptureOutput();

        $this->assertStringContainsString('Business Central item:', $output);

        $json = mb_substr($output, (int) mb_strpos($output, '{'));
        $decoded = json_decode(trim($json), true);

        $this->assertSame($this->item(), $decoded);
        $this->assertStringContainsString('BROOKLANDS', $output);
    }

    private function runCommandAndCaptureOutput(): string
    {
        $output = new BufferedOutput;

        $exitCode = app(Kernel::class)->handle(
            new ArrayInput(['command' => 'bc:test-items']),
            $output,
        );

        $this->assertSame(0, $exitCode);

        return $output->fetch();
    }

    public function test_requests_a_single_row_with_the_expanded_collections_the_sync_needs(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->item()]]),
        ]);

        $this->artisan('bc:test-items')->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::ITEMS_URL)) {
                return false;
            }

            return $request['$top'] === 1
                && $request['$expand'] === 'priceListLines,itemDefaultDimensions,itemAttributes,stockkeepingUnits'
                && str_contains((string) $request['$select'], 'displayName2')
                && str_contains((string) $request['$filter'], "gppg eq 'FINISHED GOODS'");
        });
    }

    public function test_warns_without_failing_when_no_item_matches_the_filter(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => []]),
        ]);

        $this->artisan('bc:test-items')
            ->expectsOutputToContain('No items returned')
            ->assertExitCode(0);
    }

    public function test_reports_the_status_and_exits_non_zero_when_business_central_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response('gateway timeout', 504),
        ]);

        $this->artisan('bc:test-items')
            ->expectsOutputToContain('HTTP 504')
            ->assertExitCode(1);
    }

    public function test_reports_a_failure_when_the_credentials_are_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        $this->artisan('bc:test-items')
            ->expectsOutputToContain('token request failed')
            ->assertExitCode(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(): array
    {
        return [
            'id' => '5c2a6710-4b43-f111-a820-7c1e52630c00',
            'number' => 'KR29',
            'displayName' => 'Brake Rotor',
            'unitPrice' => 11.95,
            'type' => 'Inventory',
            'lastModifiedDateTime' => '2026-08-20T10:00:00.000Z',
            'priceListLines' => [['salesCode' => 'RRP', 'unitPrice' => 14.95]],
            'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 40]],
        ];
    }
}
