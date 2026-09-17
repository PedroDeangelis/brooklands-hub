<?php

namespace App\BusinessCentral;

/**
 * The Business Central standard API path used to read a customer's picture.
 *
 * The customer twin of ItemPictureQuery, and separate from it for the same
 * reason ItemPictureQuery is separate from ItemsQuery: the picture is a
 * standard-API media stream, while customersExt is a custom page.
 *
 * Nothing here is imported either. The bytes are streamed on demand through
 * this application's /bc-customer-image/{customerId} route.
 */
final class CustomerPictureQuery
{
    public const ENTITY_SET = 'customers';

    public const PICTURE_PATH = 'picture';

    public const MEDIA_READ_LINK = 'pictureContent@odata.mediaReadLink';

    /**
     * The standard API path to one customer's picture metadata.
     */
    public static function metadataPath(string $customerId): string
    {
        return sprintf('%s(%s)/%s', self::ENTITY_SET, self::guid($customerId), self::PICTURE_PATH);
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
