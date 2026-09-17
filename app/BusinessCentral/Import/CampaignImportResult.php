<?php

namespace App\BusinessCentral\Import;

use App\Models\Campaign;

/**
 * The outcome of importing one Business Central campaign row.
 */
final readonly class CampaignImportResult
{
    /**
     * @param  array<int, string>  $changedFields  Normalised campaign columns whose
     *                                             values differ from the stored ones.
     */
    public function __construct(
        public Campaign $campaign,
        public bool $created,
        public array $changedFields,
    ) {}

    /**
     * Whether this import altered the stored campaign at all.
     */
    public function changed(): bool
    {
        return $this->created || $this->changedFields !== [];
    }

    public function hasChanged(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }
}
