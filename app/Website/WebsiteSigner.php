<?php

namespace App\Website;

/**
 * Signs a delivery so the website can prove it came from here.
 *
 * The one place a signature is produced. Everything else asks this class, so
 * there is a single definition of what is signed and how.
 *
 * The signature covers "{timestamp}.{rawBody}", where rawBody is the exact byte
 * string that goes over the wire. Encoding the body here and handing back both
 * the bytes and the headers is deliberate: signing one encoding and sending
 * another produces a valid-looking request that always fails verification.
 */
class WebsiteSigner
{
    public const HEADER_TIMESTAMP = 'X-Horizon-Timestamp';

    public const HEADER_SIGNATURE = 'X-Horizon-Signature';

    /**
     * Encode a body to the exact bytes that will be sent.
     *
     * Slashes are left unescaped so the bytes match what a reader would expect,
     * and so the receiver's own encoding choices cannot matter: it verifies
     * against the raw string, never against a re-encoding.
     *
     * @param  array<string, mixed>  $body
     */
    public function encode(array $body): string
    {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new WebsiteConfigurationException('Delivery body could not be encoded as JSON.');
        }

        return $encoded;
    }

    /**
     * The headers proving this body was sent by us.
     *
     * @return array<string, string>
     */
    public function headers(string $rawBody, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_SIGNATURE => $this->sign($rawBody, $timestamp),
        ];
    }

    /**
     * The signature for a body at a moment in time.
     *
     * Including the timestamp in the signed message is what makes it useful:
     * a replayed request carries its original timestamp, which the receiver
     * rejects once it falls outside the window.
     */
    public function sign(string $rawBody, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret());
    }

    /**
     * The shared secret.
     *
     * Never returned to a caller, logged, or included in an exception message:
     * only the comparison result is ever visible.
     */
    private function secret(): string
    {
        $secret = (string) config('services.website.secret');

        if (trim($secret) === '') {
            throw WebsiteConfigurationException::missing('services.website.secret');
        }

        return $secret;
    }
}
