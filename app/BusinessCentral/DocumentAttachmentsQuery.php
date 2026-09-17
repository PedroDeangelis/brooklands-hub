<?php

namespace App\BusinessCentral;

/**
 * The Business Central standard API page used to read document attachments.
 *
 * A standard page rather than a custom one: documentAttachments ships with
 * Business Central and needs no publisher or group, so it is read through
 * getStandard() and the api_version the rest of the standard pages use.
 *
 * WHY THIS ONE IS SWEPT
 *
 * Read as a sweep, never incrementally, for the same reason as ship-to
 * addresses: an attachment can arrive before the record it hangs off, and an
 * incremental checkpoint would then advance past it and lose the file until
 * Business Central next touched it. An attachment is also the kind of row that
 * is added once and never edited again, so it would sit skipped indefinitely.
 *
 * Deletion is the second reason. Business Central simply stops returning a
 * deleted attachment, and nothing about a row that is no longer there can be
 * noticed by a fetch that only asks for what changed. Replacing a parent's list
 * wholesale — which only a complete sweep may do — is what makes a deletion
 * propagate.
 *
 * WHAT IS NOT FETCHED
 *
 * Not the file itself. attachmentContent is a media stream measured in
 * megabytes, and the website never stores the bytes: it keeps the filename and
 * the attachment id, and renders a link back through this application's
 * /bc-doc/{attachmentId} route, which streams the file from Business Central on
 * demand. Carrying the content through the sync would move gigabytes to store
 * something nothing reads.
 */
final class DocumentAttachmentsQuery
{
    public const ENTITY_SET = 'documentAttachments';

    /**
     * The field on an attachment holding the URL its bytes are at.
     */
    public const MEDIA_READ_LINK = 'attachmentContent@odata.mediaReadLink';

    /**
     * The fields the attachment import reads.
     *
     * parentId is the GUID of the record the file hangs off, and parentType
     * says which kind of record that is — the two only mean anything together,
     * because ids are only unique within a type.
     */
    public const SELECT = 'id,fileName,parentId,parentType,lastModifiedDateTime';

    /**
     * Ordering used for every paged read.
     *
     * id alone is a total ordering, which is all a sweep needs. A timestamp
     * ordering would be partial — many attachments are created in the same
     * second — and $skip would then repeat and skip rows as the server broke
     * ties differently between calls.
     */
    public const ORDER_BY = 'id asc';

    /**
     * The parent types that have somewhere to go.
     *
     * Business Central attaches documents to far more than these — purchase
     * documents, journals, resources — and fetching them would queue rows that
     * every importer could only skip. The website stores attachments on exactly
     * three post types, so these are the three that are asked for.
     *
     * @var array<int, string>
     */
    public const PARENT_TYPES = ['Item'];

    /**
     * Build the OData query for one page of attachment rows.
     *
     * @return array<string, scalar>
     */
    public static function page(int $pageSize, int $skip = 0): array
    {
        $query = [
            '$select' => self::SELECT,
            '$orderby' => self::ORDER_BY,
            '$filter' => self::parentTypeFilter(),
            '$top' => $pageSize,
        ];

        if ($skip > 0) {
            $query['$skip'] = $skip;
        }

        return $query;
    }

    /**
     * Every attachment hanging off one parent record.
     *
     * Used by the manual single-parent fetch, and by nothing scheduled: it asks
     * for one record's files rather than a slice of a result set, so there is
     * no position to advance.
     *
     * @return array<string, scalar>
     */
    public static function forParent(string $parentId): array
    {
        return [
            '$select' => self::SELECT,
            '$orderby' => self::ORDER_BY,
            '$filter' => sprintf(
                '(%s) and parentId eq %s',
                self::parentTypeFilter(),
                self::guid($parentId),
            ),
        ];
    }

    /**
     * The standard API path to one attachment's metadata.
     *
     * Read to obtain the filename and the mediaReadLink; the bytes themselves
     * are then streamed from that link, never from a URL composed here.
     */
    public static function metadataPath(string $attachmentId): string
    {
        return sprintf('%s(%s)', self::ENTITY_SET, self::guid($attachmentId));
    }

    /**
     * The filter narrowing a fetch to the parent types we can place.
     *
     * Written as an OR chain rather than an "in" list: this endpoint family
     * rejects some set operators outright, and an OR over one field is the
     * form every version accepts.
     */
    public static function parentTypeFilter(): string
    {
        return implode(' or ', array_map(
            static fn (string $type): string => sprintf("parentType eq '%s'", self::escape($type)),
            self::PARENT_TYPES,
        ));
    }

    /**
     * A GUID as an OData literal.
     *
     * Business Central types parentId as a GUID rather than a string, so it is
     * compared unquoted. Quoting it makes the filter a type mismatch and the
     * request is rejected.
     */
    private static function guid(string $value): string
    {
        return preg_replace('/[^0-9a-fA-F-]/', '', $value);
    }

    /**
     * Escape a value for an OData string literal.
     *
     * A single quote ends the literal, so one inside the value must be doubled
     * or the filter becomes malformed and the request is rejected.
     */
    private static function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
