<?php

namespace App\Jobs;

use App\BusinessCentral\Import\ProductQuantityImporter;
use App\Models\Product;
use App\Sync\SyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Normalises one Business Central quantity row and stores it.
 *
 * The job carries the raw row for the same reason the product import does: there
 * is no local record to read from until the row has been stored.
 */
class ImportBcProductQuantity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  A raw itemQuantities row from Business Central.
     * @param  bool  $force  Deliver even when the figures have not moved, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(ProductQuantityImporter $importer, SyncLedger $ledger): void
    {
        $result = $importer->import($this->row);

        if ($result->skipped) {
            // No product carries this Business Central id. Recorded rather than
            // silently dropped: a row that keeps being skipped means the item
            // import is missing something, which is worth being able to see.
            Log::info('bc.product_quantity.skipped_no_product', [
                'bc_id' => $this->row['id'] ?? null,
                'sku' => $this->row['number'] ?? null,
                'reason' => 'no local product carries this Business Central id',
            ]);

            return;
        }

        $quantity = $result->quantity;

        // Stock feeds the website state, so a changed figure can change what the
        // website should hold: website_quantity, stock_status, and through them
        // whether the product is available at all. Reconciling here is what
        // turns a stock movement into a delivery.
        $record = null;

        // A forced run delivers whatever the figures say: the point is to put
        // the website back in step, and an unchanged quantity is exactly the
        // row a drifted website is most likely to be missing.
        if ($this->force || $result->changed()) {
            $product = Product::query()->where('bc_id', $quantity->bc_id)->first();

            if ($product !== null) {
                $record = $ledger->reconcile($product, $result->changedFields, force: $this->force);

                if ($record !== null) {
                    DeliverProductToWebsite::dispatch($product->bc_id);
                }
            }
        }

        Log::info('bc.product_quantity.imported', [
            'bc_id' => $quantity->bc_id,
            'sku' => $quantity->sku,
            'quantity_id' => $quantity->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
            'forced' => $this->force,
            'delivery_queued' => $record !== null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        $bcId = $this->row['id'] ?? null;
        $sku = $this->row['number'] ?? null;

        return array_values(array_filter([
            'bc-product-quantity',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
            is_scalar($sku) ? 'sku:'.$sku : null,
        ]));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('bc.product_quantity.import_failed', [
            'bc_id' => $this->row['id'] ?? null,
            'sku' => $this->row['number'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
