<?php

namespace App\BusinessCentral;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Business Central custom APIs.
 *
 * Deliberately minimal: no paging, no retry policy and no OData query builder yet.
 * Those arrive with the sync engine.
 */
class BusinessCentralClient
{
    public function __construct(private readonly AccessTokenProvider $tokens) {}

    /**
     * Base URL for a custom (extension) API page.
     *
     * The leading "/v2.0/" segment is fixed and is NOT the configured api_version:
     * only the "api/" segment carries the publisher, group and version of a custom API.
     */
    public function customBaseUrl(string $publisher, string $group, string $version): string
    {
        $url = rtrim($this->requiredConfig('url'), '/');
        $tenantId = $this->requiredConfig('tenant_id');
        $instance = $this->requiredConfig('instance');
        $companyId = $this->requiredConfig('company_id');

        return "{$url}/v2.0/{$tenantId}/{$instance}/api/{$publisher}/{$group}/{$version}/companies({$companyId})";
    }

    /**
     * Base URL for Microsoft's standard API.
     *
     * This is where the configured api_version applies: the standard pages live
     * under "api/{version}" with no publisher or group.
     */
    public function standardBaseUrl(): string
    {
        $url = rtrim($this->requiredConfig('url'), '/');
        $tenantId = $this->requiredConfig('tenant_id');
        $instance = $this->requiredConfig('instance');
        $companyId = $this->requiredConfig('company_id');
        $apiVersion = $this->requiredConfig('api_version');

        return "{$url}/v2.0/{$tenantId}/{$instance}/api/{$apiVersion}/companies({$companyId})";
    }

    /**
     * GET a custom API page and return the decoded body.
     *
     * @param  array<string, scalar>  $query
     * @return array<array-key, mixed>
     *
     * @throws BusinessCentralException on any non-2xx response or connection failure.
     *
     * Failing loudly is load-bearing. A non-2xx response decodes to null, and a caller
     * that reads `value` off null sees an empty page — indistinguishable from "no more
     * rows". The sync would then advance its watermark past records it never fetched.
     */
    public function getCustom(string $publisher, string $group, string $version, string $path, array $query = []): array
    {
        return $this->get($this->customBaseUrl($publisher, $group, $version).'/'.ltrim($path, '/'), $query);
    }

    /**
     * GET a standard API page and return the decoded body.
     *
     * Same semantics as getCustom(), including failing loudly.
     *
     * @param  array<string, scalar>  $query
     * @return array<array-key, mixed>
     *
     * @throws BusinessCentralException on any non-2xx response or connection failure.
     */
    public function getStandard(string $path, array $query = []): array
    {
        return $this->get($this->standardBaseUrl().'/'.ltrim($path, '/'), $query);
    }

    /**
     * GET a media stream by the absolute URL Business Central handed us.
     *
     * The URL is never built here. A media stream is only ever reachable through
     * the "@odata.mediaReadLink" a metadata read returns, which already carries
     * the tenant, company and entity, so composing one from config would be
     * guessing at a URL the server has already given us.
     *
     * Returns the body untouched rather than decoding it: the payload is binary.
     *
     * @throws BusinessCentralException on any non-2xx response or connection failure.
     */
    public function getMedia(string $mediaReadLink): MediaContent
    {
        try {
            $response = Http::withToken($this->tokens->token())
                ->timeout((int) config('services.bc.http_timeout'))
                ->connectTimeout((int) config('services.bc.http_connect_timeout'))
                ->get($mediaReadLink);
        } catch (ConnectionException $e) {
            throw BusinessCentralException::requestFailed($mediaReadLink, null, $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw BusinessCentralException::requestFailed($mediaReadLink, $response->status(), $response->body());
        }

        return new MediaContent($response->body(), $response->header('Content-Type') ?: null);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<array-key, mixed>
     */
    private function get(string $url, array $query): array
    {
        try {
            $response = Http::withToken($this->tokens->token())
                ->acceptJson()
                ->timeout((int) config('services.bc.http_timeout'))
                ->connectTimeout((int) config('services.bc.http_connect_timeout'))
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw BusinessCentralException::requestFailed($url, null, $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw BusinessCentralException::requestFailed($url, $response->status(), $response->body());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw BusinessCentralException::requestFailed($url, $response->status(), $response->body());
        }

        return $decoded;
    }

    private function requiredConfig(string $key): string
    {
        $value = (string) config("services.bc.{$key}");

        if ($value === '') {
            throw BusinessCentralException::missingConfiguration("services.bc.{$key}");
        }

        return $value;
    }
}
