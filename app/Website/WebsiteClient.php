<?php

namespace App\Website;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Posts one delivery to the website and reports what came back.
 *
 * The only place that knows the website is reached over HTTP. It never throws
 * on a response status and never decides what a failure means for the ledger;
 * it classifies the response and hands it back.
 *
 * Nothing about the current WordPress implementation is assumed. The endpoint
 * is configuration, and the body is the contract defined by WebsiteRequest.
 */
class WebsiteClient
{
    public function __construct(private readonly WebsiteSigner $signer) {}

    /**
     * Statuses that mean "the request was wrong", rather than "try later".
     *
     * 408 and 429 are 4xx but explicitly ask for a retry, so they are excluded.
     */
    private const RETRYABLE_CLIENT_ERRORS = [408, 429];

    public function deliver(WebsiteRequest $request): WebsiteResponse
    {
        $url = $this->endpoint();

        // Sign the exact bytes that are sent. withBody() posts this string
        // verbatim, so the receiver verifies against what it actually received
        // rather than against a re-encoding of the decoded body.
        $raw = $this->signer->encode($request->body);

        try {
            $response = Http::acceptJson()
                ->withHeaders($this->signer->headers($raw))
                ->timeout((int) config('services.website.timeout'))
                ->connectTimeout((int) config('services.website.connect_timeout'))
                // A followed redirect would turn a signed POST into a GET and a
                // 200 that delivered nothing.
                ->withoutRedirecting()
                ->withBody($raw, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            // The website was never reached, so nothing was delivered and
            // trying again is reasonable.
            return WebsiteResponse::transient(null, $e->getMessage(), exception: $e);
        }

        return $this->classify($response);
    }

    /**
     * Turn an HTTP response into a delivery outcome.
     *
     * A 2xx says the request was understood, not that the website changed. Only
     * an explicit applied=true means the desired state is now in place; that is
     * what the ledger records as delivered, so it must be the website's own
     * claim rather than something inferred from a status code.
     *
     * A removal is deliberately not special-cased. The website is expected to
     * report applied=true when a product is already absent, because removing
     * something that is not there has already achieved what was asked. See the
     * note on idempotency in DeliverProductToWebsite.
     */
    private function classify(Response $response): WebsiteResponse
    {
        $status = $response->status();
        $body = $this->decode($response);

        if ($response->successful()) {
            return ($body['applied'] ?? false) === true
                ? WebsiteResponse::delivered($status, $body)
                : WebsiteResponse::accepted($status, $body);
        }

        // A partial that found nothing to change. Checked before the identity
        // contract because both answer 409 and they mean different things.
        if ($status === 409 && ($body['full_sync_required'] ?? false) === true) {
            return WebsiteResponse::fullSyncRequired(
                $status,
                (string) ($body['error'] ?? 'The website has no record of this product.'),
                $body,
            );
        }

        $conflicts = $this->conflictsFrom($status, $body);

        if ($conflicts !== null) {
            return WebsiteResponse::conflict($status, $conflicts, $body);
        }

        $error = $this->errorFrom($response, $body);

        if ($response->serverError() || in_array($status, self::RETRYABLE_CLIENT_ERRORS, true)) {
            return WebsiteResponse::transient($status, $error, $body);
        }

        // Any other 4xx, and any redirect we refused to follow: repeating the
        // same request would produce the same answer.
        return WebsiteResponse::permanent($status, $error, $body);
    }

    /**
     * Read an identity conflict, but only when the response matches our contract.
     *
     * A bare 409 from a proxy, a cache or an unrelated plugin is not a conflict
     * report: treating it as one would park a record in a state that waits for a
     * human when the real answer may be to retry. The contract is deliberately
     * narrow — 409, conflict=true, and a non-empty conflicts list.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>|null
     */
    private function conflictsFrom(int $status, array $body): ?array
    {
        if ($status !== 409 || ($body['conflict'] ?? false) !== true) {
            return null;
        }

        $conflicts = $body['conflicts'] ?? null;

        if (! is_array($conflicts) || $conflicts === []) {
            return null;
        }

        $valid = [];

        foreach ($conflicts as $conflict) {
            // Each entry must at least name what collided, or it explains nothing.
            if (is_array($conflict) && isset($conflict['code'], $conflict['field'])) {
                $valid[] = $conflict;
            }
        }

        return $valid === [] ? null : $valid;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorFrom(Response $response, array $body): string
    {
        foreach (['error', 'message'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $response->body() === '' ? 'empty response body' : $response->body();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function endpoint(): string
    {
        $url = trim((string) config('services.website.url'));

        if ($url === '') {
            throw WebsiteConfigurationException::missing('services.website.url');
        }

        return $url;
    }
}
