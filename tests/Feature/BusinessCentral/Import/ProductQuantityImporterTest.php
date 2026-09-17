<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\ProductQuantityImporter;
use App\Models\Product;
use App\Models\ProductQuantity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProductQuantityImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BC_ID = '798c5fa0-3d1c-f111-8341-6045bde65a16';

    /**
     * A row shaped exactly as Business Central returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => self::BC_ID,
            'number' => 'AA27',
            'type' => 'Inventory',
            'unitPrice' => 4.12,
            'inventory' => 42,
            'qtyOnSalesOrder' => 8,
            'qtyOnPurchOrder' => 2210,
            'qtyOnTransferOrder' => 0,
            'nextPurchaseReceiptDate' => '2026-04-30',
            'nextTransferReceiptDate' => '0001-01-01',
            'gppg' => 'FINISHED GOODS',
            'lastModifiedDateTime' => '2026-05-06T01:56:04.173Z',
        ], $overrides);
    }

    public function test_it_stores_the_business_central_figures(): void
    {
        $quantity = app(ProductQuantityImporter::class)->import($this->row())->quantity;

        $this->assertSame(self::BC_ID, $quantity->bc_id);
        $this->assertSame('AA27', $quantity->sku);
        $this->assertSame('Inventory', $quantity->type);
        $this->assertSame('42.00000', $quantity->inventory);
        $this->assertSame('8.00000', $quantity->qty_on_sales_order);
        $this->assertSame('2210.00000', $quantity->qty_on_purchase_order);
        $this->assertSame('0.00000', $quantity->qty_on_transfer_order);
    }

    public function test_it_keeps_the_whole_row_for_later_reference(): void
    {
        $quantity = app(ProductQuantityImporter::class)->import($this->row())->quantity;

        $this->assertSame('FINISHED GOODS', $quantity->bc_payload['gppg']);
    }

    public function test_it_stores_the_modified_timestamp_as_utc(): void
    {
        $quantity = app(ProductQuantityImporter::class)->import($this->row())->quantity;

        $this->assertSame('2026-05-06 01:56:04', $quantity->bc_modified_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $quantity->bc_modified_at->timezoneName);
    }

    public function test_it_stores_real_receipt_dates(): void
    {
        $quantity = app(ProductQuantityImporter::class)->import($this->row())->quantity;

        $this->assertSame('2026-04-30', $quantity->next_purchase_receipt_date?->toDateString());
    }

    public function test_the_business_central_empty_date_becomes_null(): void
    {
        // BC sends 0001-01-01 rather than null when no date is set.
        $quantity = app(ProductQuantityImporter::class)->import($this->row())->quantity;

        $this->assertNull($quantity->next_transfer_receipt_date);
    }

    public function test_a_past_receipt_date_is_still_stored(): void
    {
        // The future-only rule belongs to reading, not storing: the raw value
        // stays visible and the rule re-evaluates as time passes.
        $quantity = app(ProductQuantityImporter::class)
            ->import($this->row(['nextPurchaseReceiptDate' => '2020-01-01']))
            ->quantity;

        $this->assertSame('2020-01-01', $quantity->next_purchase_receipt_date?->toDateString());
    }

    public function test_the_business_central_id_is_the_identity(): void
    {
        $importer = app(ProductQuantityImporter::class);

        $importer->import($this->row());
        $importer->import($this->row(['inventory' => 99]));

        $this->assertDatabaseCount('product_quantities', 1);
        $this->assertSame('99.00000', ProductQuantity::first()->inventory);
    }

    public function test_a_new_row_reports_its_fields_as_changed(): void
    {
        $result = app(ProductQuantityImporter::class)->import($this->row());

        $this->assertTrue($result->created);
        $this->assertTrue($result->changed());
        $this->assertContains('inventory', $result->changedFields);
        $this->assertNotContains('bc_payload', $result->changedFields);
    }

    public function test_an_identical_reimport_reports_no_change(): void
    {
        // Guards the decimal casts: MySQL returns decimals as strings, so an
        // uncast column would look dirty on every import.
        $importer = app(ProductQuantityImporter::class);
        $importer->import($this->row());

        $result = $importer->import($this->row());

        $this->assertFalse($result->created);
        $this->assertFalse($result->changed());
        $this->assertSame([], $result->changedFields);
    }

    public function test_it_reports_only_the_figures_that_moved(): void
    {
        $importer = app(ProductQuantityImporter::class);
        $importer->import($this->row());

        $result = $importer->import($this->row(['qtyOnSalesOrder' => 9]));

        $this->assertSame(['qty_on_sales_order'], $result->changedFields);
        $this->assertTrue($result->hasChanged('qty_on_sales_order'));
    }

    public function test_a_type_change_is_reported_even_when_every_figure_is_identical(): void
    {
        // Switching between Inventory and Non-Inventory changes whether stock is
        // tracked at all, so it must never be mistaken for an unchanged row.
        $importer = app(ProductQuantityImporter::class);
        $importer->import($this->row());

        $result = $importer->import($this->row(['type' => 'Non_x002D_Inventory']));

        $this->assertSame(['type'], $result->changedFields);
    }

    public function test_missing_figures_default_to_zero(): void
    {
        $quantity = app(ProductQuantityImporter::class)
            ->import(['id' => self::BC_ID, 'number' => 'BARE1'])
            ->quantity;

        $this->assertSame('0.00000', $quantity->inventory);
        $this->assertSame('0.00000', $quantity->qty_on_sales_order);
        $this->assertNull($quantity->next_purchase_receipt_date);
        $this->assertNull($quantity->bc_modified_at);
    }

    public function test_a_row_without_an_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProductQuantityImporter::class)->import($this->row(['id' => '']));
    }

    public function test_the_quantity_row_joins_its_product_by_business_central_id(): void
    {
        $product = Product::factory()->create(['bc_id' => self::BC_ID, 'sku' => 'AA27']);

        $quantity = app(ProductQuantityImporter::class)->import($this->row())->quantity;

        $this->assertTrue($product->is($quantity->product));
        $this->assertTrue($quantity->is($product->fresh()->quantity));
    }

    public function test_quantities_may_arrive_before_their_product_exists(): void
    {
        // The two entities are imported independently, so neither may assume the other.
        $quantity = app(ProductQuantityImporter::class)->import($this->row())->quantity;

        $this->assertNull($quantity->product);
        $this->assertDatabaseCount('product_quantities', 1);
    }
}
