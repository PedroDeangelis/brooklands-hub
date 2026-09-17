<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\Import\ProductQuantityImporter;
use App\Jobs\ImportBcProductQuantity;
use App\Models\ProductQuantity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class ImportBcProductQuantityTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '798c5fa0-3d1c-f111-8341-6045bde65a16',
            'number' => 'AA27',
            'type' => 'Inventory',
            'inventory' => 42,
            'qtyOnSalesOrder' => 8,
            'qtyOnPurchOrder' => 2210,
            'qtyOnTransferOrder' => 0,
            'lastModifiedDateTime' => '2026-05-06T01:56:04.173Z',
        ], $overrides);
    }

    public function test_it_stores_the_quantity_row(): void
    {
        (new ImportBcProductQuantity($this->row()))->handle(
            app(ProductQuantityImporter::class),
        );

        $this->assertDatabaseCount('product_quantities', 1);
        $this->assertSame('AA27', ProductQuantity::first()->sku);
    }

    public function test_reimporting_the_same_row_updates_it_in_place(): void
    {
        $importer = app(ProductQuantityImporter::class);

        (new ImportBcProductQuantity($this->row()))->handle($importer);
        (new ImportBcProductQuantity($this->row(['inventory' => 7])))->handle($importer);

        $this->assertDatabaseCount('product_quantities', 1);
        $this->assertSame('7.00000', ProductQuantity::first()->inventory);
    }

    public function test_it_is_queued_rather_than_run_inline(): void
    {
        Queue::fake();

        ImportBcProductQuantity::dispatch($this->row());

        Queue::assertPushed(ImportBcProductQuantity::class);
    }

    public function test_it_is_tagged_for_horizon_by_record(): void
    {
        $tags = (new ImportBcProductQuantity($this->row()))->tags();

        $this->assertContains('bc-product-quantity', $tags);
        $this->assertContains('bc:798c5fa0-3d1c-f111-8341-6045bde65a16', $tags);
        $this->assertContains('sku:AA27', $tags);
    }

    public function test_a_row_without_an_id_fails_the_job(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ImportBcProductQuantity($this->row(['id' => ''])))->handle(
            app(ProductQuantityImporter::class),
        );
    }
}
