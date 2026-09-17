<?php

namespace App\Website;

use Throwable;

/**
 * What came back from a delivery attempt.
 *
 * Never throws on an HTTP status: the caller decides what a failure means for
 * the ledger, and needs the detail to record it.
 */
final readonly class WebsiteResponse
{
    private const ERROR_EXCERPT_LIMIT = 1024;

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    private function __construct(
        public WebsiteOutcome $outcome,
        public ?int $status = null,
        public array $body = [],
        public ?string $error = null,
        public ?Throwable $exception = null,
        public array $conflicts = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function delivered(int $status, array $body = []): self
    {
        return new self(WebsiteOutcome::Delivered, $status, $body);
    }

    /**
     * Accepted without being applied.
     *
     * @param  array<string, mixed>  $body
     */
    public static function accepted(int $status, array $body = []): self
    {
        return new self(WebsiteOutcome::Accepted, $status, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function transient(?int $status, string $error, array $body = [], ?Throwable $exception = null): self
    {
        return new self(WebsiteOutcome::Transient, $status, $body, self::excerpt($error), $exception);
    }

    /**
     * The website holds no product to apply this partial to.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fullSyncRequired(int $status, string $error, array $body = []): self
    {
        return new self(WebsiteOutcome::FullSyncRequired, $status, $body, self::excerpt($error));
    }

    /**
     * Blocked by an identity collision on the website.
     *
     * @param  array<string, mixed>  $body
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    public static function conflict(int $status, array $conflicts, array $body = []): self
    {
        return new self(
            WebsiteOutcome::Conflict,
            $status,
            $body,
            self::excerpt(self::summarise($conflicts)),
            conflicts: $conflicts,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function permanent(?int $status, string $error, array $body = []): self
    {
        return new self(WebsiteOutcome::Permanent, $status, $body, self::excerpt($error));
    }

    public function isDelivered(): bool
    {
        return $this->outcome === WebsiteOutcome::Delivered;
    }

    /**
     * Accepted as a request, but the website applied nothing.
     */
    public function isAccepted(): bool
    {
        return $this->outcome === WebsiteOutcome::Accepted;
    }

    public function isTransient(): bool
    {
        return $this->outcome === WebsiteOutcome::Transient;
    }

    public function isPermanent(): bool
    {
        return $this->outcome === WebsiteOutcome::Permanent;
    }

    public function isConflict(): bool
    {
        return $this->outcome === WebsiteOutcome::Conflict;
    }

    public function needsFullSync(): bool
    {
        return $this->outcome === WebsiteOutcome::FullSyncRequired;
    }

    /**
     * A one-line description for the ledger's last_error column.
     */
    public function describe(): string
    {
        return sprintf('HTTP %s: %s', $this->status ?? 'connection error', $this->error ?? 'unknown error');
    }

    /**
     * A readable one-line summary of what collided.
     *
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    private static function summarise(array $conflicts): string
    {
        $parts = [];

        foreach ($conflicts as $conflict) {
            $field = (string) ($conflict['field'] ?? 'identity');
            $value = (string) ($conflict['value'] ?? '');
            $wpId = $conflict['existing_wp_id'] ?? null;

            $parts[] = $wpId === null
                ? sprintf('%s "%s" is already in use', $field, $value)
                : sprintf('%s "%s" belongs to WordPress record #%d', $field, $value, (int) $wpId);
        }

        return $parts === [] ? 'Identity conflict' : implode('; ', $parts);
    }

    private static function excerpt(string $error): string
    {
        return mb_strimwidth($error, 0, self::ERROR_EXCERPT_LIMIT, '…');
    }
}
