<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\ItemQuantitiesQuery;
use App\BusinessCentral\Sync\BusinessCentralPager;
use App\BusinessCentral\Sync\PagedFetchResult;
use App\Jobs\ImportBcProductQuantity;
use App\Models\SyncCheckpoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Business Central stock figures and queues one import job per row.
 *
 * Unlike the item import, every run sweeps the whole endpoint. That is not an
 * oversight: this page's lastModifiedDateTime does not track stock movement and
 * is unset on 98% of rows, so a "changed since" fetch would return nothing
 * while stock moved underneath it. See ItemQuantitiesQuery for the measurements.
 *
 * Sweeping is affordable because the cost of an unchanged row is one fetch and
 * nothing else: the importer compares figures, finds no difference, and opens
 * no delivery work.
 *
 *   (no options)   sweep the whole endpoint
 *   --top=N        manual — at most N rows, then stop
 *   --sku=CODE     manual — that one item's figures only
 *
 * --full is accepted and means the same as a plain run, so the scheduled
 * reconciliation entry reads the same way as the item one.
 */
#[Signature('bc:import-item-quantities
    {--page-size=200 : How many rows to request per Business Central call}
    {--top= : Stop after this many rows in total (manual testing)}
    {--full : Sweep the complete catalogue (the default; accepted for symmetry with bc:import-items)}
    {--sku= : Fetch one item\'s figures by its Business Central number (manual testing)}')]
#[Description('Fetch stock figures from Business Central and queue an import job for each one')]
class ImportBcItemQuantitiesCommand extends Command
{
    public function handle(BusinessCentralClient $client, BusinessCentralPager $pager): int
    {
        $pageSize = (int) $this->option('page-size');
        $limit = $this->option('top') === null ? null : (int) $this->option('top');
        $sku = trim((string) $this->option('sku'));

        if ($pageSize < 1) {
            $this->error('--page-size must be a positive integer.');

            return self::FAILURE;
        }

        if ($limit !== null && $limit < 1) {
            $this->error('--top must be a positive integer.');

            return self::FAILURE;
        }

        if ($sku !== '') {
            return $this->importOneSku($client, $sku);
        }

        $this->line(sprintf(
            'Sweeping the complete quantity catalogue, page size %d%s.',
            $pageSize,
            $limit === null ? '' : ", stopping after {$limit} row(s)",
        ));

        $queued = 0;

        try {
            $result = $pager->fetch(
                ItemQuantitiesQuery::PUBLISHER,
                ItemQuantitiesQuery::GROUP,
                ItemQuantitiesQuery::VERSION,
                ItemQuantitiesQuery::ENTITY_SET,
                fn (int $ask, int $fetched): array => ItemQuantitiesQuery::page($ask, $fetched),
                function (array $row) use (&$queued): void {
                    ImportBcProductQuantity::dispatch($row);
                    $queued++;
                },
                $pageSize,
                $limit,
                function (int $page, int $rows, int $total): void {
                    $this->line(sprintf('  page %d: %d row(s), %d queued so far', $page, $rows, $total));
                },
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            if ($e->url !== null) {
                $this->line("URL: {$e->url}");
            }

            $this->warn(sprintf('Fetch failed after queueing %d row(s).', $queued));

            Log::error('bc.import_item_quantities.failed', [
                'queued' => $queued,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if ($result->rows === 0) {
            $this->warn('No quantity rows returned from Business Central.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf('Queued %d row(s) across %d request(s).', $result->rows, $result->pages));

        $this->recordRun($result, $limit);

        return self::SUCCESS;
    }

    /**
     * Fetch and queue one item's stock figures.
     */
    private function importOneSku(BusinessCentralClient $client, string $sku): int
    {
        $this->line(sprintf('Fetching stock figures for %s.', $sku));

        try {
            $response = $client->getCustom(
                ItemQuantitiesQuery::PUBLISHER,
                ItemQuantitiesQuery::GROUP,
                ItemQuantitiesQuery::VERSION,
                ItemQuantitiesQuery::ENTITY_SET,
                ItemQuantitiesQuery::forSku($sku),
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $response['value'] ?? [];

        if (! is_array($rows) || $rows === []) {
            $this->warn(sprintf('No Business Central quantity row has the number "%s".', $sku));

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($rows as $row) {
            if (is_array($row)) {
                ImportBcProductQuantity::dispatch($row);
                $queued++;
                $this->line(sprintf('  queued %s (%s)', $row['number'] ?? $sku, $row['id'] ?? 'unknown id'));
            }
        }

        $this->info(sprintf('Queued %d row(s).', $queued));

        return self::SUCCESS;
    }

    /**
     * Record what this run did, for visibility rather than for resuming.
     *
     * There is no position to resume from: every run sweeps the whole endpoint.
     * A capped run is not recorded at all, so a manual test cannot make the
     * schedule look like it completed a sweep it never did.
     */
    private function recordRun(PagedFetchResult $result, ?int $limit): void
    {
        if ($limit !== null) {
            $this->warn('--top was given, so this run was not recorded as a full sweep.');

            return;
        }

        SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEM_QUANTITIES)->fill([
            'last_run_at' => now(),
            'last_run_rows' => $result->rows,
            'last_run_pages' => $result->pages,
            'last_full_sync_at' => now(),
        ])->save();
    }
}
