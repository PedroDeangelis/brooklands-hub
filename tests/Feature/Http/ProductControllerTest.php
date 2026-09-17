<?php

namespace Tests\Feature\Http;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\ProductQuantity;
use App\Models\SyncRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_product_list_loads(): void
    {
        Product::factory()->create(['sku' => 'POLY1', 'name' => 'Poly Bin']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('POLY1')
            ->assertSee('Poly Bin');
    }

    public function test_searching_by_sku_returns_only_matching_products(): void
    {
        Product::factory()->create(['sku' => 'POLY1', 'name' => 'Poly Bin']);
        Product::factory()->create(['sku' => 'KR29', 'name' => 'Brake Rotor']);

        $this->get(route('products.index', ['search' => 'POLY']))
            ->assertOk()
            ->assertSee('POLY1')
            ->assertDontSee('KR29');
    }

    public function test_searching_by_name_returns_only_matching_products(): void
    {
        Product::factory()->create(['sku' => 'POLY1', 'name' => 'Poly Bin']);
        Product::factory()->create(['sku' => 'KR29', 'name' => 'Brake Rotor']);

        $this->get(route('products.index', ['search' => 'Brake']))
            ->assertOk()
            ->assertSee('KR29')
            ->assertDontSee('POLY1');
    }

    public function test_search_is_case_insensitive_and_matches_partial_terms(): void
    {
        Product::factory()->create(['sku' => 'POLY1', 'name' => 'Poly Bin']);

        $this->get(route('products.index', ['search' => 'poly']))
            ->assertOk()
            ->assertSee('POLY1');
    }

    public function test_reports_when_no_product_matches_the_search(): void
    {
        Product::factory()->create(['sku' => 'POLY1', 'name' => 'Poly Bin']);

        $this->get(route('products.index', ['search' => 'nothing-matches']))
            ->assertOk()
            ->assertSee('No products match');
    }

    public function test_product_list_is_paginated(): void
    {
        // 26 products across a 25-per-page list: the 26th must only appear on page 2.
        foreach (range(1, 26) as $n) {
            Product::factory()->create(['sku' => sprintf('SKU%03d', $n)]);
        }

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('SKU001')
            ->assertDontSee('SKU026');

        $this->get(route('products.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('SKU026')
            ->assertDontSee('SKU001');
    }

    public function test_search_term_is_preserved_across_pages(): void
    {
        foreach (range(1, 26) as $n) {
            Product::factory()->create(['sku' => sprintf('POLY%03d', $n), 'name' => 'Poly Bin']);
        }

        $this->get(route('products.index', ['search' => 'POLY', 'page' => 2]))
            ->assertOk()
            ->assertSee('search=POLY', escape: false);
    }

    public function test_product_detail_loads(): void
    {
        $product = Product::factory()->create([
            'sku' => 'POLY1',
            'name' => 'Poly Bin',
            'type' => 'Inventory',
            'item_category_id' => 'TOYS',
        ]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('POLY1')
            ->assertSee('Poly Bin')
            ->assertSee('Inventory')
            ->assertSee('TOYS');
    }

    public function test_eligible_product_is_shown_as_eligible(): void
    {
        $product = Product::factory()->create(['blocked' => false, 'item_category_id' => 'TOYS']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Eligible')
            ->assertDontSee('Reason:');
    }

    public function test_blocked_product_is_shown_as_excluded_with_a_reason(): void
    {
        $product = Product::factory()->create(['blocked' => true]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Excluded')
            ->assertSee('Product is blocked in Business Central');
    }

    public function test_retired_product_is_shown_as_excluded_with_a_reason(): void
    {
        $product = Product::factory()->create(['blocked' => false, 'item_category_id' => 'RETIRE']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Excluded')
            ->assertSee('Product belongs to the RETIRE category');
    }

    public function test_shows_the_sync_record_information(): void
    {
        $product = Product::factory()->create();
        SyncRecord::factory()->create([
            'bc_id' => $product->bc_id,
            'status' => SyncStatus::Pending,
            'changed_fields' => ['price', 'inventory'],
            'payload_hash' => str_repeat('a', 64),
        ]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('pending')
            ->assertSee('price')
            ->assertSee('inventory')
            ->assertSee(str_repeat('a', 64));
    }

    public function test_shows_the_last_error_for_a_failed_sync_record(): void
    {
        $product = Product::factory()->create();
        SyncRecord::factory()->failed()->create(['bc_id' => $product->bc_id]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('failed')
            ->assertSee('boom');
    }

    public function test_states_clearly_when_no_sync_record_exists(): void
    {
        $product = Product::factory()->create();

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('No sync record exists for this product');
    }

    public function test_ignores_a_sync_record_belonging_to_another_channel(): void
    {
        $product = Product::factory()->create();
        SyncRecord::factory()->create(['bc_id' => $product->bc_id, 'channel' => 'customers']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('No sync record exists for this product');
    }

    public function test_shows_the_raw_business_central_payload(): void
    {
        $product = Product::factory()->create([
            'bc_payload' => ['number' => 'POLY1', 'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS']]],
        ]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Raw Business Central data')
            ->assertSee('BROOKLANDS');
    }

    public function test_the_website_state_section_shows_the_calculated_values(): void
    {
        $product = Product::factory()->create([
            'sku' => 'SF180',
            'name' => 'PAW MediDerm Shampoo',
            'type' => 'Inventory',
            'price' => 18.22,
            'gtin' => '9312345678907',
            'bc_payload' => [
                'stockkeepingUnits' => [
                    ['locationCode' => 'LIVESTOCK', 'inventory' => 99],
                    ['locationCode' => 'BROOKLANDS', 'inventory' => 6],
                ],
                'itemDefaultDimensions' => [
                    ['dimensionCode' => 'BRAND', 'dimensionValueCode' => 'PAW', 'dimensionValueName' => 'Paw'],
                    ['dimensionCode' => 'DEPARTMENT', 'dimensionValueCode' => 'DOG', 'dimensionValueName' => 'Dog'],
                    ['dimensionCode' => 'SHIPPING TYPE', 'dimensionValueCode' => 'NON-STANDARD', 'dimensionValueName' => 'Non-standard'],
                ],
                'priceListLines' => [
                    ['salesCode' => 'RRP', 'unitPrice' => 24.99, 'amountType' => 'Price'],
                    ['salesCode' => 'LIST PRICE', 'salesType' => 'Customer_x0020_Price_x0020_Group', 'unitPrice' => 18.22, 'amountType' => 'Price'],
                ],
                'itemAttributes' => [
                    ['itemAttributeName' => 'Size', 'itemAttributeValueName' => '200mL'],
                ],
            ],
        ]);

        $response = $this->get(route('products.show', $product));

        $response->assertOk()
            ->assertSee('Website state')
            ->assertSee('INVENTORY')
            ->assertSee('BROOKLANDS')
            ->assertSee('non-standard')
            ->assertSee('9312345678907')
            ->assertSee('Paw (PAW)')
            ->assertSee('Dog (DOG)')
            ->assertSee('24.99')
            ->assertSee('LIST PRICE')
            ->assertSee('Customer Price Group')
            ->assertSee('Size')
            ->assertSee('200mL');
    }

    public function test_the_website_state_reports_a_product_with_no_pricing_or_attributes(): void
    {
        $product = Product::factory()->create(['bc_payload' => []]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('No pricing rules')
            ->assertSee('No attributes');
    }

    public function test_the_website_state_shows_the_exclusion_reason_for_a_blocked_product(): void
    {
        $product = Product::factory()->create(['blocked' => true, 'bc_payload' => []]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Website state')
            ->assertSee('Product is blocked in Business Central');
    }

    public function test_the_website_state_shows_the_stock_position(): void
    {
        $product = Product::factory()->create([
            'sku' => 'AA27',
            'type' => 'Inventory',
            'bc_payload' => [
                'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 20]],
            ],
        ]);
        ProductQuantity::factory()->create([
            'bc_id' => $product->bc_id,
            'inventory' => 50,
            'qty_on_transfer_order' => 5,
            'qty_on_sales_order' => 4,
            'qty_on_purchase_order' => 2210,
            'next_purchase_receipt_date' => now()->addWeek()->toDateString(),
        ]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Stock position')
            ->assertSee('Inventory order')
            ->assertSee('45.00')   // 50 on hand less 5 transfers, uncapped
            ->assertSee('11.00')   // min(50, 20) less 5 transfers less 4 sales orders
            ->assertSee('2,210.00')
            ->assertSee(now()->addWeek()->toDateString());
    }

    public function test_the_website_state_reports_when_no_quantity_data_exists(): void
    {
        $product = Product::factory()->create(['bc_payload' => []]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('No quantity data imported yet')
            ->assertSee('bc:import-item-quantities');
    }

    public function test_returns_404_for_an_unknown_product(): void
    {
        $this->get('/products/999999')->assertNotFound();
    }
}
