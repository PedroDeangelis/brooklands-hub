<?php

namespace App\BusinessCentral\Import;

use App\Models\Contact;

/**
 * The outcome of applying one customerContacts row to a contact.
 */
final readonly class ContactLinkImportResult
{
    private function __construct(
        public ?Contact $contact,
        public bool $skipped,
        public bool $changed,
    ) {}

    public static function applied(Contact $contact, bool $changed): self
    {
        return new self($contact, false, $changed);
    }

    /**
     * No contact carries this Business Central id yet.
     */
    public static function skipped(): self
    {
        return new self(null, true, false);
    }
}
