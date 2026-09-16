<?php

namespace App\BusinessCentral;

use RuntimeException;
use Throwable;

/**
 * A failed call to Business Central: either the OAuth token request or an API request.
 *
 * Carries enough context to diagnose the failure from a command's output or a log line
 * without re-running the request.
 */
final class BusinessCentralException extends RuntimeException
{
    private const BODY_EXCERPT_LIMIT = 1024;

    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $url = null,
        public readonly ?string $bodyExcerpt = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function tokenRequestFailed(string $url, ?int $status, ?string $body, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Business Central token request failed (HTTP %s).', $status ?? 'connection error'),
            $status,
            $url,
            self::excerpt($body),
            $previous,
        );
    }

    public static function tokenMissing(string $url, ?string $body): self
    {
        return new self(
            'Business Central token response did not contain an access_token.',
            null,
            $url,
            self::excerpt($body),
        );
    }

    public static function requestFailed(string $url, ?int $status, ?string $body, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Business Central request failed (HTTP %s).', $status ?? 'connection error'),
            $status,
            $url,
            self::excerpt($body),
            $previous,
        );
    }

    public static function missingConfiguration(string $key): self
    {
        return new self(sprintf('Business Central configuration "%s" is not set.', $key));
    }

    private static function excerpt(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        return mb_strimwidth($body, 0, self::BODY_EXCERPT_LIMIT, '…');
    }
}
