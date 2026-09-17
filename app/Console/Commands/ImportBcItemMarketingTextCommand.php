<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\ItemMarketingTextQuery;
use App\BusinessCentral\Sync\BusinessCentralPager;
use App\BusinessCentral\Sync\PagedFetchResult;
use App\Jobs\ImportBcProductMarketingText;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Business Central marketing copy and queues one import job per row.
 *
 * Incremental like the item import, not a sweep like the quantity one. This
 * page's lastModifiedDateTime is genuine — every live row carries a real,
 * spread-out value and none carry the 0001-01-01 sentinel — so a "changed
 * since" fetch sees everything that moves. See ItemMarketingTextQuery.
 *
 *   (no options)   incremental — everything changed since the checkpoint
 *   --full         reconciliation — every row, checkpoint ignored
 *   --top=N        manual — at most N rows, and never touches the checkpoint
 *   --sku=CODE     manual — that one item's copy only, never touches the checkpoint
 *
 * Normalisation, sanitising and persistence all stay in the job and importer, so
 * the same path runs whether a row arrives from here or anywhere else. This
 * command only fetches and queues.
 */
#[Signature('bc:import-item-marketing-text
    {--page-size=200 : How many rows to request per Business Central call}
    {--top= : Stop after this many rows in total (manual testing; never advances the checkpoint)}
    {--full : Re-import every row, ignoring the incremental checkpoint}
    {--force : Re-deliver every fetched row even when the copy has not changed (requires --full)}
    {--sku= : Fetch one item\'s copy by its Business Central number (manual testing; never advances the checkpoint)}
    {--skip=0 : How many rows to skip before fetching}')]
#[Description('Fetch marketing copy from Business Central and queue an import job for each row')]
class ImportBcItemMarketingTextCommand extends Command
{
    public function handle(BusinessCentralClient $client, BusinessCentralPager $pager): int
    {
        $pageSize = (int) $this->option('page-size');
        $limit = $this->option('top') === null ? null : (int) $this->option('top');
        $skip = (int) $this->option('skip');
        $full = (bool) $this->option('full');
        $force = (bool) $this->option('force');
        $sku = trim((string) $this->option('sku'));

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

        $checkpoint = SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_ITEM_MARKETING_TEXT);

        // A full run ignores the checkpoint; so does the very first run, which
        // has none. Both therefore fetch every row.
        $since = $full || ! $checkpoint->isSet()
            ? null
            : $this->formatSince($checkpoint->last_modified_at);

        $this->describeRun($since, $full, $pageSize, $limit);

        $queued = 0;

        try {
            $result = $pager->fetch(
                ItemMarketingTextQuery::PUBLISHER,
                ItemMarketingTextQuery::GROUP,
                ItemMarketingTextQuery::VERSION,
                ItemMarketingTextQuery::ENTITY_SET,
                fn (int $ask, int $fetched): array => ItemMarketingTextQuery::page($ask, $skip + $fetched, $since),
                function (array $row) use (&$queued, $force): void {
                    ImportBcProductMarketingText::dispatch($row, $force);
                    $queued++;
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
                'Fetch failed after queueing %d row(s). The checkpoint was not advanced.',
                $queued,
            ));

            Log::error('bc.import_item_marketing_text.failed', [
                'queued' => $queued,
                'since' => $since,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if ($result->rows === 0) {
            $this->info($since === null
                ? 'No marketing text rows returned from Business Central.'
                : 'No marketing text has changed since the last run.');

            $this->recordRun($checkpoint, $result, $full, advance: false);

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf('Queued %d row(s) across %d request(s).', $result->rows, $result->pages));

        $this->advanceCheckpoint($checkpoint, $result, $full, $limit);

        return self::SUCCESS;
    }

    /**
     * Fetch and queue one item's marketing copy.
     *
     * A deliberate detour around the paged path: this asks for one named record
     * rather than a slice of a result set, so there is nothing to page through
     * and no position to advance. The checkpoint is left entirely alone — this
     * run has seen one row and can vouch for nothing else.
     */
    private function importOneSku(BusinessCentralClient $client, string $sku): int
    {
        $this->line(sprintf('Fetching marketing text for %s, ignoring the checkpoint.', $sku));

        try {
            $response = $client->getCustom(
                ItemMarketingTextQuery::PUBLISHER,
                ItemMarketingTextQuery::GROUP,
                ItemMarketingTextQuery::VERSION,
                ItemMarketingTextQuery::ENTITY_SET,
                ItemMarketingTextQuery::forSku($sku),
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
            $this->warn(sprintf('No Business Central marketing text row has the number "%s".', $sku));

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            ImportBcProductMarketingText::dispatch($row);
            $queued++;

            $this->line(sprintf('  queued %s (%s)', $row['itemNo'] ?? $sku, $row['itemId'] ?? 'unknown id'));
        }

        $this->info(sprintf('Queued %d row(s). The checkpoint was left unchanged.', $queued));

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

        // A full reconciliation must never drag the checkpoint backwards: it
        // re-reads old rows, and their timestamps are older than whatever the
        // incremental runs have already reached.
        if ($full && $checkpoint->isSet() && $result->latestModifiedAt->lessThanOrEqualTo($checkpoint->last_modified_at)) {
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
            $result->latestModifiedAt->toIso8601ZuluString('millisecond'),
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
            $this->error('--force requires --full: a forced run re-delivers every row.');

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
     * Gated on whether there is anyone to ask. Under --no-interaction the input
     * is non-interactive and the run proceeds: a scheduled or scripted caller
     * has already stated its intent on the command line, and prompting into a
     * void would abort it.
     */
    private function confirmForce(): bool
    {
        $this->warn('Forcing re-delivery of every marketing text row.');
        $this->line('Each row will be queued for WordPress even if the copy has not changed.');

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('Continue?', false);
    }

    /**
     * The checkpoint as Business Central expects it in a filter.
     *
     * Milliseconds are sent, matching what Business Central stores and what the
     * checkpoint column holds. Flooring to whole seconds would place the filter
     * before the record the checkpoint came from, and the strict "gt"
     * comparison would then return that record on every run. See
     * ImportBcItemsCommand.
     */
    private function formatSince(CarbonImmutable $checkpoint): string
    {
        return $checkpoint->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private function describeRun(?string $since, bool $full, int $pageSize, ?int $limit): void
    {
        if ($full) {
            $this->line('Full reconciliation: fetching every marketing text row, ignoring the checkpoint.');
        } elseif ($since === null) {
            $this->line('No checkpoint yet: fetching every marketing text row.');
        } else {
            $this->line(sprintf('Incremental: fetching marketing text changed since %s.', $since));
        }

        $this->line(sprintf(
            'Page size %d%s.',
            $pageSize,
            $limit === null ? '' : ", stopping after {$limit} row(s)",
        ));
    }
}
