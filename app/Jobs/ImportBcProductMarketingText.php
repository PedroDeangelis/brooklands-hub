<?php

namespace App\Jobs;

use App\BusinessCentral\Import\ProductMarketingTextImporter;
use App\Models\Product;
use App\Sync\SyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Normalises one Business Central marketing text row and stores it.
 *
 * The job carries the raw row for the same reason the product import does: there
 * is no local record to read from until the row has been stored.
 */
class ImportBcProductMarketingText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  A raw marketingTextExt row from Business Central.
     * @param  bool  $force  Deliver even when the copy has not changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(ProductMarketingTextImporter $importer, SyncLedger $ledger): void
    {
        $result = $importer->import($this->row);

        if ($result->skipped) {
            // No product carries this Business Central id. Recorded rather than
            // silently dropped: a row that keeps being skipped means the item
            // import is missing something, which is worth being able to see.
            Log::info('bc.product_marketing_text.skipped_no_product', [
                'bc_id' => $this->row['itemId'] ?? null,
                'sku' => $this->row['itemNo'] ?? null,
                'reason' => 'no local product carries this Business Central id',
            ]);

            return;
        }

        $marketingText = $result->marketingText;

        // The copy feeds the website payload, so changed copy means the website
        // is holding something out of date. Reconciling here is what turns an
        // edit in Business Central into a delivery.
        $record = null;

        // A forced run delivers whatever the copy says: the point is to put the
        // website back in step, and unchanged copy is exactly what a drifted
        // website is most likely to be missing.
        if ($this->force || $result->changed()) {
            $product = Product::query()->where('bc_id', $marketingText->bc_id)->first();

            if ($product !== null) {
                $record = $ledger->reconcile($product, $result->changedFields, force: $this->force);

                if ($record !== null) {
                    DeliverProductToWebsite::dispatch($product->bc_id);
                }
            }
        }

        Log::info('bc.product_marketing_text.imported', [
            'bc_id' => $marketingText->bc_id,
            'sku' => $marketingText->sku,
            'marketing_text_id' => $marketingText->id,
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
        $bcId = $this->row['itemId'] ?? null;
        $sku = $this->row['itemNo'] ?? null;

        return array_values(array_filter([
            'bc-product-marketing-text',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
            is_scalar($sku) ? 'sku:'.$sku : null,
        ]));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('bc.product_marketing_text.import_failed', [
            'bc_id' => $this->row['itemId'] ?? null,
            'sku' => $this->row['itemNo'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
