<?php

namespace App\Jobs;

use App\BusinessCentral\Import\ProductImporter;
use App\Sync\SyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Normalises one Business Central item row and stores it as a Product.
 *
 * The job carries the raw BC row because there is no local record to read yet;
 * once the sync ledger exists, jobs will carry identifiers instead.
 */
class ImportBcProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  A raw itemsExt row from Business Central.
     * @param  bool  $force  Deliver even when nothing has changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(ProductImporter $importer, SyncLedger $ledger): void
    {
        $result = $importer->import($this->row);
        $product = $result->product;

        // The ledger decides whether this amounts to new work. It compares both
        // halves of the intent, so a product that has only lost its eligibility
        // is queued for removal even though no Business Central field moved.
        $record = $ledger->reconcile($product, $result->changedFields, force: $this->force);

        // Only genuinely new work is delivered. reconcile() returns null when the
        // ledger already wants exactly this, which is what stops an unchanged
        // re-import queueing a delivery on every pass.
        if ($record !== null) {
            DeliverProductToWebsite::dispatch($product->bc_id);
        }

        Log::info('bc.product.imported', [
            'bc_id' => $product->bc_id,
            'sku' => $product->sku,
            'product_id' => $product->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
            'forced' => $this->force,
            'marked_pending' => $record !== null,
            'website_action' => $record?->action->value,
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
            'bc-product',
            is_scalar($bcId) ? 'bc:'.$bcId : null,
            is_scalar($sku) ? 'sku:'.$sku : null,
        ]));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('bc.product.import_failed', [
            'bc_id' => $this->row['id'] ?? null,
            'sku' => $this->row['number'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
