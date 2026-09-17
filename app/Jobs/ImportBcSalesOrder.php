<?php

namespace App\Jobs;

use App\BusinessCentral\Import\SalesOrderImporter;
use App\BusinessCentral\SalesOrderDetailFetcher;
use App\Enums\SyncStatus;
use App\Sync\SalesOrderSyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Completes one Business Central sales order header with its documents and
 * stores it as a SalesOrder.
 *
 * The header row queued by the command is not enough on its own: the lines,
 * shipments and invoices are read here, per order, from the standard API. That
 * is three requests on Horizon per order rather than in the scheduler, where a
 * failure is retried and a slow read holds nothing else up.
 *
 * Only genuinely new work is delivered: the ledger returns null when it already
 * wants exactly this. One exception: a row already pending is re-queued even
 * when nothing moved, because a delivery can be held back waiting for the
 * order's customer to reach the website, and the nightly full run is what
 * gives it another go.
 */
class ImportBcSalesOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 90;

    /**
     * @param  array<string, mixed>  $row  A raw salesOrdersExt header row from Business Central.
     * @param  bool  $force  Deliver even when nothing has changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(
        SalesOrderDetailFetcher $details,
        SalesOrderImporter $importer,
        SalesOrderSyncLedger $ledger,
    ): void {
        $bcId = trim((string) ($this->row['id'] ?? ''));
        $number = trim((string) ($this->row['number'] ?? ''));

        // The fetched documents win over anything the row happened to carry.
        $row = array_merge($this->row, $details->fetch($bcId, $number));

        $result = $importer->import($row);
        $salesOrder = $result->salesOrder;

        $record = $ledger->reconcile($salesOrder, $result->changedFields, force: $this->force);

        $queued = $record !== null;

        if ($record === null) {
            $existing = $ledger->find($salesOrder);

            if ($existing !== null && $existing->status === SyncStatus::Pending) {
                $queued = true;
            }
        }

        if ($queued) {
            DeliverSalesOrderToWebsite::dispatch($salesOrder->bc_id);
        }

        Log::info('bc.sales_order.imported', [
            'bc_id' => $salesOrder->bc_id,
            'number' => $salesOrder->number,
            'sales_order_id' => $salesOrder->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
            'lines' => count($salesOrder->lines()),
            'website_status' => $salesOrder->website_status,
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
            'bc-sales-order',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
        ]));
    }
}
