<?php

namespace App\Jobs;

use App\BusinessCentral\Import\SalesInvoiceImporter;
use App\BusinessCentral\SalesInvoiceDetailFetcher;
use App\Enums\SyncStatus;
use App\Sync\SalesInvoiceSyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Completes one Business Central posted invoice header with its lines and
 * stores it as a SalesInvoice.
 *
 * The lines are read here, per invoice, from the standard API: one request
 * on Horizon rather than in the scheduler, where a failure is retried.
 *
 * Only genuinely new work is delivered: the ledger returns null when it already
 * wants exactly this. A row already pending is re-queued even when nothing
 * moved, because a delivery can be held back waiting for the invoice's
 * customer to reach the website, and the weekly full run is what gives it
 * another go.
 */
class ImportBcSalesInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 90;

    /**
     * @param  array<string, mixed>  $row  A raw postedSalesInvoicesExt header row from Business Central.
     * @param  bool  $force  Deliver even when nothing has changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(
        SalesInvoiceDetailFetcher $details,
        SalesInvoiceImporter $importer,
        SalesInvoiceSyncLedger $ledger,
    ): void {
        $bcId = trim((string) ($this->row['id'] ?? ''));

        // The fetched lines win over anything the row happened to carry.
        $row = array_merge($this->row, $details->fetch($bcId));

        $result = $importer->import($row);
        $salesInvoice = $result->salesInvoice;

        $record = $ledger->reconcile($salesInvoice, $result->changedFields, force: $this->force);

        $queued = $record !== null;

        if ($record === null) {
            $existing = $ledger->find($salesInvoice);

            if ($existing !== null && $existing->status === SyncStatus::Pending) {
                $queued = true;
            }
        }

        if ($queued) {
            DeliverSalesInvoiceToWebsite::dispatch($salesInvoice->bc_id);
        }

        Log::info('bc.sales_invoice.imported', [
            'bc_id' => $salesInvoice->bc_id,
            'number' => $salesInvoice->number,
            'order_number' => $salesInvoice->order_number,
            'sales_invoice_id' => $salesInvoice->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
            'lines' => count($salesInvoice->lines()),
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
            'bc-sales-invoice',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
        ]));
    }
}
