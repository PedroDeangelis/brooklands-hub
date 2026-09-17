<?php

namespace App\Jobs;

use App\BusinessCentral\Import\ContactLinkImporter;
use App\Enums\SyncStatus;
use App\Sync\ContactSyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Attaches one customerContacts row to its contact, then re-evaluates the
 * contact whether or not the link moved.
 *
 * The re-evaluation is the point of the sweep. Three of a contact's website
 * rules depend on other records — its customer arriving, that customer
 * gaining a ship-to address, another contact releasing an email — and none of
 * those touch the contact row, so nothing else would ever notice the contact
 * had started to qualify. The legacy sync noticed only when Business Central
 * happened to modify the contact again; this notices within one sweep.
 *
 * A pending delivery is re-queued here for the same reason: a contact held
 * back because its customer had not reached the website yet is waiting on
 * something this sweep is the only thing to re-check.
 */
class ImportBcContactLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  One raw customerContacts row: id (the
     *                                     contact's id) and customerId. An empty
     *                                     customerId unlinks the contact.
     * @param  bool  $force  Deliver even when nothing has changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(ContactLinkImporter $importer, ContactSyncLedger $ledger): void
    {
        $result = $importer->link(
            (string) ($this->row['id'] ?? ''),
            is_scalar($this->row['customerId'] ?? null) ? (string) $this->row['customerId'] : null,
        );

        if ($result->skipped) {
            // No contact carries this id yet. Recorded rather than dropped: a
            // row that keeps being skipped means the contact import is behind,
            // which is worth being able to see. The next sweep retries.
            Log::info('bc.contact_link.skipped_no_contact', [
                'bc_id' => $this->row['id'] ?? null,
                'customer_bc_id' => $this->row['customerId'] ?? null,
            ]);

            return;
        }

        $contact = $result->contact;

        $record = $ledger->reconcile(
            $contact,
            $result->changed ? ['customer_bc_id'] : [],
            force: $this->force,
        );

        $queued = $record !== null;

        if ($record === null) {
            $existing = $ledger->find($contact);

            if ($existing !== null && $existing->status === SyncStatus::Pending) {
                $queued = true;
            }
        }

        if ($queued) {
            DeliverContactToWebsite::dispatch($contact->bc_id);
        }

        Log::info('bc.contact_link.imported', [
            'bc_id' => $contact->bc_id,
            'number' => $contact->number,
            'customer_bc_id' => $contact->customer_bc_id,
            'changed' => $result->changed,
            'eligible' => $ledger->isEligible($contact),
            'forced' => $this->force,
            'delivery_queued' => $queued,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        $bcId = $this->row['id'] ?? null;

        return array_values(array_filter([
            'bc-contact-link',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
        ]));
    }
}
