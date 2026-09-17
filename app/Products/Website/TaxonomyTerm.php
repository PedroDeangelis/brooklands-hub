<?php

namespace App\Products\Website;

/**
 * A Business Central dimension value, used for brand and the category levels.
 *
 * The code is the stable identifier; the title is the display name shown on the
 * website. Business Central sometimes omits the name, in which case the code is
 * the only thing available to display.
 */
final readonly class TaxonomyTerm
{
    public function __construct(
        public string $code,
        public string $title,
    ) {}

    /**
     * Build from a raw dimension row, or return null when it carries no usable code.
     *
     * @param  array<string, mixed>  $dimension
     */
    public static function fromDimension(array $dimension): ?self
    {
        $code = trim((string) ($dimension['dimensionValueCode'] ?? ''));

        if ($code === '') {
            return null;
        }

        $title = trim((string) ($dimension['dimensionValueName'] ?? ''));

        return new self($code, $title === '' ? $code : $title);
    }

    public function label(): string
    {
        return $this->title === $this->code
            ? $this->code
            : sprintf('%s (%s)', $this->title, $this->code);
    }
}
