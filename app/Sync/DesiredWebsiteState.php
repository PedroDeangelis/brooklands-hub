<?php

namespace App\Sync;

use App\Products\EligibilityResult;
use App\Products\Exclusion;

/**
 * What the website should look like for one product, and why.
 *
 * Derived only from Business Central data: it says nothing about whether the
 * website already agrees. A product that has never been delivered and one that
 * was delivered long ago produce the same desired state.
 */
final readonly class DesiredWebsiteState
{
    /**
     * @param  list<string>  $reasons  Why the product is to be removed; empty for an upsert.
     * @param  list<string>  $reasonCodes  The same reasons as stable enum codes.
     */
    private function __construct(
        public WebsiteAction $action,
        public array $reasons,
        public array $reasonCodes,
    ) {}

    /**
     * Decide what the website should hold for a product.
     *
     * Eligibility is the whole rule: anything that qualifies should be on the
     * website, anything that does not should be off it. An excluded product is
     * deliberately not treated as "nothing to do", because it may already be on
     * the website from when it qualified and now needs taking down.
     */
    public static function from(EligibilityResult $eligibility): self
    {
        if ($eligibility->eligible) {
            return new self(WebsiteAction::Upsert, [], []);
        }

        return new self(
            WebsiteAction::Remove,
            $eligibility->reasons(),
            array_map(
                static fn (Exclusion $exclusion): string => $exclusion->reason->value,
                $eligibility->exclusions,
            ),
        );
    }

    /**
     * The reasons as stable codes, for anything that must survive rewording.
     *
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return $this->reasonCodes;
    }

    public function isUpsert(): bool
    {
        return $this->action === WebsiteAction::Upsert;
    }

    public function isRemoval(): bool
    {
        return $this->action === WebsiteAction::Remove;
    }

    public function label(): string
    {
        return $this->action->label();
    }

    /**
     * Why the website should not hold this product, or null for an upsert.
     */
    public function reason(): ?string
    {
        return $this->reasons === [] ? null : implode('; ', $this->reasons);
    }
}
