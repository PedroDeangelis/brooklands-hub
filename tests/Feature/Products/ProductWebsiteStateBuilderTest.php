<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductQuantity;
use App\Products\ExclusionReason;
use App\Products\ProductWebsiteState;
use App\Products\ProductWebsiteStateBuilder;
use App\Products\Website\LocationSelector;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductWebsiteStateBuilderTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     */
    private function stateFor(array $attributes = [], array $payload = []): ProductWebsiteState
    {
        $product = Product::factory()->make(array_merge([
            'sku' => 'SF180',
            'name' => 'PAW MediDerm Shampoo 200mL',
            'type' => 'Inventory',
            'price' => 18.22,
            'blocked' => false,
            'sales_blocked' => false,
            'gtin' => '',
            'item_category_id' => '',
        ], $attributes, ['bc_payload' => $payload]));

        return app(ProductWebsiteStateBuilder::class)->build($product);
    }

    /**
     * @param  array<string, string>  $codes
     * @return list<array<string, string>>
     */
    private function dimensions(array $codes): array
    {
        return array_map(
            fn (string $code, string $value): array => [
                'dimensionCode' => $code,
                'dimensionValueCode' => mb_strtoupper($value),
                'dimensionValueName' => ucfirst($value),
            ],
            array_keys($codes),
            array_values($codes),
        );
    }

    public function test_it_carries_the_identity_fields_through(): void
    {
        $state = $this->stateFor(['sku' => 'POLY1', 'name' => 'Poly Lead', 'price' => 12.5]);

        $this->assertSame('POLY1', $state->sku);
        $this->assertSame('Poly Lead', $state->name);
        $this->assertSame(12.5, $state->price);
    }

    public function test_a_blocked_product_is_excluded(): void
    {
        $state = $this->stateFor(['blocked' => true]);

        $this->assertFalse($state->isEligible());
        $this->assertTrue($state->eligibility->hasReason(ExclusionReason::Blocked));
        $this->assertFalse($state->isPurchasable());
    }

    public function test_a_retired_product_is_excluded(): void
    {
        $state = $this->stateFor(['item_category_id' => 'RETIRE']);

        $this->assertFalse($state->isEligible());
        $this->assertTrue($state->eligibility->hasReason(ExclusionReason::Retired));
    }

    public function test_an_ordinary_product_is_eligible_with_no_reason(): void
    {
        $state = $this->stateFor();

        $this->assertTrue($state->isEligible());
        $this->assertSame([], $state->eligibility->reasons());
        $this->assertTrue($state->isPurchasable());
    }

    public function test_a_zero_price_is_not_published(): void
    {
        // Business Central uses zero for items with no sell price set; the website
        // must not advertise $0.00.
        $this->assertNull($this->stateFor(['price' => 0])->price);
    }

    public function test_a_sales_blocked_product_stays_listed_but_is_not_purchasable(): void
    {
        // Unlike exclusion, a sales block leaves the product browsable.
        $state = $this->stateFor(['sales_blocked' => true]);

        $this->assertTrue($state->salesBlocked);
        $this->assertTrue($state->isEligible());
        $this->assertFalse($state->isPurchasable());
    }

    public function test_the_barcode_comes_from_the_gtin_and_is_null_when_absent(): void
    {
        $this->assertSame('9312345678907', $this->stateFor(['gtin' => ' 9312345678907 '])->barcode);
        $this->assertNull($this->stateFor(['gtin' => ''])->barcode);
        $this->assertNull($this->stateFor(['gtin' => '   '])->barcode);
    }

    public function test_an_inventory_item_tracks_stock(): void
    {
        $state = $this->stateFor(['type' => 'Inventory']);

        $this->assertSame(ProductWebsiteStateBuilder::TYPE_INVENTORY, $state->inventoryType);
        $this->assertTrue($state->tracksInventory());
    }

    public function test_the_escaped_non_inventory_type_is_decoded(): void
    {
        $state = $this->stateFor(['type' => 'Non_x002D_Inventory']);

        $this->assertSame(ProductWebsiteStateBuilder::TYPE_NON_INVENTORY, $state->inventoryType);
        $this->assertFalse($state->tracksInventory());
    }

    public function test_a_missing_type_is_treated_as_non_inventory(): void
    {
        $this->assertSame(
            ProductWebsiteStateBuilder::TYPE_NON_INVENTORY,
            $this->stateFor(['type' => ''])->inventoryType,
        );
    }

    public function test_a_non_inventory_item_defaults_to_the_general_location(): void
    {
        // Business Central requires stockkeeping units to be removed before the
        // type can become non-inventory, so these items carry no location signal.
        $state = $this->stateFor(['type' => 'Non_x002D_Inventory'], [
            'stockkeepingUnits' => [['locationCode' => 'LIVESTOCK', 'inventory' => 50]],
        ]);

        $this->assertSame(LocationSelector::BROOKLANDS, $state->location->code);
        $this->assertSame(0, $state->location->inventory);
    }

    public function test_an_inventory_item_selects_its_location_by_precedence(): void
    {
        $state = $this->stateFor([], [
            'stockkeepingUnits' => [
                ['locationCode' => 'LIVESTOCK', 'inventory' => 99],
                ['locationCode' => 'BROOKLANDS', 'inventory' => 4],
            ],
        ]);

        $this->assertSame(LocationSelector::BROOKLANDS, $state->location->code);
        $this->assertSame(4, $state->location->inventory);
    }

    public function test_an_inventory_item_without_stockkeeping_units_resolves_no_location(): void
    {
        $state = $this->stateFor([], ['stockkeepingUnits' => []]);

        $this->assertFalse($state->location->isResolved());
    }

    public function test_dimensions_map_to_brand_and_the_category_path(): void
    {
        $state = $this->stateFor([], [
            'itemDefaultDimensions' => $this->dimensions([
                'BRAND' => 'paw',
                'DEPARTMENT' => 'dog',
                'CATEGORY' => 'grooming',
                'SUBCATEGORY' => 'treatment',
            ]),
        ]);

        $this->assertSame('PAW', $state->brand?->code);
        $this->assertSame('Paw', $state->brand?->title);
        $this->assertSame('DOG', $state->department?->code);
        $this->assertSame('GROOMING', $state->category?->code);
        $this->assertSame('TREATMENT', $state->subcategory?->code);
        $this->assertSame(['DOG', 'GROOMING', 'TREATMENT'], array_column(
            array_map(fn ($t) => ['code' => $t->code], $state->categoryPath()), 'code'
        ));
    }

    public function test_missing_dimensions_are_null(): void
    {
        $state = $this->stateFor([], ['itemDefaultDimensions' => $this->dimensions(['DEPARTMENT' => 'dog'])]);

        $this->assertSame('DOG', $state->department?->code);
        $this->assertNull($state->brand);
        $this->assertNull($state->category);
        $this->assertNull($state->subcategory);
        $this->assertCount(1, $state->categoryPath());
    }

    public function test_a_dimension_without_a_value_code_is_ignored(): void
    {
        $state = $this->stateFor([], [
            'itemDefaultDimensions' => [
                ['dimensionCode' => 'BRAND', 'dimensionValueCode' => '', 'dimensionValueName' => 'Nameless'],
            ],
        ]);

        $this->assertNull($state->brand);
    }

    public function test_a_dimension_without_a_display_name_falls_back_to_its_code(): void
    {
        $state = $this->stateFor([], [
            'itemDefaultDimensions' => [
                ['dimensionCode' => 'BRAND', 'dimensionValueCode' => 'PAW', 'dimensionValueName' => ''],
            ],
        ]);

        $this->assertSame('PAW', $state->brand?->title);
        $this->assertSame('PAW', $state->brand?->label());
    }

    public function test_the_shipping_class_is_standard_by_default(): void
    {
        $this->assertSame(
            ProductWebsiteStateBuilder::SHIPPING_STANDARD,
            $this->stateFor([], ['itemDefaultDimensions' => []])->shippingClass,
        );
    }

    public function test_a_non_standard_shipping_type_sets_the_non_standard_class(): void
    {
        $state = $this->stateFor([], [
            'itemDefaultDimensions' => [[
                'dimensionCode' => 'SHIPPING TYPE',
                'dimensionValueCode' => 'NON-STANDARD',
                'dimensionValueName' => 'Non-standard',
            ]],
        ]);

        $this->assertSame(ProductWebsiteStateBuilder::SHIPPING_NON_STANDARD, $state->shippingClass);
    }

    public function test_any_other_shipping_type_stays_standard(): void
    {
        $state = $this->stateFor([], [
            'itemDefaultDimensions' => [[
                'dimensionCode' => 'SHIPPING TYPE',
                'dimensionValueCode' => 'STANDARD',
                'dimensionValueName' => 'Standard',
            ]],
        ]);

        $this->assertSame(ProductWebsiteStateBuilder::SHIPPING_STANDARD, $state->shippingClass);
    }

    public function test_pricing_rules_and_the_rrp_come_from_the_price_list_lines(): void
    {
        $state = $this->stateFor([], [
            'priceListLines' => [
                ['salesCode' => 'RRP', 'unitPrice' => 24.99, 'amountType' => 'Price'],
                ['salesCode' => 'LIST PRICE', 'unitPrice' => 18.22, 'amountType' => 'Price', 'salesType' => 'Customer'],
            ],
        ]);

        $this->assertSame(24.99, $state->rrp);
        $this->assertCount(1, $state->pricingRules);
        $this->assertSame('LIST PRICE', $state->pricingRules[0]->salesCode);
        $this->assertSame(18.22, $state->pricingRules[0]->price);
    }

    public function test_attributes_are_read_and_valueless_ones_dropped(): void
    {
        $state = $this->stateFor([], [
            'itemAttributes' => [
                ['itemAttributeName' => 'Size', 'itemAttributeValueName' => '200mL'],
                ['itemAttributeName' => 'Colour', 'itemAttributeValueName' => ''],
                ['itemAttributeName' => 'Scent', 'itemAttributeValueName' => 'Unscented'],
            ],
        ]);

        $this->assertCount(2, $state->attributes);
        $this->assertSame('Size', $state->attributes[0]->name);
        $this->assertSame('200mL', $state->attributes[0]->value);
        $this->assertSame('Scent', $state->attributes[1]->name);
    }

    public function test_stock_is_unknown_until_a_quantity_row_is_imported(): void
    {
        // The two Business Central entities are imported independently, so a
        // product can exist with no figures yet.
        $product = Product::factory()->create(['bc_payload' => []]);

        $state = app(ProductWebsiteStateBuilder::class)->build($product);

        $this->assertFalse($state->stock->isKnown());
        $this->assertFalse($state->stock->hasSellableStock());
    }

    public function test_it_combines_the_product_with_its_quantity_row(): void
    {
        $product = Product::factory()->create([
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
        ]);

        $state = app(ProductWebsiteStateBuilder::class)->build($product);

        $this->assertTrue($state->stock->isKnown());
        // Item-level on-hand stays uncapped: 50 - 5 transfers.
        $this->assertSame(45.0, $state->stock->inventoryOrder);
        // Sellable is capped at the location: min(50, 20) - 5 - 4.
        $this->assertSame(11.0, $state->stock->sellableStock);
        $this->assertTrue($state->stock->locationCapped);
    }

    public function test_the_location_cap_comes_from_the_item_stockkeeping_units(): void
    {
        // The cap is the location figure the item import stored, not the
        // item-level total the quantity row carries.
        $product = Product::factory()->create([
            'type' => 'Inventory',
            'bc_payload' => [
                'stockkeepingUnits' => [
                    ['locationCode' => 'LIVESTOCK', 'inventory' => 900],
                    ['locationCode' => 'BROOKLANDS', 'inventory' => 3],
                ],
            ],
        ]);
        ProductQuantity::factory()->create(['bc_id' => $product->bc_id, 'inventory' => 900]);

        $state = app(ProductWebsiteStateBuilder::class)->build($product);

        $this->assertSame('BROOKLANDS', $state->location->code);
        $this->assertSame(3.0, $state->stock->sellableStock);
        $this->assertSame(900.0, $state->stock->inventoryOrder);
    }

    public function test_a_non_inventory_item_is_available_without_tracked_stock(): void
    {
        // Non-inventory items carry no location signal and are always available.
        $product = Product::factory()->create(['type' => 'Non_x002D_Inventory', 'bc_payload' => []]);
        ProductQuantity::factory()->create(['bc_id' => $product->bc_id, 'inventory' => 0]);

        $state = app(ProductWebsiteStateBuilder::class)->build($product);

        $this->assertFalse($state->tracksInventory());
        $this->assertSame(0.0, $state->stock->sellableStock);
        $this->assertTrue($state->isAvailableToBuy());
    }

    public function test_a_tracked_item_without_sellable_stock_is_not_available(): void
    {
        $product = Product::factory()->create(['type' => 'Inventory', 'bc_payload' => []]);
        ProductQuantity::factory()->create([
            'bc_id' => $product->bc_id,
            'inventory' => 5,
            'qty_on_sales_order' => 5,
        ]);

        $state = app(ProductWebsiteStateBuilder::class)->build($product);

        $this->assertTrue($state->isPurchasable());
        $this->assertFalse($state->isAvailableToBuy());
    }

    public function test_an_excluded_product_is_never_available_however_much_stock_it_has(): void
    {
        $product = Product::factory()->create(['blocked' => true, 'type' => 'Inventory', 'bc_payload' => []]);
        ProductQuantity::factory()->create(['bc_id' => $product->bc_id, 'inventory' => 500]);

        $state = app(ProductWebsiteStateBuilder::class)->build($product);

        $this->assertSame(500.0, $state->stock->sellableStock, 'stock is still calculated');
        $this->assertFalse($state->isAvailableToBuy());
    }

    public function test_a_sales_blocked_product_is_not_available_despite_stock(): void
    {
        $product = Product::factory()->create(['sales_blocked' => true, 'type' => 'Inventory', 'bc_payload' => []]);
        ProductQuantity::factory()->create(['bc_id' => $product->bc_id, 'inventory' => 30]);

        $state = app(ProductWebsiteStateBuilder::class)->build($product);

        $this->assertSame(30.0, $state->stock->sellableStock);
        $this->assertFalse($state->isAvailableToBuy());
    }

    public function test_it_uses_an_already_loaded_quantity_relation(): void
    {
        $product = Product::factory()->create(['type' => 'Inventory', 'bc_payload' => []]);
        ProductQuantity::factory()->create(['bc_id' => $product->bc_id, 'inventory' => 12]);

        $loaded = Product::with('quantity')->find($product->id);

        $state = app(ProductWebsiteStateBuilder::class)->build($loaded);

        $this->assertSame(12.0, $state->stock->sellableStock);
    }

    public function test_an_empty_payload_produces_a_usable_state(): void
    {
        $state = $this->stateFor([], []);

        $this->assertTrue($state->isEligible());
        $this->assertNull($state->brand);
        $this->assertSame([], $state->pricingRules);
        $this->assertSame([], $state->attributes);
        $this->assertNull($state->rrp);
        $this->assertSame(ProductWebsiteStateBuilder::SHIPPING_STANDARD, $state->shippingClass);
    }

    public function test_collections_of_the_wrong_shape_are_ignored(): void
    {
        $state = $this->stateFor([], [
            'stockkeepingUnits' => 'nonsense',
            'itemDefaultDimensions' => 42,
            'priceListLines' => null,
            'itemAttributes' => 'nope',
        ]);

        $this->assertFalse($state->location->isResolved());
        $this->assertNull($state->brand);
        $this->assertSame([], $state->pricingRules);
        $this->assertSame([], $state->attributes);
    }
}
