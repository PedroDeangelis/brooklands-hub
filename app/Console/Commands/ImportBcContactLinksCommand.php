<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\CustomerContactsQuery;
use App\BusinessCentral\Sync\BusinessCentralPager;
use App\BusinessCentral\Sync\PagedFetchResult;
use App\Jobs\ImportBcContactLink;
use App\Models\Contact;
use App\Models\SyncCheckpoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the Business Central contact → customer links and queues one job
 * per row.
 *
 * The link lives on the standard API's customerContacts page, not on the
 * contact. Every run sweeps the whole page, on purpose: it has no
 * lastModifiedDateTime to checkpoint against, and a link arriving before its
 * contact is skipped and has to be seen again. It is ~1,400 rows — seven
 * requests — so re-reading it is cheaper than being wrong.
 *
 * The sweep is also what re-evaluates every linked contact's website rules,
 * because three of them depend on other records. See ImportBcContactLink.
 *
 *   (no options)    sweep the whole page
 *   --top=N         manual — at most N rows, then stop
 *   --contact=NO    manual — the link for that one contact, by its number
 *
 * --full is accepted and means the same as a plain run, so the scheduled
 * entry reads the same way as the other imports.
 */
#[Signature('bc:import-contact-links
    {--page-size=200 : How many rows to request per Business Central call}
    {--top= : Stop after this many rows in total (manual testing)}
    {--full : Sweep the complete page (the default; accepted for symmetry with the other imports)}
    {--force : Re-deliver every linked contact that qualifies, even when nothing changed}
    {--contact= : Fetch the link for one contact by its Business Central number (manual testing)}')]
#[Description('Fetch contact-to-customer links from Business Central and queue an import job for each one')]
class ImportBcContactLinksCommand extends Command
{
    public function handle(BusinessCentralClient $client, BusinessCentralPager $pager): int
    {
        $pageSize = (int) $this->option('page-size');
        $limit = $this->option('top') === null ? null : (int) $this->option('top');
        $contactNumber = trim((string) $this->option('contact'));
        $force = (bool) $this->option('force');

        if ($pageSize < 1) {
            $this->error('--page-size must be a positive integer.');

            return self::FAILURE;
        }

        if ($limit !== null && $limit < 1) {
            $this->error('--top must be a positive integer.');

            return self::FAILURE;
        }

        if ($force && ! $this->forceIsAllowed($limit, $contactNumber)) {
            return self::FAILURE;
        }

        if ($contactNumber !== '') {
            return $this->importOneContact($client, $contactNumber);
        }

        if ($force && ! $this->confirmForce()) {
            $this->line('Nothing was fetched or queued.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Sweeping the complete contact link list, page size %d%s.',
            $pageSize,
            $limit === null ? '' : ", stopping after {$limit} row(s)",
        ));

        $queued = 0;

        /** @var array<int, string> $seen */
        $seen = [];

        try {
            $result = $pager->fetchStandard(
                CustomerContactsQuery::ENTITY_SET,
                fn (int $ask, int $fetched): array => CustomerContactsQuery::page($ask, $fetched),
                function (array $row) use (&$queued, &$seen, $force): void {
                    $id = (string) ($row['id'] ?? '');

                    if ($id === '') {
                        return;
                    }

                    $seen[] = $id;

                    ImportBcContactLink::dispatch($row, $force);
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

            Log::error('bc.import_contact_links.failed', [
                'queued' => $queued,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if ($limit === null && $result->complete) {
            // A contact detached in Business Central no longer appears on the
            // page at all, so no row above would ever clear its link. Any
            // contact still linked that this sweep did not see is unlinked.
            $orphaned = Contact::query()
                ->whereNotNull('customer_bc_id')
                ->pluck('bc_id')
                ->diff($seen)
                ->values();

            foreach ($orphaned as $bcId) {
                ImportBcContactLink::dispatch(['id' => $bcId, 'customerId' => ''], $force);
                $queued++;
            }

            if ($orphaned->isNotEmpty()) {
                $this->line(sprintf('  %d contact(s) are no longer linked to a customer in Business Central; unlinking.', $orphaned->count()));
            }
        }

        if ($result->rows === 0) {
            $this->warn('No contact link rows returned from Business Central.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf('Queued %d row(s) across %d request(s).', $queued, $result->pages));

        $this->recordRun($result, $limit);

        return self::SUCCESS;
    }

    /**
     * Whether --force makes sense alongside the other options given.
     *
     * No --full requirement: this command sweeps the whole page on every run,
     * so --full is already a no-op here. --top and --contact still read a
     * subset, and forcing one of those would re-send an arbitrary slice.
     */
    private function forceIsAllowed(?int $limit, string $contactNumber): bool
    {
        if ($limit !== null) {
            $this->error('--force cannot be combined with --top.');

            return false;
        }

        if ($contactNumber !== '') {
            $this->error('--force cannot be combined with --contact.');

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
        $this->warn('Forcing re-delivery of every linked contact that qualifies.');
        $this->line('Each one will be queued for WordPress even if nothing changed.');

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('Continue?', false);
    }

    /**
     * Fetch and queue the link for one contact.
     *
     * The page is keyed by the contact's Business Central id, so the number is
     * resolved locally first; a contact that has not been imported cannot be
     * linked yet in any case.
     */
    private function importOneContact(BusinessCentralClient $client, string $contactNumber): int
    {
        $contact = Contact::query()->where('number', $contactNumber)->first();

        if ($contact === null) {
            $this->warn(sprintf('No local contact has the number "%s"; import it first with bc:import-contacts --number=%1$s.', $contactNumber));

            return self::SUCCESS;
        }

        $this->line(sprintf('Fetching the customer link for contact %s (%s).', $contactNumber, $contact->bc_id));

        try {
            $response = $client->getStandard(
                CustomerContactsQuery::ENTITY_SET,
                CustomerContactsQuery::forContactId($contact->bc_id),
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $response['value'] ?? [];
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        if ($rows === []) {
            // Everything Business Central holds for this contact is in hand,
            // and it holds no link: the contact is unlinked.
            $this->warn(sprintf('Business Central links contact "%s" to no customer; unlinking.', $contactNumber));

            ImportBcContactLink::dispatch(['id' => $contact->bc_id, 'customerId' => '']);

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            ImportBcContactLink::dispatch($row);

            $this->line(sprintf('  queued link to customer %s (%s)', $row['customerName'] ?? '?', $row['customerId'] ?? 'unknown id'));
        }

        $this->info(sprintf('Queued %d link(s) for %s.', count($rows), $contactNumber));

        return self::SUCCESS;
    }

    /**
     * Record what this run did, for visibility rather than for resuming.
     *
     * There is no position to resume from: every run sweeps the whole page.
     * A capped run is not recorded at all, so a manual test cannot make the
     * schedule look like it completed a sweep it never did.
     */
    private function recordRun(PagedFetchResult $result, ?int $limit): void
    {
        if ($limit !== null) {
            $this->warn('--top was given, so this run was not recorded as a full sweep.');

            return;
        }

        SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_CONTACT_LINKS)->fill([
            'last_run_at' => now(),
            'last_run_rows' => $result->rows,
            'last_run_pages' => $result->pages,
            'last_full_sync_at' => now(),
        ])->save();
    }
}
