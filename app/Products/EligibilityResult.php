<?php

namespace App\Products;

/**
 * Whether a product may appear on the website, and every reason it may not.
 *
 * Products that fail a rule are still imported and kept, so the result must
 * carry the full explanation rather than only the first rule that failed: a
 * product can be both unpriced and retired, and fixing one leaves it excluded.
 */
final readonly class EligibilityResult
{
    /**
     * @param  list<Exclusion>  $exclusions
     */
    private function __construct(
        public bool $eligible,
        public array $exclusions,
    ) {}

    public static function eligible(): self
    {
        return new self(true, []);
    }

    /**
     * @param  list<Exclusion>  $exclusions
     */
    public static function excluded(array $exclusions): self
    {
        return $exclusions === []
            ? self::eligible()
            : new self(false, array_values($exclusions));
    }

    /**
     * Human-readable status for display.
     */
    public function label(): string
    {
        return $this->eligible ? 'Eligible' : 'Excluded';
    }

    /**
     * Every exclusion reason, in the order the rules were evaluated.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_map(fn (Exclusion $exclusion): string => $exclusion->describe(), $this->exclusions);
    }

    /**
     * The first reason, for places with room for only one line.
     *
     * Prefer reasons() wherever the full explanation fits: a single reason can
     * mislead a person into fixing one rule and expecting the product to appear.
     */
    public function reason(): ?string
    {
        return $this->reasons()[0] ?? null;
    }

    public function hasReason(ExclusionReason $reason): bool
    {
        foreach ($this->exclusions as $exclusion) {
            if ($exclusion->is($reason)) {
                return true;
            }
        }

        return false;
    }

    public function reasonCount(): int
    {
        return count($this->exclusions);
    }
}
