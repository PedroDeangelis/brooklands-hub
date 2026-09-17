<?php

namespace Tests\Feature\Products\Website;

use App\Models\ProductQuantity;
use App\Products\Website\ProductLocation;
use App\Products\Website\StockCalculator;
use App\Products\Website\StockPosition;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StockCalculatorTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $figures
     */
    private function position(array $figures, ?ProductLocation $location = null): StockPosition
    {
        $quantity = ProductQuantity::factory()->make($figures);

        return app(StockCalculator::class)->calculate(
            $quantity,
            $location ?? ProductLocation::none(),
        );
    }

    public function test_inventory_order_is_on_hand_net_of_transfers(): void
    {
        $position = $this->position(['inventory' => 42, 'qty_on_transfer_order' => 10]);

        $this->assertSame(32.0, $position->inventoryOrder);
    }

    public function test_inventory_order_never_goes_negative(): void
    {
        $position = $this->position(['inventory' => 5, 'qty_on_transfer_order' => 20]);

        $this->assertSame(0.0, $position->inventoryOrder);
    }

    public function test_inventory_order_is_not_capped_by_the_location(): void
    {
        // The item-level figure stays whole: backorder and partial-shipping
        // displays read it and do their own subtraction.
        $position = $this->position(
            ['inventory' => 100, 'qty_on_transfer_order' => 0],
            new ProductLocation('BROOKLANDS', 4),
        );

        $this->assertSame(100.0, $position->inventoryOrder);
        $this->assertSame(4.0, $position->sellableStock);
    }

    public function test_sellable_stock_is_capped_at_the_location_holding(): void
    {
        // Stock parked in non-sellable locations is counted by the item-level
        // figure but cannot be picked, so it must not be sold.
        $position = $this->position(
            ['inventory' => 90, 'qty_on_sales_order' => 0, 'qty_on_transfer_order' => 0],
            new ProductLocation('BROOKLANDS', 6),
        );

        $this->assertSame(6.0, $position->sellableStock);
        $this->assertTrue($position->locationCapped);
    }

    public function test_the_cap_never_raises_the_sellable_figure(): void
    {
        // A location holding more than the item total cannot invent stock.
        $position = $this->position(
            ['inventory' => 3],
            new ProductLocation('BROOKLANDS', 500),
        );

        $this->assertSame(3.0, $position->sellableStock);
    }

    public function test_without_a_resolved_location_the_item_figure_is_used_uncapped(): void
    {
        $position = $this->position(['inventory' => 12], ProductLocation::none());

        $this->assertSame(12.0, $position->sellableStock);
        $this->assertFalse($position->locationCapped);
    }

    public function test_transfers_are_subtracted_after_the_cap(): void
    {
        // min(50, 20) = 20, then 20 - 5 = 15.
        $position = $this->position(
            ['inventory' => 50, 'qty_on_transfer_order' => 5],
            new ProductLocation('BROOKLANDS', 20),
        );

        $this->assertSame(15.0, $position->sellableStock);
    }

    public function test_open_sales_orders_are_subtracted_last(): void
    {
        // min(50, 20) = 20, then 20 - 5 transfers = 15, then 15 - 4 sales = 11.
        $position = $this->position(
            ['inventory' => 50, 'qty_on_transfer_order' => 5, 'qty_on_sales_order' => 4],
            new ProductLocation('BROOKLANDS', 20),
        );

        $this->assertSame(11.0, $position->sellableStock);
        $this->assertTrue($position->hasSellableStock());
    }

    public function test_sellable_stock_never_goes_negative(): void
    {
        $position = $this->position([
            'inventory' => 10,
            'qty_on_transfer_order' => 4,
            'qty_on_sales_order' => 50,
        ]);

        $this->assertSame(0.0, $position->sellableStock);
        $this->assertFalse($position->hasSellableStock());
    }

    public function test_a_zero_location_holding_leaves_nothing_sellable(): void
    {
        // A cap of zero is a real cap, not a missing one.
        $position = $this->position(
            ['inventory' => 80],
            new ProductLocation('BROOKLANDS', 0),
        );

        $this->assertSame(0.0, $position->sellableStock);
        $this->assertSame(80.0, $position->inventoryOrder);
    }

    public function test_the_order_figures_are_carried_through(): void
    {
        $position = $this->position([
            'qty_on_purchase_order' => 2210,
            'qty_on_sales_order' => 8,
            'qty_on_transfer_order' => 3,
        ]);

        $this->assertSame(2210.0, $position->onPurchaseOrder);
        $this->assertSame(8.0, $position->onSalesOrder);
        $this->assertSame(3.0, $position->onTransferOrder);
    }

    public function test_a_future_receipt_date_is_reported(): void
    {
        $date = now()->addWeek()->toDateString();

        $position = $this->position([
            'next_purchase_receipt_date' => $date,
            'next_transfer_receipt_date' => $date,
        ]);

        $this->assertSame($date, $position->nextPurchaseReceiptDate);
        $this->assertSame($date, $position->nextTransferReceiptDate);
        $this->assertTrue($position->expectsRestock());
    }

    public function test_a_past_receipt_date_is_ignored(): void
    {
        $position = $this->position([
            'next_purchase_receipt_date' => now()->subDay()->toDateString(),
            'next_transfer_receipt_date' => now()->subYear()->toDateString(),
        ]);

        $this->assertNull($position->nextPurchaseReceiptDate);
        $this->assertNull($position->nextTransferReceiptDate);
        $this->assertFalse($position->expectsRestock());
    }

    public function test_todays_receipt_date_is_ignored(): void
    {
        // Strictly in the future: stock arriving today is not still to come.
        $position = $this->position(['next_purchase_receipt_date' => now()->toDateString()]);

        $this->assertNull($position->nextPurchaseReceiptDate);
    }

    public function test_the_future_rule_is_applied_at_read_time_not_at_import(): void
    {
        $quantity = ProductQuantity::factory()->make([
            'next_purchase_receipt_date' => now()->addDay()->toDateString(),
        ]);
        $calculator = app(StockCalculator::class);

        $this->assertNotNull($calculator->calculate($quantity, ProductLocation::none())->nextPurchaseReceiptDate);

        // The stored date has not changed; only the present has moved past it.
        $this->travel(2)->days();

        $this->assertNull($calculator->calculate($quantity, ProductLocation::none())->nextPurchaseReceiptDate);
    }

    public function test_missing_receipt_dates_are_absent(): void
    {
        $position = $this->position([
            'next_purchase_receipt_date' => null,
            'next_transfer_receipt_date' => null,
        ]);

        $this->assertNull($position->nextPurchaseReceiptDate);
        $this->assertNull($position->nextTransferReceiptDate);
        $this->assertFalse($position->expectsRestock());
    }

    public function test_an_unknown_position_reports_nothing(): void
    {
        $position = StockPosition::unknown();

        $this->assertFalse($position->isKnown());
        $this->assertFalse($position->hasSellableStock());
        $this->assertSame(0.0, $position->sellableStock);
    }

    public function test_fractional_quantities_survive_the_calculation(): void
    {
        $position = $this->position(['inventory' => 10.5, 'qty_on_sales_order' => 0.25]);

        $this->assertSame(10.5, $position->inventoryOrder);
        $this->assertSame(10.25, $position->sellableStock);
    }
}
