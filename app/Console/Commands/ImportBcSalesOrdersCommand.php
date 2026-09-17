<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\SalesOrdersQuery;
use App\BusinessCentral\Sync\BusinessCentralPager;
use App\BusinessCentral\Sync\PagedFetchResult;
use App\Jobs\ImportBcSalesOrder;
use App\Models\SyncCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Business Central sales order headers and queues one import job per row.
 *
 * Three ways to run, which differ only in where they start and when they stop:
 *
 *   (no options)   incremental — everything changed since the checkpoint
 *   --full         reconciliation — every open order, checkpoint ignored
 *   --top=N        manual — at most N rows, and never touches the checkpoint
 *   --number=NO    manual — that one order only, and never touches the checkpoint
 *
 * Only the header is fetched here. The lines, shipments and invoices behind
 * each order are read by the job, on Horizon, so a slow or failing read of
 * one order's documents never holds the scheduler or the checkpoint.
 *
 * --force additionally re-delivers every fetched order, whether or not
 * anything moved. That is for putting a drifted website back in step. The
 * scheduler never passes it.
 */
#[Signature('bc:import-sales-orders
    {--page-size=200 : How many orders to request per Business Central call}
    {--top= : Stop after this many orders in total (manual testing; never advances the checkpoint)}
    {--full : Re-import every open order, ignoring the incremental checkpoint}
    {--force : Re-deliver every fetched order even when nothing changed (requires --full)}
    {--number= : Fetch one order by its Business Central number (manual testing; never advances the checkpoint)}
    {--skip=0 : How many orders to skip before fetching}')]
#[Description('Fetch sales orders from Business Central and queue an import job for each one')]
class ImportBcSalesOrdersCommand extends Command
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

        $number = trim((string) $this->option('number'));

        if ($force && ! $this->forceIsAllowed($full, $limit, $number)) {
            return self::FAILURE;
        }

        if ($number !== '') {
            return $this->importOneNumber($client, $number);
        }

        if ($force && ! $this->confirmForce()) {
            $this->line('Nothing was fetched or queued.');

            return self::SUCCESS;
        }

        $checkpoint = SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_SALES_ORDERS);

        // A full run ignores the checkpoint; so does the very first run, which
        // has none. Both therefore fetch every open order.
        $since = $full || ! $checkpoint->isSet()
            ? null
            : $this->formatSince($checkpoint->last_modified_at);

        $this->describeRun($since, $full, $pageSize, $limit);

        $dispatched = 0;

        try {
            $result = $pager->fetch(
                SalesOrdersQuery::PUBLISHER,
                SalesOrdersQuery::GROUP,
                SalesOrdersQuery::VERSION,
                SalesOrdersQuery::ENTITY_SET,
                fn (int $ask, int $fetched): array => SalesOrdersQuery::page($ask, $skip + $fetched, $since),
                function (array $row) use (&$dispatched, $force): void {
                    ImportBcSalesOrder::dispatch($row, $force);
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
                'Fetch failed after queueing %d order(s). The checkpoint was not advanced.',
                $dispatched,
            ));

            Log::error('bc.import_sales_orders.failed', [
                'queued' => $dispatched,
                'since' => $since,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if ($result->rows === 0) {
            $this->info($since === null
                ? 'No sales orders returned from Business Central.'
                : 'No sales orders have changed since the last run.');

            $this->recordRun($checkpoint, $result, $full, advance: false);

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            'Queued %d order(s) across %d request(s).',
            $result->rows,
            $result->pages,
        ));

        $this->advanceCheckpoint($checkpoint, $result, $full, $limit);

        return self::SUCCESS;
    }

    /**
     * Fetch and queue a single order by its Business Central number.
     *
     * A deliberate detour around the paged path: this asks for one named
     * record rather than a slice of a result set, so there is nothing to page
     * through and no position to advance.
     */
    private function importOneNumber(BusinessCentralClient $client, string $number): int
    {
        $this->line(sprintf('Fetching the single sales order %s, ignoring the checkpoint.', $number));

        try {
            $response = $client->getCustom(
                SalesOrdersQuery::PUBLISHER,
                SalesOrdersQuery::GROUP,
                SalesOrdersQuery::VERSION,
                SalesOrdersQuery::ENTITY_SET,
                SalesOrdersQuery::forNumber($number),
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
            $this->warn(sprintf('No open Business Central sales order has the number "%s".', $number));

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            ImportBcSalesOrder::dispatch($row);
            $queued++;

            $this->line(sprintf('  queued %s (%s)', $row['number'] ?? $number, $row['id'] ?? 'unknown id'));
        }

        $this->info(sprintf('Queued %d order(s). The checkpoint was left unchanged.', $queued));

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
     * it fetches everything. --top and --number both read a subset and never
     * advance the checkpoint.
     */
    private function forceIsAllowed(bool $full, ?int $limit, string $number): bool
    {
        if (! $full) {
            $this->error('--force requires --full: a forced run re-delivers every order.');

            return false;
        }

        if ($limit !== null) {
            $this->error('--force cannot be combined with --top.');

            return false;
        }

        if ($number !== '') {
            $this->error('--force cannot be combined with --number.');

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
        $this->warn('Forcing re-delivery of every open sales order.');
        $this->line('Each one will be queued for WordPress even if nothing changed.');

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
     * comparison would then return that record on every run.
     */
    private function formatSince(CarbonImmutable $checkpoint): string
    {
        return $checkpoint->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private function describeRun(?string $since, bool $full, int $pageSize, ?int $limit): void
    {
        if ($full) {
            $this->line('Full reconciliation: fetching every open sales order, ignoring the checkpoint.');
        } elseif ($since === null) {
            $this->line('No checkpoint yet: fetching every open sales order.');
        } else {
            $this->line(sprintf('Incremental: fetching sales orders changed since %s.', $since));
        }

        $this->line(sprintf(
            'Page size %d%s.',
            $pageSize,
            $limit === null ? '' : ", stopping after {$limit} order(s)",
        ));
    }
}
