<?php

namespace App\BusinessCentral\Import;

use App\Models\Contact;

/**
 * Attaches a customer to a contact from a customerContacts row.
 *
 * The row's id is the contact's own Business Central id; customerId is the
 * customer it belongs to. An empty customer id unlinks: a contact that no
 * longer appears on the page has been detached in Business Central, and the
 * sweep hands such contacts here with no customer so the link can go.
 */
class ContactLinkImporter
{
    public function link(string $contactBcId, ?string $customerBcId): ContactLinkImportResult
    {
        $contactBcId = trim($contactBcId);

        if ($contactBcId === '') {
            return ContactLinkImportResult::skipped();
        }

        $contact = Contact::query()->where('bc_id', $contactBcId)->first();

        if ($contact === null) {
            return ContactLinkImportResult::skipped();
        }

        $customerBcId = trim((string) $customerBcId);
        $customerBcId = $customerBcId === '' ? null : $customerBcId;

        $contact->customer_bc_id = $customerBcId;
        $changed = $contact->isDirty('customer_bc_id');

        $contact->save();

        return ContactLinkImportResult::applied($contact, $changed);
    }
}
