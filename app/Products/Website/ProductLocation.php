<?php

namespace App\Products\Website;

/**
 * The stock location selected for the website, and its on-hand figure.
 *
 * Selection is by precedence, never by quantity: see LocationSelector.
 */
final readonly class ProductLocation
{
    public function __construct(
        public ?string $code,
        public int $inventory,
    ) {}

    public static function none(): self
    {
        return new self(null, 0);
    }

    public function isResolved(): bool
    {
        return $this->code !== null;
    }
}
