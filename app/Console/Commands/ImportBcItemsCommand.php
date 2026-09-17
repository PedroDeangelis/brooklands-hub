<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\ItemsQuery;
use App\BusinessCentral\Sync\BusinessCentralPager;
use App\BusinessCentral\Sync\PagedFetchResult;
use App\Jobs\ImportBcProduct;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Business Central items and queues one import job per row.
 *
 * Three ways to run, which differ only in where they start and when they stop:
 *
 *   (no options)   incremental — everything changed since the checkpoint
 *   --full         reconciliation — the complete catalogue, checkpoint ignored
 *   --top=N        manual — at most N rows, and never touches the checkpoint
 *   --sku=CODE     manual — that one item only, and never touches the checkpoint
 *
 * Normalisation and persistence deliberately stay in the job, so the same path
 * runs whether a row arrives from here or from anywhere else. This command only
 * fetches and queues; Horizon may still be working long after it exits.
 *
 * --force additionally re-delivers every fetched item, whether or not anything
 * moved. That is for putting a drifted website back in step, and it queues one
 * delivery per product, so it asks before doing it. The scheduler never passes
 * it.
 */
#[Signature('bc:import-items
    {--page-size=200 : How many items to request per Business Central call}
    {--top= : Stop after this many items in total (manual testing; never advances the checkpoint)}
    {--full : Re-import the complete catalogue, ignoring the incremental checkpoint}
    {--force : Re-deliver every fetched item even when nothing changed (requires --full)}
    {--sku= : Fetch one item by its Business Central number (manual testing; never advances the checkpoint)}
    {--skip=0 : How many items to skip before fetching}')]
#[Description('Fetch items from Business Central and queue an import job for each one')]
class ImportBcItemsCommand extends Command
{
    public function handle(BusinessCentralClient $client, BusinessCentralPager $pager): int
    {
        $pageSize = (int) $this->option('page-size');
        $limit = $this->option('top') === null ? null : (int) $this->option('top');
        $skip = (int) $this->option('skip');
        $full = (bool) $this->option('full');
        $force = (bool) $this->option('force');

        if ($pageSize < 1) {
            $this->error('--page-size must be a positive integer.');

            return self::FAILURE;
        }

        if ($limit !== null && $limit < 1) {
            $this->error('--top must be a positive integer.');

            return self::FAILURE;
        }

        if ($skip < 0) {
            $this->error('--skip cannot be negative.');

            return self::FAILURE;
        }

        $sku = trim((string) $this->option('sku'));

        if ($force && ! $this->forceIsAllowed($full, $limit, $sku)) {
            return self::FAILURE;
        }

        if ($sku !== '') {
            return $this->importOneSku($client, $sku);
        }

        if ($force && ! $this->confirmForce()) {
            $this->line('Nothing was fetched or queued.');

            return self::SUCCESS;
        }

        $checkpoint = SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEMS);

        // A full run ignores the checkpoint; so does the very first run, which
        // has none. Both therefore fetch the complete catalogue.
        $since = $full || ! $checkpoint->isSet()
            ? null
            : $this->formatSince($checkpoint->last_modified_at);

        $this->describeRun($since, $full, $checkpoint, $pageSize, $limit);

        $dispatched = 0;

