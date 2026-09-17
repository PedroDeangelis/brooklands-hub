<?php

namespace App\Products\Website;

/**
 * A Business Central item attribute shown as a product specification.
 */
final readonly class ProductAttribute
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}
}
