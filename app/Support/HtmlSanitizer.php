<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitises Business Central marketing copy before it is stored.
 *
 * The copy is authored by people in Business Central and arrives as HTML, so it
 * is untrusted: it reaches a public website verbatim. Cleaning happens on the
 * way in rather than on the way out, so what is stored is what is delivered and
 * a payload can be read without wondering what it will become.
 *
 * One HTMLPurifier instance per process, not per row. Building the config
 * constructs the entire HTML definition set — hundreds of element and attribute
 * definitions — which made it the dominant cost of a full sweep in an earlier
 * implementation that built one per row.
 */
class HtmlSanitizer
{
    /**
     * The tags marketing copy may use.
     *
     * Formatting and structure only: no links, images, or anything that could
     * carry script or layout. Business Central's editor produces <br> heavily,
     * which is why it is here.
     */
    private const ALLOWED = 'b,strong,br,p,ul,ol,li,em,i,u';

    private ?HTMLPurifier $purifier = null;

    /**
     * Clean one piece of marketing copy, returning '' when there is none.
     */
    public function purify(?string $html): string
    {
        // Never pay for an empty string: an item with no copy is common, and
        // purifying '' would build the definition set for nothing.
        if ($html === null || trim($html) === '') {
            return '';
        }

        return $this->purifier()->purify($html);
    }

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= $this->build();
    }

    private function build(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('HTML.ForbiddenAttributes', ['style', 'class', 'id']);

        // The vendor tree can be read-only in a built image, and HTMLPurifier
        // writes its definition cache under vendor/ by default. Point it at
        // storage/ when that is available and skip caching when it is not,
        // rather than letting a cache write fail the import.
        $path = storage_path('app/htmlpurifier');

        if (is_dir($path) && is_writable($path)) {
            $config->set('Cache.SerializerPath', $path);
        }

        return new HTMLPurifier($config);
    }
}