        try {
            $result = $pager->fetch(
                ItemsQuery::PUBLISHER,
                ItemsQuery::GROUP,
                ItemsQuery::VERSION,
                ItemsQuery::ENTITY_SET,
                fn (int $ask, int $fetched): array => ItemsQuery::page($ask, $skip + $fetched, $since),
                function (array $row) use (&$dispatched, $force): void {
                    ImportBcProduct::dispatch($row, $force);
                    $dispatched++;
                },
                $pageSize,
                $limit,
                function (int $page, int $rows, int $total): void {
                    $this->line(sprintf('  page %d: %d row(s), %d queued so far', $page, $rows, $total));
                },
            );
        } catch (BusinessCentralException $e) {
            // The checkpoint is deliberately untouched. Whatever was queued has
            // been queued, and the next run re-reads from the same place rather
            // than stepping over the pages that were never fetched.
            $this->error($e->getMessage());

            if ($e->url !== null) {
                $this->line("URL: {$e->url}");
            }

            if ($e->bodyExcerpt !== null) {
                $this->line("Response: {$e->bodyExcerpt}");
            }

            $this->warn(sprintf(
                'Fetch failed after queueing %d item(s). The checkpoint was not advanced.',
                $dispatched,
            ));

            Log::error('bc.import_items.failed', [
                'queued' => $dispatched,
                'since' => $since,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if ($result->rows === 0) {
            $this->info($since === null
                ? 'No items returned from Business Central.'
                : 'No items have changed since the last run.');

            $this->recordRun($checkpoint, $result, $full, advance: false);

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            'Queued %d item(s) across %d request(s).',
            $result->rows,
            $result->pages,
        ));

        $this->advanceCheckpoint($checkpoint, $result, $full, $limit);

        return self::SUCCESS;
    }

    /**
     * Fetch and queue a single item by its Business Central number.
     *
     * A deliberate detour around the paged path: this asks for one named
     * record rather than a slice of a result set, so there is nothing to page
     * through and no position to advance. The checkpoint is left entirely
     * alone — this run has seen one product and can vouch for nothing else.
     */
    private function importOneSku(BusinessCentralClient $client, string $sku): int
    {
        $this->line(sprintf('Fetching the single item %s, ignoring the checkpoint.', $sku));

        try {
            $response = $client->getCustom(
                ItemsQuery::PUBLISHER,
                ItemsQuery::GROUP,
                ItemsQuery::VERSION,
                ItemsQuery::ENTITY_SET,
                ItemsQuery::forSku($sku),
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            if ($e->url !== null) {
                $this->line("URL: {$e->url}");
            }

            return self::FAILURE;
        }

        $rows = $response['value'] ?? [];

        if (! is_array($rows) || $rows === []) {
            $this->warn(sprintf('No Business Central item has the number "%s".', $sku));

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            ImportBcProduct::dispatch($row);
            $queued++;

            $this->line(sprintf('  queued %s (%s)', $row['number'] ?? $sku, $row['id'] ?? 'unknown id'));
        }

        $this->info(sprintf('Queued %d item(s). The checkpoint was left unchanged.', $queued));

        return self::SUCCESS;
    }

    /**
     * Move the checkpoint on, when this run has earned the right to.
     */
    private function advanceCheckpoint(
        SyncCheckpoint $checkpoint,
        PagedFetchResult $result,
        bool $full,
        ?int $limit,
    ): void {
        if ($limit !== null) {
            // A capped run stopped early by design and has not seen the whole
            // result set, so it cannot vouch for anything.
            $this->warn('--top was given, so the checkpoint was left unchanged.');
            $this->recordRun($checkpoint, $result, $full, advance: false);

            return;
        }

        if (! $result->canAdvanceCheckpoint()) {
            $this->warn('The result set was not fetched completely; the checkpoint was left unchanged.');
            $this->recordRun($checkpoint, $result, $full, advance: false);

            return;
        }

        $latest = $result->latestModifiedAt;

        // A full reconciliation must never drag the checkpoint backwards: it
        // re-reads old records, and their timestamps are older than whatever
        // the incremental runs have already reached.
        if ($full && $checkpoint->isSet() && $latest->lessThanOrEqualTo($checkpoint->last_modified_at)) {
            $this->line(sprintf(
                'Checkpoint left at %s: the full run saw nothing newer.',
                $checkpoint->last_modified_at->toIso8601ZuluString('millisecond'),
            ));

            $this->recordRun($checkpoint, $result, $full, advance: false);

            return;
        }

        $this->recordRun($checkpoint, $result, $full, advance: true);

        $this->info(sprintf(
            'Checkpoint advanced to %s.',
            $latest->toIso8601ZuluString('millisecond'),
        ));
    }

    private function recordRun(
        SyncCheckpoint $checkpoint,
        PagedFetchResult $result,
        bool $full,
        bool $advance,
    ): void {
        $attributes = [
            'last_run_at' => now(),
            'last_run_rows' => $result->rows,
            'last_run_pages' => $result->pages,
        ];

        if ($advance) {
            $attributes['last_modified_at'] = $result->latestModifiedAt;
        }

        if ($full) {
            $attributes['last_full_sync_at'] = now();
        }

        $checkpoint->fill($attributes)->save();
    }

    /**
     * Whether --force makes sense alongside the other options given.
     *
     * A forced run re-delivers what it fetches, so it only means anything when
     * it fetches everything. --top and --sku both read a subset and never
     * advance the checkpoint, so forcing them would re-send an arbitrary slice
     * and call it a resync.
     */
    private function forceIsAllowed(bool $full, ?int $limit, string $sku): bool
    {
        if (! $full) {
            $this->error('--force requires --full: a forced run re-delivers the complete catalogue.');

            return false;
        }

        if ($limit !== null) {
            $this->error('--force cannot be combined with --top.');

            return false;
        }

        if ($sku !== '') {
            $this->error('--force cannot be combined with --sku.');

            return false;
        }

        return true;
    }

    /**
     * Check the operator meant it.
     *
     * Gated on whether there is anyone to ask. Under --no-interaction the
     * input is non-interactive and the run proceeds: a scheduled or scripted
     * caller has already stated its intent on the command line, and prompting
     * into a void would abort it.
     */
    private function confirmForce(): bool
    {
        $this->warn('Forcing re-delivery of the complete catalogue.');
        $this->line('Every fetched item will be queued for WordPress even if nothing changed.');

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('Continue?', false);
    }

    /**
     * The checkpoint as Business Central expects it in a filter.
     *
     * Milliseconds are sent, matching what Business Central stores and what
     * the checkpoint column holds. The API accepts a fractional literal — its
     * datetime grammar makes the fraction optional and requires the timezone
     * designator, which the trailing Z supplies. An earlier comment here
     * claimed the fraction was rejected; a live request disproved it.
     *
     * Flooring to whole seconds would place the filter before the record the
     * checkpoint came from, and the strict "gt" comparison would then return
     * that record on every run.
     */
    private function formatSince(CarbonImmutable $checkpoint): string
    {
        return $checkpoint->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private function describeRun(?string $since, bool $full, SyncCheckpoint $checkpoint, int $pageSize, ?int $limit): void
    {
        if ($full) {
            $this->line('Full reconciliation: fetching the complete catalogue, ignoring the checkpoint.');
        } elseif ($since === null) {
            $this->line('No checkpoint yet: fetching the complete catalogue.');
        } else {
            $this->line(sprintf('Incremental: fetching items changed since %s.', $since));
        }

        $this->line(sprintf(
            'Page size %d%s.',
            $pageSize,
            $limit === null ? '' : ", stopping after {$limit} item(s)",
        ));
    }
}
