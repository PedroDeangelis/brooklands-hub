<?php

namespace App\BusinessCentral\Import;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns a Business Central campaign row into a local Campaign.
 *
 * Business Central calls these Campaigns; the website calls them Promotions.
 */
class CampaignImporter
{
    /**
     * Columns that must never count as a change.
     *
     * bc_payload mirrors the whole BC row, so any movement inside it would make
     * every campaign look changed; the timestamps are bookkeeping.
     *
     * @var array<int, string>
     */
    private const IGNORED_FOR_CHANGE_DETECTION = [
        'bc_payload',
        'created_at',
        'updated_at',
    ];

    /**
     * Business Central's null date sentinel.
     *
     * An unset date arrives as year one rather than as an absent field.
     *
     * @var array<int, string>
     */
    private const EMPTY_DATES = [
        '0001-01-01',
        '0000-00-00',
    ];

    /**
     * Create or update the Campaign for a Business Central row, reporting what changed.
     *
     * The BC "id" GUID is the identity: re-importing the same row updates the
     * existing Campaign rather than creating a duplicate.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable BC id.
     */
    public function import(array $row): CampaignImportResult
    {
        $bcId = $this->string($row, 'id');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central row is missing an "id".');
        }

        $campaign = Campaign::firstOrNew(['bc_id' => $bcId]);
        $created = ! $campaign->exists;

        $campaign->fill($this->normalize($row));

        // Read the dirty set before saving: afterwards getDirty() is empty.
        // The audience is a normalised column rather than a nested payload
        // section, so it is compared here like any other field.
        $changedFields = $created
            ? $this->presentFields($campaign)
            : $this->dirtyFields($campaign);

        sort($changedFields);

        $campaign->save();

        return new CampaignImportResult($campaign, $created, $changedFields);
    }

    /**
     * Map a Business Central row onto Campaign columns.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        return [
            'bc_id' => $this->string($row, 'id'),
            'code' => $this->string($row, 'code'),
            'description' => $this->string($row, 'description'),
            'starting_date' => $this->date($row['startingDate'] ?? null),
            'ending_date' => $this->date($row['endingDate'] ?? null),
            'activated' => $this->activated($row['activated'] ?? null),
            'customers' => $this->customers($row['campaignCustomers'] ?? null),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => $row,
        ];
    }

    /**
     * The campaign's audience, as a canonical list of Business Central customer ids.
     *
     * Blanks are dropped, duplicates collapsed and the result sorted, so the
     * same audience arriving in a different order is recognised as the same
     * audience. Without this the column would differ on every reorder and each
     * import would report a change that had not happened.
     *
     * @return array<int, string>
     */
    private function customers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $customers = [];

        foreach ($value as $customer) {
            if (! is_array($customer)) {
                continue;
            }

            $customerId = $this->string($customer, 'customerId');

            if ($customerId !== '') {
                $customers[$customerId] = $customerId;
            }
        }

        $customers = array_values($customers);
        sort($customers);

        return $customers;
    }

    /**
     * Business Central's activation flag, which is not reliably a boolean.
     *
     * Anything not recognisably true is false: a campaign is shown only when
     * Business Central positively says so.
     */
    private function activated(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    /**
     * The fields carried by a newly created campaign.
     *
     * @return array<int, string>
     */
    private function presentFields(Campaign $campaign): array
    {
        return $this->withoutIgnored(array_keys($campaign->getAttributes()));
    }

    /**
     * The fields whose values differ from those already stored.
     *
     * @return array<int, string>
     */
    private function dirtyFields(Campaign $campaign): array
    {
        return $this->withoutIgnored(array_keys($campaign->getDirty()));
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private function withoutIgnored(array $fields): array
    {
        return array_values(array_diff($fields, self::IGNORED_FOR_CHANGE_DETECTION));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * A Business Central calendar date, or null when it carries no date.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || in_array($value, self::EMPTY_DATES, true)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    /**
     * Business Central sends UTC timestamps with milliseconds.
     */
    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
