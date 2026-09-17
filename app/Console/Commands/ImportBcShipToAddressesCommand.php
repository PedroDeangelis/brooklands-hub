<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\ShipToAddressesQuery;
use App\BusinessCentral\Sync\BusinessCentralPager;
use App\BusinessCentral\Sync\PagedFetchResult;
use App\Jobs\ImportBcShipToAddress;
use App\Models\Customer;
use App\Models\SyncCheckpoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Business Central ship-to addresses and queues one import job per row.
 *
 * Every run sweeps the whole endpoint, on purpose. A ship-to that arrives
 * before its customer is skipped, and an incremental checkpoint would then
 * advance past it and lose the address until Business Central next touched it.
 * The endpoint is ~1,300 rows — three requests — so re-reading it is cheaper
 * than being wrong. See ShipToAddressesQuery.
 *
 * Sweeping is affordable because an unchanged address costs one fetch and
 * nothing else: the importer finds the customer's list unchanged and opens no
 * delivery work.
 *
 *   (no options)    sweep the whole endpoint
 *   --top=N         manual — at most N rows, then stop
 *   --customer=NO   manual — every ship-to for that one customer
 *
 * --full is accepted and means the same as a plain run, so the scheduled
 * reconciliation entry reads the same way as the item one.
 */
#[Signature('bc:import-ship-to-addresses
    {--page-size=200 : How many rows to request per Business Central call}
    {--top= : Stop after this many rows in total (manual testing)}
    {--full : Sweep the complete catalogue (the default; accepted for symmetry with bc:import-items)}
    {--force : Re-deliver every fetched row even when nothing changed}
    {--customer= : Fetch every ship-to for one customer by its Business Central number (manual testing)}')]
