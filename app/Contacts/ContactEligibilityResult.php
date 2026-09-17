<?php

namespace App\Contacts;

/**
 * Whether a contact may become a website user, and every reason it may not.
 *
 * Contacts that fail a rule are still imported and kept, so the result must
 * carry the full explanation rather than only the first rule that failed: a
 * contact can lack both a customer and an email, and fixing one leaves it
 * excluded.
 *
 * The same shape as the product EligibilityResult, so the dashboard components
 * built for products render this unchanged.
 */
final readonly class ContactEligibilityResult
{
    /**
     * @param  list<ContactExclusion>  $exclusions
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
     * @param  list<ContactExclusion>  $exclusions
     */
    public static function excluded(array $exclusions): self
    {
        return $exclusions === []
            ? self::eligible()
            : new self(false, array_values($exclusions));
    }

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
        return array_map(fn (ContactExclusion $exclusion): string => $exclusion->describe(), $this->exclusions);
    }

    /**
     * The first reason, for places with room for only one line.
     */
    public function reason(): ?string
    {
        return $this->reasons()[0] ?? null;
    }

    public function hasReason(ContactExclusionReason $reason): bool
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
