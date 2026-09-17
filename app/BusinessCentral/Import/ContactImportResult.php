<?php

namespace App\BusinessCentral\Import;

use App\Models\Contact;

/**
 * The outcome of importing one Business Central contact row.
 */
final readonly class ContactImportResult
{
    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public Contact $contact,
        public bool $created,
        public array $changedFields,
    ) {}

    public function changed(): bool
    {
        return $this->created || $this->changedFields !== [];
    }

    public function hasChanged(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }
}
