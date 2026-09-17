<?php

namespace App\BusinessCentral\Import;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns a Business Central contact row into a local Contact.
 *
 * The customer link is not touched here: it arrives from the customerContacts
 * page and is attached by ContactLinkImporter. A contact re-import therefore
 * never wipes a link already gathered for it.
 */
class ContactImporter
{
    /**
     * @var array<int, string>
     */
    private const IGNORED_FOR_CHANGE_DETECTION = [
        'bc_payload',
        'created_at',
        'updated_at',
    ];

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the row carries no usable BC id.
     */
    public function import(array $row): ContactImportResult
    {
        $bcId = $this->string($row, 'id');

        if ($bcId === '') {
            throw new InvalidArgumentException('Business Central row is missing an "id".');
        }

        $contact = Contact::firstOrNew(['bc_id' => $bcId]);
        $created = ! $contact->exists;

        $contact->fill($this->normalize($row));

        $changedFields = $created
            ? $this->withoutIgnored(array_keys($contact->getAttributes()))
            : $this->withoutIgnored(array_keys($contact->getDirty()));

        sort($changedFields);

        $contact->save();

        return new ContactImportResult($contact, $created, $changedFields);
    }

    /**
     * Map a Business Central row onto Contact columns.
     *
     * customer_bc_id is deliberately absent: see the class docblock.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        return [
            'bc_id' => $this->string($row, 'id'),
            'number' => $this->string($row, 'number'),
            'display_name' => $this->string($row, 'displayName'),
            'type' => $this->string($row, 'type'),
            'company_number' => $this->string($row, 'companyNumber'),
            'company_name' => $this->string($row, 'companyName'),
            // Upper-cased so the eligibility rule and its SQL twin can compare
            // against one spelling. Business Central codes are upper-case
            // already; this only guards against a hand-typed one.
            'organisational_level_code' => mb_strtoupper($this->string($row, 'organisationalLevelCode')),
            'contact_business_relation' => $this->string($row, 'contactBusinessRelation'),
            'address_1' => $this->string($row, 'addressLine1'),
            'address_2' => $this->string($row, 'addressLine2'),
            'city' => $this->string($row, 'city'),
            'state' => $this->string($row, 'state'),
            'postal_code' => $this->string($row, 'postalCode'),
            'country' => $this->string($row, 'country'),
            'phone' => $this->string($row, 'phoneNumber'),
            'mobile' => $this->string($row, 'mobilePhoneNumber'),
            // Lower-cased: it becomes the WordPress login and is what the
            // duplicate rule compares, so "A@x" and "a@x" must be one address.
            'email' => mb_strtolower($this->string($row, 'email')),
            'privacy_blocked' => $this->bool($row['privacyBlocked'] ?? null),
            'bc_modified_at' => $this->timestamp($row['lastModifiedDateTime'] ?? null),
            'bc_payload' => $row,
        ];
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

        return is_numeric($value) ? (string) $value : '';
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['true', '1', 'yes'], true);
        }

        return is_numeric($value) && (int) $value !== 0;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
