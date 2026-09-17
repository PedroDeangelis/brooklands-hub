<?php

namespace App\Jobs;

use App\BusinessCentral\Import\DocumentAttachmentImporter;
use App\DocumentAttachments\AttachmentParentReconciler;
use App\DocumentAttachments\AttachmentParentType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores one Business Central document attachment, or one parent's whole set.
 *
 * A changed attachment list changes what the website should hold for the record
 * the file hangs off, so the parent is reconciled here — the same way a ship-to
 * address reconciles its customer.
 */
class ImportBcDocumentAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  One raw documentAttachments row, or — when
     *                                     $replaceAll is set — every row for one parent
     *                                     under 'rows', with the parent named by
     *                                     'parentId' and 'parentType'.
     * @param  bool  $force  Deliver the parent even when its attachments have not moved.
     * @param  bool  $replaceAll  Set the parent's attachments to exactly these rows.
     *                            Only a complete sweep may do this; a capped run that
     *                            saw part of the endpoint would delete the rest.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
        public readonly bool $replaceAll = false,
    ) {}

    public function handle(
        DocumentAttachmentImporter $importer,
        AttachmentParentReconciler $reconciler,
    ): void {
        $result = $this->replaceAll
            ? $importer->replace(
                $this->parentType(),
                (string) ($this->row['parentId'] ?? ''),
                $this->row['rows'] ?? [],
            )
            : $importer->import($this->row);

        if ($result->skipped) {
            // Nothing could be done with this row. Recorded rather than silently
            // dropped: a row that keeps being skipped means the parent's import
            // is behind, which is worth being able to see. Every run sweeps the
            // whole endpoint, so the next one retries it.
            Log::info('bc.document_attachment.skipped', [
                'bc_id' => $this->row['id'] ?? null,
                'parent_bc_id' => $this->row['parentId'] ?? null,
                'parent_type' => $this->row['parentType'] ?? null,
                'reason' => $result->skipReason,
            ]);

            return;
        }

        $parent = $result->parent;
        $record = null;

        if ($this->force || $result->changed) {
            $record = $reconciler->reconcile($parent, force: $this->force);
        }

        Log::info('bc.document_attachment.imported', [
            'bc_id' => $this->row['id'] ?? null,
            'parent_bc_id' => $parent->bc_id,
            'parent_type' => $result->parentType?->value,
            'replaced_all' => $this->replaceAll,
            'rows' => $this->replaceAll ? count($this->row['rows'] ?? []) : 1,
            'changed' => $result->changed,
            'forced' => $this->force,
            'delivery_queued' => $record !== null,
        ]);
    }

    /**
     * The parent type a wholesale replace is for.
     *
     * Only read on the replaceAll path, where the type names the set being
     * replaced rather than describing one row. An unrecognised type cannot
     * reach here: the command resolves it before queueing, and only the three
     * supported kinds are ever grouped.
     */
    private function parentType(): AttachmentParentType
    {
        return AttachmentParentType::from((string) ($this->row['parentType'] ?? ''));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        $bcId = $this->row['id'] ?? null;
        $parentId = $this->row['parentId'] ?? null;

        return array_values(array_filter([
            'bc-document-attachment',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
            is_scalar($parentId) ? 'parent:'.$parentId : null,
        ]));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('bc.document_attachment.import_failed', [
            'bc_id' => $this->row['id'] ?? null,
            'parent_bc_id' => $this->row['parentId'] ?? null,
            'parent_type' => $this->row['parentType'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
