<?php

namespace Tests\Feature\BusinessCentral;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessCentralClientTest extends TestCase
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

    public function test_builds_the_custom_api_url_from_configuration(): void
    {
        $url = app(BusinessCentralClient::class)->customBaseUrl('brooklands', 'catalog', 'v1.0');

        $this->assertSame(
            'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
                .'/api/brooklands/catalog/v1.0/companies(company-guid)',
            $url,
        );
    }

    public function test_ignores_the_configured_api_version_for_custom_api_urls(): void
    {
        // Custom APIs live under /v2.0/ regardless of api_version; only the "api/"
        // segment carries the publisher, group and version.
        config()->set('services.bc.api_version', 'v9.9');

        $url = app(BusinessCentralClient::class)->customBaseUrl('brooklands', 'catalog', 'v1.0');

        $this->assertStringContainsString('/v2.0/tenant-abc/', $url);
        $this->assertStringNotContainsString('v9.9', $url);
    }

    public function test_sends_the_bearer_token_and_query_parameters_to_the_custom_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [['number' => 'KR29']]]),
        ]);

        $result = app(BusinessCentralClient::class)
            ->getCustom('brooklands', 'catalog', 'v1.0', 'itemsExt', ['$top' => 1, '$select' => 'id,number']);

        $this->assertSame([['number' => 'KR29']], $result['value']);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::ITEMS_URL)
                && $request->hasHeader('Authorization', 'Bearer tok-123')
                && $request['$top'] === 1
                && $request['$select'] === 'id,number';
        });
    }

    public function test_throws_instead_of_returning_an_empty_page_when_business_central_fails(): void
    {
        // Load-bearing: a silent null here would look like "no more rows" to a paging
        // loop, which would then advance its watermark past records it never fetched.
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response('server exploded', 500),
        ]);

        try {
            app(BusinessCentralClient::class)->getCustom('brooklands', 'catalog', 'v1.0', 'itemsExt');
            $this->fail('Expected a BusinessCentralException.');
        } catch (BusinessCentralException $e) {
            $this->assertSame(500, $e->status);
            $this->assertStringContainsString('server exploded', (string) $e->bodyExcerpt);
        }
    }

    public function test_throws_when_business_central_rejects_the_token(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(BusinessCentralException::class);

        app(BusinessCentralClient::class)->getCustom('brooklands', 'catalog', 'v1.0', 'itemsExt');
    }

    public function test_throws_when_business_central_cannot_be_reached(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::failedConnection(),
        ]);

        $this->expectException(BusinessCentralException::class);

        app(BusinessCentralClient::class)->getCustom('brooklands', 'catalog', 'v1.0', 'itemsExt');
    }

    public function test_throws_when_a_required_configuration_value_is_missing(): void
    {
        config()->set('services.bc.company_id', null);

        $this->expectException(BusinessCentralException::class);

        app(BusinessCentralClient::class)->customBaseUrl('brooklands', 'catalog', 'v1.0');
    }
}