#[Description('Fetch ship-to addresses from Business Central and queue an import job for each one')]
class ImportBcShipToAddressesCommand extends Command
{
    public function handle(BusinessCentralClient $client, BusinessCentralPager $pager): int
    {
        $pageSize = (int) $this->option('page-size');
        $limit = $this->option('top') === null ? null : (int) $this->option('top');
        $customerNumber = trim((string) $this->option('customer'));
        $force = (bool) $this->option('force');

        if ($pageSize < 1) {
            $this->error('--page-size must be a positive integer.');

            return self::FAILURE;
        }

        if ($limit !== null && $limit < 1) {
            $this->error('--top must be a positive integer.');

            return self::FAILURE;
        }

        // --force needs no --full here: this command always sweeps the whole
        // endpoint, so there is no narrower fetch for it to contradict.
        if ($force && ! $this->forceIsAllowed($limit, $customerNumber)) {
            return self::FAILURE;
        }

        if ($customerNumber !== '') {
            return $this->importOneCustomer($client, $customerNumber);
        }

        if ($force && ! $this->confirmForce()) {
            $this->line('Nothing was fetched or queued.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Sweeping the complete ship-to address list, page size %d%s.',
            $pageSize,
            $limit === null ? '' : ", stopping after {$limit} row(s)",
        ));

        $queued = 0;

        // A complete sweep sees every ship-to, so it can set each customer's
        // list to exactly what Business Central holds — the only way a deleted
        // address ever leaves. Rows are gathered per customer and handed over
        // as one replace job each. A capped run sees only part of the endpoint
        // and must never remove anything, so it stays one add-only job per row.
        $complete = $limit === null;

        /** @var array<string, array<int, array<string, mixed>>> $byCustomer */
        $byCustomer = [];

        try {
            $result = $pager->fetch(
                ShipToAddressesQuery::PUBLISHER,
                ShipToAddressesQuery::GROUP,
                ShipToAddressesQuery::VERSION,
                ShipToAddressesQuery::ENTITY_SET,
                fn (int $ask, int $fetched): array => ShipToAddressesQuery::page($ask, $fetched),
                function (array $row) use (&$queued, &$byCustomer, $force, $complete): void {
                    if ($complete) {
                        $byCustomer[(string) ($row['customerNo'] ?? '')][] = $row;

                        return;
                    }

                    ImportBcShipToAddress::dispatch($row, $force);
                    $queued++;
                },
                $pageSize,
                $limit,
                function (int $page, int $rows, int $total): void {
                    $this->line(sprintf('  page %d: %d row(s), %d fetched so far', $page, $rows, $total));
                },
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            if ($e->url !== null) {
                $this->line("URL: {$e->url}");
            }

            $this->warn(sprintf('Fetch failed after queueing %d row(s).', $queued));

            Log::error('bc.import_ship_to_addresses.failed', [
                'queued' => $queued,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if ($complete && $result->complete) {
            unset($byCustomer['']);

            foreach ($byCustomer as $customerNumber => $rows) {
                ImportBcShipToAddress::dispatch(['customerNo' => $customerNumber, 'rows' => $rows], $force, replaceAll: true);
                $queued++;
            }

            // A customer whose every address was deleted no longer appears in
            // the sweep at all, so its list would never be cleared by the loop
            // above. Any customer still holding addresses that this sweep did
            // not see gets an empty replacement.
            $orphaned = Customer::query()
                ->whereNotNull('shipping_addresses')
                // whereJsonLength rather than raw json_length(): MySQL and SQLite
                // spell the function differently, and the tests run on both.
                ->whereJsonLength('shipping_addresses', '>', 0)
                ->whereNotIn('number', array_keys($byCustomer))
                ->pluck('number');

            foreach ($orphaned as $customerNumber) {
                ImportBcShipToAddress::dispatch(['customerNo' => $customerNumber, 'rows' => []], $force, replaceAll: true);
                $queued++;
            }

            if ($orphaned->isNotEmpty()) {
                $this->line(sprintf('  %d customer(s) no longer have any ship-to in Business Central; clearing.', $orphaned->count()));
            }
        }

        if ($result->rows === 0) {
            $this->warn('No ship-to address rows returned from Business Central.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf('Queued %d row(s) across %d request(s).', $result->rows, $result->pages));

        $this->recordRun($result, $limit);

        return self::SUCCESS;
    }

    /**
     * Fetch and queue one item's ship-to addresses.
     */
    /**
     * Whether --force makes sense alongside the other options given.
     *
     * No --full requirement: this command sweeps the whole endpoint on every
     * run, so --full is already a no-op here. --top and --customer still read a
     * subset, and forcing one of those would re-send an arbitrary slice.
     */
    private function forceIsAllowed(?int $limit, string $customerNumber): bool
    {
        if ($limit !== null) {
            $this->error('--force cannot be combined with --top.');

            return false;
        }

        if ($customerNumber !== '') {
            $this->error('--force cannot be combined with --customer.');

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
        $this->warn('Forcing re-delivery of every ship-to address.');
        $this->line('Each row will be queued for WordPress even if nothing changed.');

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('Continue?', false);
    }

    private function importOneCustomer(BusinessCentralClient $client, string $customerNumber): int
    {
        $this->line(sprintf('Fetching ship-to addresses for %s.', $customerNumber));

        try {
            $response = $client->getCustom(
                ShipToAddressesQuery::PUBLISHER,
                ShipToAddressesQuery::GROUP,
                ShipToAddressesQuery::VERSION,
                ShipToAddressesQuery::ENTITY_SET,
                ShipToAddressesQuery::forCustomerNumber($customerNumber),
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $response['value'] ?? [];

        if (! is_array($rows)) {
            $rows = [];
        }

        if ($rows === []) {
            $this->warn(sprintf('No Business Central ship-to address belongs to customer "%s"; clearing its list.', $customerNumber));
        }

        $rows = array_values(array_filter($rows, 'is_array'));

        foreach ($rows as $row) {
            $this->line(sprintf('  %s (%s)', $row['code'] ?? '?', $row['id'] ?? 'unknown id'));
        }

        // Everything Business Central holds for this customer is in hand, so
        // the list is replaced whole: an address deleted there is dropped here.
        ImportBcShipToAddress::dispatch(['customerNo' => $customerNumber, 'rows' => $rows], replaceAll: true);

        $this->info(sprintf('Queued %d address(es) for %s as one replacement.', count($rows), $customerNumber));

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

        SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_SHIP_TO_ADDRESSES)->fill([
            'last_run_at' => now(),
            'last_run_rows' => $result->rows,
            'last_run_pages' => $result->pages,
            'last_full_sync_at' => now(),
        ])->save();
    }
}
