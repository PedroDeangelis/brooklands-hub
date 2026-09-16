<?php

namespace Tests\Feature\BusinessCentral;

use App\BusinessCentral\AccessTokenProvider;
use App\BusinessCentral\BusinessCentralException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessTokenProviderTest extends TestCase
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml defines no BC_* variables, so config is null by default.
        // Setting it here also keeps the suite independent of a developer's .env.
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

    public function test_requests_a_client_credentials_token_for_the_business_central_scope(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599])]);

        $token = app(AccessTokenProvider::class)->token();

        $this->assertSame('tok-123', $token);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::TOKEN_URL
                && $request->isForm()
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'client-abc'
                && $request['client_secret'] === 'secret-abc'
                && $request['scope'] === 'https://api.businesscentral.dynamics.com/.default';
        });
    }

    public function test_caches_the_token_so_a_second_call_makes_no_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599])]);

        $provider = app(AccessTokenProvider::class);
        $provider->token();
        $provider->token();

        Http::assertSentCount(1);
    }

    public function test_caches_the_token_for_the_advertised_lifetime_minus_a_safety_margin(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599])]);

        $this->freezeTime();

        $provider = app(AccessTokenProvider::class);
        $provider->token();

        // Still cached one second before expiry - margin.
        $this->travel(3538)->seconds();
        $provider->token();
        Http::assertSentCount(1);

        // Expired: a fresh token is requested.
        $this->travel(2)->seconds();
        $provider->token();
        Http::assertSentCount(2);
    }

    public function test_scopes_the_cache_key_to_the_instance_so_sandbox_and_production_never_share_a_token(): void
    {
        $sandboxKey = app(AccessTokenProvider::class)->cacheKey();

        config()->set('services.bc.instance', 'Production');
        $productionKey = app(AccessTokenProvider::class)->cacheKey();

        $this->assertNotSame($sandboxKey, $productionKey);
        $this->assertStringContainsString('Sandbox_Test', $sandboxKey);
        $this->assertStringContainsString('Production', $productionKey);
    }

    public function test_throws_and_caches_nothing_when_the_response_has_no_access_token(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response(['expires_in' => 3599])]);

        $provider = app(AccessTokenProvider::class);

        $this->expectException(BusinessCentralException::class);

        try {
            $provider->token();
        } finally {
            $this->assertNull(Cache::get($provider->cacheKey()));
        }
    }

    public function test_throws_when_the_token_endpoint_rejects_the_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        try {
            app(AccessTokenProvider::class)->token();
            $this->fail('Expected a BusinessCentralException.');
        } catch (BusinessCentralException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('invalid_client', (string) $e->bodyExcerpt);
        }
    }

    public function test_throws_when_the_token_endpoint_cannot_be_reached(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::failedConnection()]);

        $this->expectException(BusinessCentralException::class);

        app(AccessTokenProvider::class)->token();
    }
}
