<?php

namespace App\Sync\Payload;

use App\DocumentAttachments\AttachmentParentType;
use App\Models\DocumentAttachment;

/**
 * The attachments a parent record's website payload carries.
 *
 * Attachments are never delivered on their own: the website stores them as a
 * repeater on the product, customer or order post, so they travel as one field
 * of that record's payload and are replaced whole. This builds that field, and
 * is shared by the three payload builders so all three agree on its shape.
 *
 * The shape matches what the website's applier writes: an entry per file with
 * the Business Central attachment id it matches on and the filename it shows.
 * The bytes are not here — the website renders a link back to this application's
 * /bc-doc/{attachmentId} route, which streams the file from Business Central on
 * demand.
 */
final class AttachmentsPayload
{
    /**
     * The files on one record, in a deterministic order.
     *
     * Sorted by filename then id so the same files always produce the same
     * list. The payload is hashed to decide whether a delivery is owed, and an
     * ordering that moved between reads would open work that changes nothing.
     *
     * @return list<array{bc_id: string, file_name: string}>
     */
    public static function for(AttachmentParentType $parentType, string $parentBcId): array
    {
        if (trim($parentBcId) === '') {
            return [];
        }

        return DocumentAttachment::query()
            ->forParent($parentType, $parentBcId)
            ->orderBy('file_name')
            ->orderBy('bc_id')
            ->get()
            ->map(static fn (DocumentAttachment $attachment): array => [
                'bc_id' => (string) $attachment->bc_id,
                'file_name' => (string) $attachment->file_name,
            ])
            ->all();
    }
}
