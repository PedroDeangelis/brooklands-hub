<?php

namespace App\BusinessCentral\Sync;

use App\BusinessCentral\BusinessCentralClient;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Fetches a Business Central result set one page at a time.
 *
 * This endpoint returns no @odata.nextLink, so paging is done explicitly with
 * $top and $skip over a total ordering. The ordering must be total — a change
 * timestamp alone is not, because many records share one — or $skip would
 * repeat and skip rows as the server broke ties differently between calls.
 *
 * Page size and total cap are deliberately separate ideas. Page size is how
 * much is asked for per HTTP call and says nothing about when to stop; a cap
 * is a deliberate limit for manual testing, and a capped run never advances a
 * checkpoint because it has not seen everything it would have to.
 */
class BusinessCentralPager
{
    /**
     * A hard stop on pages, so a defect cannot loop against Business Central
     * forever. At the default page size this allows a million records.
     */
    private const MAX_PAGES = 5000;

    public function __construct(private readonly BusinessCentralClient $client) {}

    /**
     * Page through a result set, handing each row to the caller.
     *
     * @param  Closure(int $pageSize, int $skip): array<string, scalar>  $query  Builds the query for one page.
     * @param  Closure(array<string, mixed> $row): void  $onRow  Receives each row in order.
     * @param  int|null  $limit  Stop after this many rows in total, or null for everything.
     */
    public function fetch(
        string $publisher,
        string $group,
        string $version,
        string $entitySet,
        Closure $query,
        Closure $onRow,
        int $pageSize,
        ?int $limit = null,
        ?Closure $onPage = null,
    ): PagedFetchResult {
        $rows = 0;
        $pages = 0;
        $latest = null;
        $complete = true;

        while ($pages < self::MAX_PAGES) {
            // Never ask for more than the cap still allows, so a --top=20 run
            // with a page size of 200 makes one request for 20 rather than
            // fetching 200 and discarding 180.
            $ask = $limit === null ? $pageSize : min($pageSize, $limit - $rows);

            if ($ask <= 0) {
                $complete = false;
                break;
            }

            $response = $this->client->getCustom(
                $publisher,
                $group,
                $version,
                $entitySet,
                $query($ask, $rows),
            );

            $page = $response['value'] ?? [];

            if (! is_array($page) || $page === []) {
                break;
            }

            $pages++;

            foreach ($page as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $onRow($row);
                $rows++;

                $modified = $this->modifiedAt($row);

                if ($modified !== null && ($latest === null || $modified->greaterThan($latest))) {
                    $latest = $modified;
                }
            }

            $onPage?->__invoke($pages, count($page), $rows);

            // A short page means the result set is exhausted. Asking again
            // would cost a request to learn nothing.
            if (count($page) < $ask) {
                break;
            }

            if ($limit !== null && $rows >= $limit) {
                // Stopped because the caller asked for a cap, not because the
                // data ran out: this run has not seen the whole result set.
                $complete = false;
                break;
            }
        }

        return new PagedFetchResult($rows, $pages, $latest, $complete);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function modifiedAt(array $row): ?CarbonImmutable
    {
        $value = $row['lastModifiedDateTime'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
