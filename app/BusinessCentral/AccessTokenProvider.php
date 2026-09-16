<?php

namespace App\BusinessCentral;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Obtains and caches an OAuth2 client-credentials access token for Business Central.
 */
class AccessTokenProvider
{
    /**
     * Business Central only issues tokens for this scope under client credentials.
     */
    private const SCOPE = 'https://api.businesscentral.dynamics.com/.default';

    /**
     * Seconds shaved off the token's advertised lifetime so an in-flight request
     * cannot be made with a token that expires mid-call.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    private const MINIMUM_TTL_SECONDS = 60;

    public function token(): string
    {
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $ttl] = $this->requestToken();

        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * The key is namespaced by instance and client so a Sandbox token can never be
     * served to a Production call (or vice versa) when the two share a cache store.
     */
    public function cacheKey(): string
    {
        return sprintf(
            'bc:token:%s:%s',
            (string) config('services.bc.instance'),
            (string) config('services.bc.client_id'),
        );
    }

    public function tokenUrl(): string
    {
        $tenantId = (string) config('services.bc.tenant_id');

        if ($tenantId === '') {
            throw BusinessCentralException::missingConfiguration('services.bc.tenant_id');
        }

        return "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
    }

    /**
     * @return array{0: string, 1: int} The token and the seconds it may be cached for.
     */
    private function requestToken(): array
    {
        $url = $this->tokenUrl();

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.bc.http_timeout'))
                ->connectTimeout((int) config('services.bc.http_connect_timeout'))
                ->post($url, [
                    'grant_type' => 'client_credentials',
                    'client_id' => (string) config('services.bc.client_id'),
                    'client_secret' => (string) config('services.bc.client_secret'),
                    'scope' => self::SCOPE,
                ]);
        } catch (ConnectionException $e) {
            throw BusinessCentralException::tokenRequestFailed($url, null, $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw BusinessCentralException::tokenRequestFailed($url, $response->status(), $response->body());
        }

        $token = $response->json('access_token');

        // An empty token must never be cached: the old app cached '' for ~an hour and
        // every subsequent call failed with a confusing 401.
        if (! is_string($token) || $token === '') {
            throw BusinessCentralException::tokenMissing($url, $response->body());
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        $ttl = max($expiresIn - self::EXPIRY_MARGIN_SECONDS, self::MINIMUM_TTL_SECONDS);

        return [$token, $ttl];
    }
}
