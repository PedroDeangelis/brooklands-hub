<?php

namespace App\Contacts;

/**
 * One failed website rule, together with the value that failed it.
 *
 * Keeping the value alongside the reason means the dashboard can say which
 * contact took the email, or which customer has no address, without
 * re-reading anything.
 */
final readonly class ContactExclusion
{
    public function __construct(
        public ContactExclusionReason $reason,
        public ?string $value = null,
    ) {}

    /**
     * The sentence shown to a person reading the dashboard.
     */
    public function describe(): string
    {
        return $this->reason->describe($this->value);
    }

    public function is(ContactExclusionReason $reason): bool
    {
        return $this->reason === $reason;
    }
}
