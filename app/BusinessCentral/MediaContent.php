<?php

namespace App\BusinessCentral;

/**
 * The bytes of one media stream read from Business Central, and its content type.
 *
 * A media stream is the one thing the client cannot return as a decoded array:
 * an item picture or an invoice PDF is binary, and json_decode() on it yields
 * null. This carries the body untouched so a route can stream it straight to
 * the browser.
 */
final readonly class MediaContent
{
    public function __construct(
        public string $body,
        public ?string $contentType = null,
    ) {}

    /**
     * The content type to answer with, falling back to the generic binary type.
     *
     * Business Central does not always send a Content-Type on a media read, and
     * an empty header must not reach the browser as an empty one: a browser
     * shown `Content-Type:` with no value guesses, and guesses wrongly for a PDF.
     */
    public function contentTypeOr(string $fallback = 'application/octet-stream'): string
    {
        return $this->contentType !== null && $this->contentType !== ''
            ? $this->contentType
            : $fallback;
    }
}
