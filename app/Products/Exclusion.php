<?php

namespace App\Products;

/**
 * One failed website rule, together with the product value that failed it.
 *
 * Keeping the value alongside the reason means the dashboard can show why the
 * rule failed ("Service") without re-reading the product.
 */
final readonly class Exclusion
{
    public function __construct(
        public ExclusionReason $reason,
        public ?string $value = null,
    ) {}

    /**
     * The sentence shown to a person reading the dashboard.
     */
    public function describe(): string
    {
        return $this->reason->describe($this->value);
    }

    public function is(ExclusionReason $reason): bool
    {
        return $this->reason === $reason;
    }
}
