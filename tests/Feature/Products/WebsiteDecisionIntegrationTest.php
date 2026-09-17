<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductQuantity;
use App\Products\ProductWebsiteState;
use App\Products\ProductWebsiteStateBuilder;
use App\Products\Website\StockStatus;
use App\Products\Website\WebsiteDecisionMaker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The final website decision as it is actually reached from a stored product,
 * its quantity row and its payload.
 *
 * WebsiteDecisionMakerTest covers the rules in isolation; this proves the
 * builder feeds them the right values and the page shows the answer.
 */
class WebsiteDecisionIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A stored product with a matching quantity row.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $quantity
     */
    private function storedProduct(array $attributes = [], ?array $quantity = null): Product
    {
        $product = Product::factory()->create(array_merge([
            'sku' => 'SF180',
            'type' => 'Inventory',
            'price' => 18.22,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_payload' => [
                'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 50]],
            ],
        ], $attributes));

        if ($quantity !== null) {
            ProductQuantity::factory()->create(array_merge([
                'bc_id' => $product->bc_id,
                'sku' => $product->sku,
                'inventory' => 0,
                'qty_on_purchase_order' => 0,
                'qty_on_sales_order' => 0,
                'qty_on_transfer_order' => 0,
            ], $quantity));
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $quantity
     */
    private function stateFor(array $attributes = [], ?array $quantity = null): ProductWebsiteState
    {
        return app(ProductWebsiteStateBuilder::class)
            ->build($this->storedProduct($attributes, $quantity));
    }

    public function test_a_stocked_inventory_product_is_in_stock_with_its_sellable_quantity(): void
    {
        $state = $this->stateFor(quantity: ['inventory' => 30]);

        $this->assertTrue($state->existsOnWebsite());
        $this->assertTrue($state->isPurchasable());
        $this->assertTrue($state->managesStock());
        $this->assertSame(30.0, $state->websiteQuantity());
        $this->assertSame(StockStatus::InStock, $state->stockStatus());
        $this->assertNull($state->unavailableReason());
        $this->assertTrue($state->isAvailableToBuy());
    }

    /**
     * The website quantity is the sellable figure, so it is capped at the
     * location and net of transfers and open sales orders.
     */
    public function test_the_website_quantity_is_the_sellable_figure_not_the_raw_inventory(): void
    {
        $state = $this->stateFor(quantity: [
            'inventory' => 30,
            'qty_on_sales_order' => 4,
            'qty_on_transfer_order' => 6,
        ]);

        $this->assertSame(30.0, $state->stock->inventory);
        $this->assertSame(20.0, $state->websiteQuantity());
        $this->assertSame(StockStatus::InStock, $state->stockStatus());
    }

    /**
     * Stock parked in a non-sellable location cannot be sold, so a zero cap
     * takes the product out of stock even though inventory exists.
     */
    public function test_a_location_cap_of_zero_leaves_the_product_out_of_stock(): void
    {
        $state = $this->stateFor(
            ['bc_payload' => ['stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 0]]]],
            ['inventory' => 32],
        );

        $this->assertSame(32.0, $state->stock->inventoryOrder);
        $this->assertSame(0.0, $state->websiteQuantity());
        $this->assertSame(StockStatus::OutOfStock, $state->stockStatus());
        $this->assertSame(WebsiteDecisionMaker::REASON_NO_SELLABLE_STOCK, $state->unavailableReason());
    }

    public function test_an_excluded_product_does_not_exist_on_the_website(): void
    {
        $state = $this->stateFor(['price' => 0], ['inventory' => 50]);

        $this->assertFalse($state->existsOnWebsite());
        $this->assertFalse($state->isPurchasable());
        $this->assertSame(StockStatus::NotApplicable, $state->stockStatus());
        $this->assertSame(WebsiteDecisionMaker::REASON_EXCLUDED, $state->unavailableReason());
    }

    public function test_a_sales_blocked_product_stays_on_the_website_but_is_out_of_stock(): void
    {
        $state = $this->stateFor(['sales_blocked' => true], ['inventory' => 50]);

        $this->assertTrue($state->existsOnWebsite());
        $this->assertFalse($state->isPurchasable());
        $this->assertFalse($state->managesStock());
        $this->assertSame(StockStatus::OutOfStock, $state->stockStatus());
        $this->assertSame(WebsiteDecisionMaker::REASON_SALES_BLOCKED, $state->unavailableReason());
    }

    public function test_a_non_inventory_product_is_in_stock_without_managing_stock(): void
    {
        $state = $this->stateFor(['type' => 'Non_x002D_Inventory']);

        $this->assertTrue($state->existsOnWebsite());
        $this->assertFalse($state->managesStock());
        $this->assertNull($state->websiteQuantity());
        $this->assertSame(StockStatus::InStock, $state->stockStatus());
        $this->assertTrue($state->isAvailableToBuy());
    }

    public function test_a_sales_blocked_non_inventory_product_is_forced_out_of_stock(): void
    {
        $state = $this->stateFor(['type' => 'Non_x002D_Inventory', 'sales_blocked' => true]);

        $this->assertSame(StockStatus::OutOfStock, $state->stockStatus());
        $this->assertFalse($state->isAvailableToBuy());
    }

    public function test_an_inventory_product_without_a_quantity_row_says_so(): void
    {
        $state = $this->stateFor();

        $this->assertTrue($state->managesStock());
        $this->assertNull($state->websiteQuantity());
        $this->assertSame(WebsiteDecisionMaker::REASON_NO_QUANTITY_DATA, $state->unavailableReason());
    }

    public function test_the_detail_page_shows_the_final_website_decision(): void
    {
        $product = $this->storedProduct([], ['inventory' => 12]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Final website decision')
            ->assertSee('Exists on website')
            ->assertSee('Purchasable')
            ->assertSee('Manage stock')
            ->assertSee('Website quantity')
            ->assertSee('Final stock status')
            ->assertSee('Reason if unavailable')
            ->assertSee('In stock')
            ->assertSee('12.00');
    }

    public function test_the_detail_page_shows_why_an_excluded_product_is_unavailable(): void
    {
        $product = $this->storedProduct(['price' => 0]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Final website decision')
            ->assertSee(WebsiteDecisionMaker::REASON_EXCLUDED)
            ->assertSee('Not applicable');
    }

    public function test_the_detail_page_shows_why_a_sales_blocked_product_is_unavailable(): void
    {
        $product = $this->storedProduct(['sales_blocked' => true], ['inventory' => 50]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee(WebsiteDecisionMaker::REASON_SALES_BLOCKED)
            ->assertSee('Out of stock');
    }
}
