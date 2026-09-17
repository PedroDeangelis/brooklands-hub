<?php

namespace App\BusinessCentral\Import;

use App\DocumentAttachments\AttachmentParentType;
use App\Models\DocumentAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Normalises a raw Business Central documentAttachments row and stores it.
 *
 * The only place that knows how the attachment field names map onto local
 * columns, and the only place that decides which local record a file belongs to.
 *
 * Two entry points, for the same reason as ship-to addresses. import() adds or
 * replaces one attachment and leaves the rest of its parent's alone, which is
 * all a capped run may do. replace() sets a parent's attachments to exactly the
 * rows given, which is the only way a file deleted in Business Central is ever
 * removed here — a deleted row simply stops being returned, so nothing about it
 * can be noticed one row at a time.
 */
class DocumentAttachmentImporter
{
    /**
     * Set one parent's attachments to exactly these rows.
     *
     * Only a complete sweep may call this. A capped run has seen part of the
     * endpoint, and replacing from a partial view would delete every attachment
     * whose row happened to fall outside it.
     *
     * @param  array<int, array<string, mixed>>  $rows  Every attachment row for one parent.
     *
     * @throws InvalidArgumentException when the parent id is empty.
     */
    public function replace(
        AttachmentParentType $parentType,
        string $parentBcId,
        array $rows,
    ): DocumentAttachmentImportResult {
        $parentBcId = trim($parentBcId);

        if ($parentBcId === '') {
            throw new InvalidArgumentException('A parent Business Central id is required to replace attachments.');
        }

        $parent = $this->findParent($parentType, $parentBcId);

        if ($parent === null) {
            return DocumentAttachmentImportResult::parentMissing($parentType, $parentBcId);
        }

        $existing = DocumentAttachment::query()
            ->forParent($parentType, $parentBcId)
            ->get()
            ->keyBy('bc_id');

        $changed = false;
        $seen = [];

        foreach ($rows as $row) {
            $bcId = $this->string($row, 'id');

            if ($bcId === '') {
                continue;
            }

            $seen[] = $bcId;

            if ($this->store($bcId, $parentType, $parentBcId, $row)) {
                $changed = true;
            }
        }

        // Whatever the parent used to have and this sweep did not see has been
        // deleted in Business Central. This is the only place that can tell.
        $removed = $existing->keys()->diff($seen);

        if ($removed->isNotEmpty()) {
            DocumentAttachment::query()->whereIn('bc_id', $removed->all())->delete();
            $changed = true;
        }

        return DocumentAttachmentImportResult::attached($parent, $parentType, $changed);
    }

    /**
     * Add or replace one attachment, leaving its parent's others alone.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no id or no parent id.
     */
    public function import(array $row): DocumentAttachmentImportResult
    {
        $bcId = $this->string($row, 'id');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central document attachment row is missing an "id".');
        }

        $parentBcId = $this->string($row, 'parentId');

        if ($parentBcId === '') {
            throw new InvalidArgumentException('Business Central document attachment row is missing a "parentId".');
        }

        $parentType = AttachmentParentType::fromBusinessCentral($this->string($row, 'parentType'));

        if ($parentType === null) {
            return DocumentAttachmentImportResult::unsupportedParentType($row['parentType'] ?? null);
        }

        $parent = $this->findParent($parentType, $parentBcId);

        if ($parent === null) {
            return DocumentAttachmentImportResult::parentMissing($parentType, $parentBcId);
        }

        $changed = $this->store($bcId, $parentType, $parentBcId, $row);

        return DocumentAttachmentImportResult::attached($parent, $parentType, $changed);
    }

    /**
     * Write one attachment, reporting whether anything about it actually moved.
     *
     * bc_modified_at and bc_payload are written but do not count as a change:
     * Business Central touches the timestamp when the parent record is saved,
     * not only when a file is added, and the payload mirrors the whole row, so
     * either would open a delivery carrying an identical list.
     *
     * @param  array<string, mixed>  $row
     */
    private function store(
        string $bcId,
        AttachmentParentType $parentType,
        string $parentBcId,
        array $row,
    ): bool {
        $attachment = DocumentAttachment::firstOrNew(['bc_id' => $bcId]);

        $changed = ! $attachment->exists
            || $attachment->file_name !== $this->string($row, 'fileName')
            || $attachment->parent_bc_id !== $parentBcId
            || $attachment->parent_type !== $parentType->value;

        $attachment->fill([
            'bc_id' => $bcId,
            'parent_bc_id' => $parentBcId,
            'parent_type' => $parentType->value,
            'file_name' => $this->string($row, 'fileName'),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => $row,
        ]);

        $attachment->save();

        return $changed;
    }

    /**
     * The local record a file hangs off, or null when it has not arrived.
     *
     * Matched on the Business Central id only. There is nothing else on an
     * attachment row that identifies its parent, and guessing would attach one
     * record's documents to another.
     */
    private function findParent(AttachmentParentType $parentType, string $parentBcId): ?Model
    {
        return $parentType->modelClass()::query()
            ->where('bc_id', $parentBcId)
            ->first();
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        $timestamp = trim((string) $value);

        if ($timestamp === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        return is_numeric($value) ? (string) $value : '';
    }
}
