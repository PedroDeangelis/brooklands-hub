<?php

namespace App\BusinessCentral;

/**
 * The Business Central standard API path used to read an item's picture.
 *
 * A standard page rather than a custom one: an item's picture ships with
 * Business Central, so it needs no publisher or group and is read through
 * getStandard() and the api_version the other standard pages use. That is why
 * this is separate from ItemsQuery, which reads the custom itemsExt page.
 *
 * WHAT IS NOT FETCHED BY THE SYNC
 *
 * Nothing here is imported. A picture is a media stream measured in hundreds of
 * kilobytes, and the website stores no bytes: it renders a link back through
 * this application's /bc-image/{itemId} route, which streams the picture from
 * Business Central on demand. This class exists for that route alone.
 */
final class ItemPictureQuery
{
    public const ENTITY_SET = 'items';

    /**
     * The media stream carrying the picture itself.
     */
    public const PICTURE_PATH = 'picture';

    /**
     * The field on the metadata response holding the URL the bytes are at.
     *
     * A media stream is never addressed by a URL we compose; the metadata read
     * hands us this link and it is followed as given.
     */
    public const MEDIA_READ_LINK = 'pictureContent@odata.mediaReadLink';

    /**
     * The standard API path to one item's picture metadata.
     *
     * Metadata first, bytes second: the response carries both the etag the
     * cache is keyed by and the mediaReadLink the bytes are fetched from.
     */
    public static function metadataPath(string $itemId): string
    {
        return sprintf('%s(%s)/%s', self::ENTITY_SET, self::guid($itemId), self::PICTURE_PATH);
    }

    /**
     * A GUID as an OData literal: unquoted in OData v4, and stripped of
     * anything that is not GUID-shaped so a path parameter cannot inject.
     */
    private static function guid(string $value): string
    {
        return preg_replace('/[^0-9a-fA-F-]/', '', $value);
    }
}
