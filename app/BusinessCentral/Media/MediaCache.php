<?php

namespace App\BusinessCentral\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The on-disk cache for pictures streamed out of Business Central.
 *
 * WHY PICTURES ARE CACHED AND PDFs ARE NOT
 *
 * A picture is immutable for the life of its etag, so a hit can be served
 * without calling Business Central at all — which matters because a catalogue
 * page renders dozens of them at once. A PDF is not cached: an invoice is
 * rendered on demand, is read once or twice ever, and a stale one would be a
 * document someone acts on.
 *
 * The etag is part of the filename rather than the cache's metadata. That makes
 * a replaced picture a different file instead of an overwrite, so a stale entry
 * can never be served and nothing has to be invalidated: the old file simply
 * stops being asked for.
 */
class MediaCache
{
    /**
     * The private disk, not the public one. These files are reachable only
     * through a signed route, and a public disk would expose the whole cache
     * directly under /storage.
     */
    private const DISK = 'local';

    public function __construct(private readonly ?Filesystem $disk = null) {}

    /**
     * The cached file's absolute path, or null when it is not cached.
     *
     * An absolute path rather than the bytes, so the caller can hand the file
     * to the response and let the web server stream it.
     */
    public function path(string $directory, string $key, string $extension): ?string
    {
        $relative = $this->relativePath($directory, $key, $extension);

        return $this->disk()->exists($relative)
            ? $this->disk()->path($relative)
            : null;
    }

    /**
     * Store the bytes and return the absolute path they were written to.
     */
    public function put(string $directory, string $key, string $extension, string $contents): string
    {
        $relative = $this->relativePath($directory, $key, $extension);

        $this->disk()->put($relative, $contents);

        return $this->disk()->path($relative);
    }

    /**
     * The path one cached picture lives at.
     *
     * Both the id and the etag are reduced to filename-safe characters. An etag
     * arrives quoted and may carry slashes, and a raw one would either escape
     * the cache directory or fail to open.
     */
    private function relativePath(string $directory, string $key, string $extension): string
    {
        return sprintf(
            '%s/%s.%s',
            trim($directory, '/'),
            self::safe($key),
            self::safe($extension),
        );
    }

    /**
     * Reduce a value to the characters a filename may safely carry.
     */
    public static function safe(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $value);

        return $safe === '' ? 'unknown' : $safe;
    }

    private function disk(): Filesystem
    {
        return $this->disk ?? Storage::disk(self::DISK);
    }
}
