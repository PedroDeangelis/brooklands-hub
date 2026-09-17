<?php

namespace App\BusinessCentral\Import;

use App\DocumentAttachments\AttachmentParentType;
use Illuminate\Database\Eloquent\Model;

/**
 * The outcome of importing one Business Central document attachment row, or of
 * replacing every attachment on one parent record.
 *
 * Carries the parent rather than the attachment, because the parent is what a
 * change means work for: an attachment is a field of its parent's website
 * payload, so it is the parent's delivery that has to be reopened.
 */
final readonly class DocumentAttachmentImportResult
{
    /**
     * @param  Model|null  $parent  The local record the file hangs off.
     * @param  AttachmentParentType|null  $parentType  Which kind of record that is.
     * @param  bool  $changed  Whether the parent's attachment list actually moved.
     * @param  bool  $skipped  Whether nothing could be done with the row.
     * @param  string|null  $skipReason  Why, for the log that has to explain it.
     */
    private function __construct(
        public ?Model $parent,
        public ?AttachmentParentType $parentType,
        public bool $changed,
        public bool $skipped = false,
        public ?string $skipReason = null,
    ) {}

    public static function attached(Model $parent, AttachmentParentType $parentType, bool $changed): self
    {
        return new self($parent, $parentType, $changed);
    }

    /**
     * The row names a parent type this application cannot place.
     *
     * Business Central attaches documents to purchase invoices, journals and
     * resources as well as to the three kinds of record the website stores.
     * Those are valid rows with nowhere to go, so they are skipped rather than
     * treated as errors — and a query filter already asks for only the three,
     * so reaching this means Business Central returned something unexpected.
     */
    public static function unsupportedParentType(?string $parentType): self
    {
        return new self(null, null, false, skipped: true, skipReason: sprintf(
            'parent type "%s" has no local record',
            (string) $parentType,
        ));
    }

    /**
     * No local record carries this parent's Business Central id.
     *
     * Deferred rather than dropped: the parent has almost certainly not been
     * imported yet, and because attachments are swept in full every run, the
     * next sweep picks the row up once it has been. Nothing is stored in the
     * meantime, because a stored orphan is a row nothing would reconcile.
     */
    public static function parentMissing(AttachmentParentType $parentType, string $parentBcId): self
    {
        return new self(null, $parentType, false, skipped: true, skipReason: sprintf(
            'no local %s carries Business Central id %s',
            $parentType->value,
            $parentBcId,
        ));
    }
}
