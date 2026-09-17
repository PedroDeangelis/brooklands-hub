<?php

namespace App\Http\Controllers;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\CustomerPictureQuery;
use App\BusinessCentral\ItemPictureQuery;
use App\BusinessCentral\Media\MediaCache;
use App\BusinessCentral\SalespersonImagesQuery;
use finfo;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams pictures out of Business Central for the website to render.
 *
 * Reached only through the bc.signed middleware: the website builds a signed
 * URL and the browser fetches it, so these responses are the one part of this
 * application a public visitor's browser touches.
 *
 * Nothing here is imported by the sync. A picture is a media stream and the
 * website stores no bytes, for the reason documented on ItemPictureQuery.
 */
class BcImageController extends Controller
{
    /**
     * How long a browser may reuse a picture.
     *
     * A day is safe because the URL is etag-keyed on our side: a replaced
     * picture is a different cache entry, and the website re-renders the link.
     */
    private const BROWSER_CACHE_SECONDS = 86400;

    public function __construct(
        private readonly BusinessCentralClient $client,
        private readonly MediaCache $cache,
    ) {}

    /**
     * One item's picture.
     */
    public function item(string $itemId): Response|BinaryFileResponse
    {
        return $this->picture(
            ItemPictureQuery::metadataPath($itemId),
            ItemPictureQuery::MEDIA_READ_LINK,
            'bc-images',
            $itemId,
        );
    }

    /**
     * One customer's picture.
     */
    public function customer(string $customerId): Response|BinaryFileResponse
    {
        return $this->picture(
            CustomerPictureQuery::metadataPath($customerId),
            CustomerPictureQuery::MEDIA_READ_LINK,
            'bc-customer-images',
            $customerId,
        );
    }

    /**
     * One salesperson's image.
     *
     * Handled apart from the other two because this one is not a media stream:
     * the custom page returns the picture base64-encoded in a JSON field, so
     * there is no mediaReadLink to follow and nothing to cache by etag.
     */
    public function salesperson(string $id): Response
    {
        $record = $this->client->getCustom(
            SalespersonImagesQuery::PUBLISHER,
            SalespersonImagesQuery::GROUP,
            SalespersonImagesQuery::VERSION,
            SalespersonImagesQuery::path($id),
        );

        $encoded = $record[SalespersonImagesQuery::IMAGE_FIELD] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            abort(404);
        }

        // A data URI prefix ("data:image/png;base64,...") is not part of the
        // payload and decodes to nothing, so it is dropped when present.
        if (str_contains($encoded, ',')) {
            [, $encoded] = explode(',', $encoded, 2);
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            abort(422, 'Invalid base64 image data.');
        }

        // The page does not say what kind of image it encoded, so the type is
        // sniffed from the bytes rather than assumed.
        $contentType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        return response($bytes, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age='.self::BROWSER_CACHE_SECONDS,
        ]);
    }

    /**
     * Serve one picture, from the disk cache when it is already there.
     *
     * The metadata read happens either way: it is what supplies the etag the
     * cache is keyed by, so it cannot be skipped on a hit. What a hit avoids is
     * transferring the bytes again, which is the part measured in hundreds of
     * kilobytes.
     */
    private function picture(
        string $metadataPath,
        string $mediaReadLinkField,
        string $cacheDirectory,
        string $id,
    ): Response|BinaryFileResponse {
        $metadata = $this->client->getStandard($metadataPath);

        $mediaReadLink = $metadata[$mediaReadLinkField] ?? null;

        if (! is_string($mediaReadLink) || $mediaReadLink === '') {
            abort(404);
        }

        $etag = is_string($metadata['@odata.etag'] ?? null) ? $metadata['@odata.etag'] : null;
        $contentType = is_string($metadata['contentType'] ?? null) ? $metadata['contentType'] : '';

        $key = $id.'-'.MediaCache::safe($etag ?? 'noetag');
        $extension = str_contains($contentType, 'png') ? 'png' : 'jpg';

        $headers = [
            'Cache-Control' => 'public, max-age='.self::BROWSER_CACHE_SECONDS,
            'ETag' => $etag ?? '',
        ];

        $cached = $this->cache->path($cacheDirectory, $key, $extension);

        if ($cached !== null) {
            return response()->file($cached, $headers);
        }

        $media = $this->client->getMedia($mediaReadLink);

        $this->cache->put($cacheDirectory, $key, $extension, $media->body);

        return response($media->body, 200, $headers + [
            'Content-Type' => $media->contentTypeOr(),
        ]);
    }
}
