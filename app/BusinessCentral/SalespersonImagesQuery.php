<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page used to read a salesperson's image.
 *
 * Unlike every other image in this application, this one is NOT a media
 * stream. The custom page returns the picture inline as a base64 string in an
 * ordinary JSON field, so it is read with getCustom() and decoded, and there is
 * no mediaReadLink to follow.
 *
 * Nothing here is imported; the bytes are served on demand through this
 * application's /bc-salesperson-image/{id} route.
 */
final class SalespersonImagesQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'salespersonImages';

    /**
     * The JSON field carrying the base64-encoded image.
     */
    public const IMAGE_FIELD = 'imageBase64';

    /**
     * The custom API path to one salesperson's image record.
     *
     * The record is fetched whole rather than as a media stream, so getCustom()
     * receives the normal JSON object it expects.
     */
    public static function path(string $id): string
    {
        return sprintf('%s(%s)', self::ENTITY_SET, self::guid($id));
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
