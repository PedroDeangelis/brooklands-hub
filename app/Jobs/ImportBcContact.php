<?php

namespace App\Jobs;

use App\BusinessCentral\Import\ContactImporter;
use App\Sync\ContactSyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Normalises one Business Central contact row and stores it as a Contact.
 *
 * Only genuinely new work is delivered: the ledger returns null when the
 * contact does not qualify for the website or when it already wants exactly
 * this, which is what stops an unchanged re-import queueing a delivery on
 * every pass and stops an excluded contact ever becoming a user.
 */
class ImportBcContact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  A raw contactsExt row from Business Central.
     * @param  bool  $force  Deliver even when nothing has changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(ContactImporter $importer, ContactSyncLedger $ledger): void
    {
        $result = $importer->import($this->row);
        $contact = $result->contact;

        $record = $ledger->reconcile($contact, $result->changedFields, force: $this->force);

        if ($record !== null) {
            DeliverContactToWebsite::dispatch($contact->bc_id);
        }

        Log::info('bc.contact.imported', [
            'bc_id' => $contact->bc_id,
            'number' => $contact->number,
            'contact_id' => $contact->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
            'eligible' => $ledger->isEligible($contact),
            'forced' => $this->force,
            'marked_pending' => $record !== null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        $bcId = $this->row['id'] ?? null;
        $number = $this->row['number'] ?? null;

        return array_values(array_filter([
            'bc-contact',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
            is_scalar($number) ? 'number:'.$number : null,
        ]));
    }
}
