<?php

namespace App\Sync\Payload;

use App\Models\Contact;

/**
 * Builds the payload the website should be given for a contact.
 *
 * A translation step and nothing more. The website turns this into a user
 * account; nothing here says how, and in particular nothing here can ask for
 * an email to be sent. The payload has no key for that, on purpose, and a test
 * pins the key list so one cannot be added by accident.
 *
 * Deterministic — the same contact always produces the same keys in the same
 * shape — so payloads can be hashed and diffed. The billing block is sent
 * whole and compared whole; PayloadDiff lists it as atomic.
 */
class ContactWebsitePayloadBuilder
{
    /**
     * The name used when a contact has none. Reproduces the legacy upserter.
     */
    public const FALLBACK_FIRST_NAME = 'Valued Customer';

    /**
     * The billing address every website user carries.
     *
     * Reproduced from the legacy upserter, which wrote Brooklands' own address
     * onto every user: orders invoice to head office rather than to the
     * person. Flagged in the plan as a decision to confirm, not made here.
     *
     * @var array<string, string>
     */
    public const BILLING_ADDRESS = [
        'address_1' => '21 McGiven Drive',
        'address_2' => '',
        'city' => 'New Plymouth',
        'state' => 'TKI',
        'postcode' => '4371',
        'country' => 'NZ',
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(Contact $contact): array
    {
        $displayName = trim((string) $contact->display_name);

        return [
            'bc_id' => (string) $contact->bc_id,
            'number' => (string) $contact->number,
            'email' => mb_strtolower(trim((string) $contact->email)),
            'display_name' => $displayName,
            'first_name' => $displayName !== '' ? $displayName : self::FALLBACK_FIRST_NAME,
            'phone' => (string) $contact->phone,
            'mobile' => (string) $contact->mobile,
            'customer_bc_id' => (string) $contact->customer_bc_id,
            'billing' => ['first_name' => $displayName] + self::BILLING_ADDRESS,
        ];
    }
}
