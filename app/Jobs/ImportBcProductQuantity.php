<?php

namespace App\Jobs;

use App\BusinessCentral\Import\ProductQuantityImporter;
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
     */
    public function __construct(public readonly array $row) {}

    public function handle(ProductQuantityImporter $importer): void
    {
        $result = $importer->import($this->row);
        $quantity = $result->quantity;

        Log::info('bc.product_quantity.imported', [
            'bc_id' => $quantity->bc_id,
            'sku' => $quantity->sku,
            'quantity_id' => $quantity->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
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
