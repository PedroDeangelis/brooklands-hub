<?php

namespace App\Jobs;

use App\BusinessCentral\Import\CustomerImporter;
use App\Sync\CustomerSyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Normalises one Business Central customer row and stores it as a Customer.
 *
 * Only genuinely new work is delivered: the ledger returns null when it already
 * wants exactly this, which is what stops an unchanged re-import queueing a
 * delivery on every pass.
 */
class ImportBcCustomer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  A raw customersExt row from Business Central.
     * @param  bool  $force  Deliver even when nothing has changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(CustomerImporter $importer, CustomerSyncLedger $ledger): void
    {
        $result = $importer->import($this->row);
        $customer = $result->customer;

        $record = $ledger->reconcile($customer, $result->changedFields, force: $this->force);

        if ($record !== null) {
            DeliverCustomerToWebsite::dispatch($customer->bc_id);
        }

        Log::info('bc.customer.imported', [
            'bc_id' => $customer->bc_id,
            'number' => $customer->number,
            'customer_id' => $customer->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
            'forced' => $this->force,
            'marked_pending' => $record !== null,
        ]);
    }
}
