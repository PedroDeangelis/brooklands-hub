<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\DocumentAttachmentsQuery;
use App\BusinessCentral\Sync\BusinessCentralPager;
use App\BusinessCentral\Sync\PagedFetchResult;
use App\DocumentAttachments\AttachmentParentType;
use App\Jobs\ImportBcDocumentAttachment;
use App\Models\DocumentAttachment;
use App\Models\SyncCheckpoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Business Central document attachments and queues one import job per parent.
 *
 * Every run sweeps the whole endpoint, on purpose, for two reasons. An
 * attachment can arrive before the record it hangs off, and an incremental
 * checkpoint would advance past it and lose the file until Business Central next
 * touched it — which for a file added once and never edited could be never. And
 * a deleted attachment simply stops being returned, so nothing about it can be
 * noticed by a fetch that only asks for what changed.
 *
 * Sweeping is affordable because an unchanged attachment costs one fetch and
 * nothing else: the importer finds the parent's list unchanged and opens no
 * delivery work.
 *
 *   (no options)      sweep the whole endpoint
 *   --top=N           manual — at most N rows, add-only, never removes
 *   --parent=GUID     manual — every attachment on that one record
 *
 * --full is accepted and means the same as a plain run, so the scheduled entry
 * reads the same way as the item one.
 */
#[Signature('bc:import-document-attachments
    {--page-size=200 : How many rows to request per Business Central call}
    {--top= : Stop after this many rows in total (manual testing; add-only, never removes)}
    {--full : Sweep the complete endpoint (the default; accepted for symmetry with bc:import-items)}
    {--force : Re-deliver every affected parent even when its attachments have not changed}
    {--parent= : Fetch every attachment on one record by its Business Central id (manual testing)}')]
