<?php

namespace App\BusinessCentral\Sync;

use Carbon\CarbonImmutable;

/**
 * What one paged fetch achieved.
 */
final readonly class PagedFetchResult
{
    public function __construct(
        public int $rows,
        public int $pages,
        public ?CarbonImmutable $latestModifiedAt,
        /** False when a total cap stopped the run before the result set ran out. */
        public bool $complete,
    ) {}

    /**
     * Whether the checkpoint may be advanced from this run.
     *
     * Only a run that reached the end of its result set has proved that
     * everything up to latestModifiedAt has been queued. A capped run has not,
     * and advancing from it would step over records it never fetched.
     */
    public function canAdvanceCheckpoint(): bool
    {
        return $this->complete && $this->latestModifiedAt !== null;
    }
}
