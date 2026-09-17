<?php

namespace App\Website;

use RuntimeException;

/**
 * The website destination has not been configured.
 *
 * Thrown when a delivery is attempted, not when the client is built, so the
 * container can still resolve it in tests and previews.
 */
final class WebsiteConfigurationException extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self(sprintf('Website delivery configuration "%s" is not set.', $key));
    }
}
