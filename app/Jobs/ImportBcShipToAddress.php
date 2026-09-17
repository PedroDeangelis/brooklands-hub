<?php

namespace App\Jobs;

use App\BusinessCentral\Import\ShipToAddressImporter;
use App\Sync\CustomerSyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Attaches one Business Central ship-to address to its customer.
 *
 * A new or changed address changes what the website should hold for the
 * customer, so the customer is reconciled here — the same way a stock movement
 * reconciles a product.
 */
class ImportBcShipToAddress implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  One raw shipToAddresses row, or — when
     *                                     $replaceAll is set — every row for one
     *                                     customer under 'rows' with the number
     *                                     under 'customerNo'.
     * @param  bool  $replaceAll  Set the customer's list to exactly these rows.
     *                            Only a complete sweep may do this; a capped run
     *                            that saw part of the endpoint would wipe the rest.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
        public readonly bool $replaceAll = false,
    ) {}

    public function handle(ShipToAddressImporter $importer, CustomerSyncLedger $ledger): void
    {
        $result = $this->replaceAll
            ? $importer->replace((string) ($this->row['customerNo'] ?? ''), $this->row['rows'] ?? [])
            : $importer->import($this->row);

        if ($result->skipped) {
            // No customer carries this number yet. Recorded rather than dropped:
            // a row that keeps being skipped means the customer import is
            // behind, which is worth being able to see. The next sweep retries.
            Log::info('bc.ship_to_address.skipped_no_customer', [
                'bc_id' => $this->row['id'] ?? null,
                'customer_number' => $this->row['customerNo'] ?? null,
                'rows' => $this->replaceAll ? count($this->row['rows'] ?? []) : 1,
            ]);

            return;
        }

        $customer = $result->customer;
        $record = null;

        if ($this->force || $result->changed) {
            $record = $ledger->reconcile($customer, ['shipping_addresses'], force: $this->force);

            if ($record !== null) {
                DeliverCustomerToWebsite::dispatch($customer->bc_id);
            }
        }

        Log::info('bc.ship_to_address.imported', [
            'bc_id' => $this->row['id'] ?? null,
            'customer_number' => $customer->number,
            'replaced_all' => $this->replaceAll,
            'rows' => $this->replaceAll ? count($this->row['rows'] ?? []) : 1,
            'changed' => $result->changed,
            'forced' => $this->force,
            'delivery_queued' => $record !== null,
        ]);
    }
}