#[Description('Fetch document attachments from Business Central and queue an import job for each parent')]
class ImportBcDocumentAttachmentsCommand extends Command
{
    public function handle(BusinessCentralClient $client, BusinessCentralPager $pager): int
    {
        $pageSize = (int) $this->option('page-size');
        $limit = $this->option('top') === null ? null : (int) $this->option('top');
        $parentBcId = trim((string) $this->option('parent'));
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
        if ($force && ! $this->forceIsAllowed($limit, $parentBcId)) {
            return self::FAILURE;
        }

        if ($parentBcId !== '') {
            return $this->importOneParent($client, $parentBcId);
        }

        if ($force && ! $this->confirmForce()) {
            $this->line('Nothing was fetched or queued.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Sweeping every document attachment on %s, page size %d%s.',
            implode(', ', DocumentAttachmentsQuery::PARENT_TYPES),
            $pageSize,
            $limit === null ? '' : ", stopping after {$limit} row(s)",
        ));

        $queued = 0;
        $unsupported = 0;

        // A complete sweep sees every attachment, so it can set each parent's
        // set to exactly what Business Central holds — the only way a deleted
        // file ever leaves. Rows are gathered per parent and handed over as one
        // replace job each. A capped run sees only part of the endpoint and must
        // never remove anything, so it stays one add-only job per row.
        $complete = $limit === null;

        /** @var array<string, array{type: AttachmentParentType, parent_bc_id: string, rows: array<int, array<string, mixed>>}> $byParent */
        $byParent = [];

        try {
            $result = $pager->fetchStandard(
                DocumentAttachmentsQuery::ENTITY_SET,
                fn (int $ask, int $fetched): array => DocumentAttachmentsQuery::page($ask, $fetched),
                function (array $row) use (&$queued, &$unsupported, &$byParent, $force, $complete): void {
                    $type = AttachmentParentType::fromBusinessCentral($row['parentType'] ?? null);

                    // The filter already asks for only the three supported
                    // types, so this is Business Central returning something
                    // unexpected. Counted rather than queued: a job could only
                    // skip it, and a skip per row would drown the log.
                    if ($type === null) {
                        $unsupported++;

                        return;
                    }

                    if (! $complete) {
                        ImportBcDocumentAttachment::dispatch($row, $force);
                        $queued++;

                        return;
                    }

                    $parentBcId = trim((string) ($row['parentId'] ?? ''));

                    if ($parentBcId === '') {
                        return;
                    }

                    // Keyed by both parts: ids are only unique within a type.
                    $key = $type->value.':'.$parentBcId;

                    $byParent[$key] ??= ['type' => $type, 'parent_bc_id' => $parentBcId, 'rows' => []];
                    $byParent[$key]['rows'][] = $row;
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

            if ($e->bodyExcerpt !== null) {
                $this->line("Response: {$e->bodyExcerpt}");
            }

            $this->warn(sprintf('Fetch failed after queueing %d row(s).', $queued));

            Log::error('bc.import_document_attachments.failed', [
                'queued' => $queued,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if ($unsupported > 0) {
            $this->line(sprintf('  %d row(s) hang off a record type this application does not store; ignored.', $unsupported));
        }

        if ($complete && $result->complete) {
            $queued += $this->queueReplacements($byParent, $force);
        }

        if ($result->rows === 0) {
            $this->warn('No document attachment rows returned from Business Central.');

            $this->recordRun($result);

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf('Queued %d job(s) from %d row(s) across %d request(s).', $queued, $result->rows, $result->pages));

        $this->recordRun($result);

        return self::SUCCESS;
    }

    /**
     * Hand each parent its complete set, and clear the parents that lost theirs.
     *
     * @param  array<string, array{type: AttachmentParentType, parent_bc_id: string, rows: array<int, array<string, mixed>>}>  $byParent
     * @return int How many jobs were queued.
     */
    private function queueReplacements(array $byParent, bool $force): int
    {
        $queued = 0;

        foreach ($byParent as $parent) {
            ImportBcDocumentAttachment::dispatch([
                'parentType' => $parent['type']->value,
                'parentId' => $parent['parent_bc_id'],
                'rows' => $parent['rows'],
            ], $force, replaceAll: true);

            $queued++;
        }

        // A record whose every attachment was deleted no longer appears in the
        // sweep at all, so its set would never be cleared by the loop above.
        // Any parent still holding attachments this sweep did not see gets an
        // empty replacement.
        $seen = array_keys($byParent);

        $orphaned = DocumentAttachment::query()
            ->select(['parent_type', 'parent_bc_id'])
            ->distinct()
            ->get()
            ->reject(static fn (DocumentAttachment $attachment): bool => in_array(
                $attachment->parent_type.':'.$attachment->parent_bc_id,
                $seen,
                true,
            ));

        foreach ($orphaned as $attachment) {
            $type = $attachment->parentType();

            if ($type === null) {
                continue;
            }

            ImportBcDocumentAttachment::dispatch([
                'parentType' => $type->value,
                'parentId' => $attachment->parent_bc_id,
                'rows' => [],
            ], $force, replaceAll: true);

            $queued++;
        }

        if ($orphaned->isNotEmpty()) {
            $this->line(sprintf(
                '  %d record(s) no longer have any attachment in Business Central; clearing.',
                $orphaned->count(),
            ));
        }

        return $queued;
    }

    /**
     * Fetch and queue every attachment on one record.
     *
     * A deliberate detour around the paged path: this asks for one record's
     * files rather than a slice of a result set. It still replaces the set
     * wholesale, because it has seen all of that parent's attachments — which
     * is exactly the condition a replace needs, and makes this the way to fix
     * one record whose files have drifted.
     */
    private function importOneParent(BusinessCentralClient $client, string $parentBcId): int
    {
        $this->line(sprintf('Fetching every document attachment on %s.', $parentBcId));

        try {
            $response = $client->getStandard(
                DocumentAttachmentsQuery::ENTITY_SET,
                DocumentAttachmentsQuery::forParent($parentBcId),
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            if ($e->url !== null) {
                $this->line("URL: {$e->url}");
            }

            return self::FAILURE;
        }

        $rows = array_values(array_filter($response['value'] ?? [], 'is_array'));

        if ($rows === []) {
            $this->warn(sprintf('No Business Central document attachment hangs off %s.', $parentBcId));

            return self::SUCCESS;
        }

        // Every row shares one parent, so the type is read from the first.
        $type = AttachmentParentType::fromBusinessCentral($rows[0]['parentType'] ?? null);

        if ($type === null) {
            $this->warn(sprintf(
                'Those attachments hang off a "%s", which this application does not store.',
                (string) ($rows[0]['parentType'] ?? 'unknown'),
            ));

            return self::SUCCESS;
        }

        ImportBcDocumentAttachment::dispatch([
            'parentType' => $type->value,
            'parentId' => $parentBcId,
            'rows' => $rows,
        ], false, replaceAll: true);

        $this->info(sprintf('Queued %d attachment(s) on one %s.', count($rows), $type->label()));

        return self::SUCCESS;
    }

    /**
     * Record that a run happened.
     *
     * Only ever a record, never a position: this endpoint is swept, so there is
     * no watermark to advance and last_modified_at stays null. The run
     * timestamps are what makes a stalled sweep visible.
     */
    private function recordRun(PagedFetchResult $result): void
    {
        SyncCheckpoint::forEntity(SyncCheckpoint::ENTITY_DOCUMENT_ATTACHMENTS)->fill([
            'last_run_at' => now(),
            'last_run_rows' => $result->rows,
            'last_run_pages' => $result->pages,
            'last_full_sync_at' => $result->complete ? now() : null,
        ])->save();
    }

    /**
     * Whether --force makes sense alongside the other options given.
     *
     * No --full requirement: this command sweeps the whole endpoint on every
     * run, so --full is already a no-op here. --top and --parent still read a
     * subset, and forcing one of those would re-send an arbitrary slice.
     */
    private function forceIsAllowed(?int $limit, string $parentBcId): bool
    {
        if ($limit !== null) {
            $this->error('--force cannot be combined with --top.');

            return false;
        }

        if ($parentBcId !== '') {
            $this->error('--force cannot be combined with --parent.');

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
        $this->warn('Forcing re-delivery of every record that carries an attachment.');
        $this->line('Each parent will be queued for WordPress even if its attachments have not changed.');

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('Continue?', false);
    }
}
