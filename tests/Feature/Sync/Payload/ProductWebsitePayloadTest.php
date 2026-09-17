<?php

namespace Tests\Feature\Sync\Payload;

use App\Models\Product;
use App\Models\ProductMarketingText;
use App\Models\ProductQuantity;
use App\Sync\Payload\DeliveryType;
use App\Sync\Payload\ProductWebsitePayloadBuilder;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The outbound website payload and the partial change that would be sent.
 *
 * Everything here compares finished website payloads, never Business Central
 * fields: one Business Central change can move several website values at once.
 */
class ProductWebsitePayloadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function ledger(): SyncLedger
    {
        return app(SyncLedger::class);
    }

    private function builder(): ProductWebsitePayloadBuilder
    {
        return app(ProductWebsitePayloadBuilder::class);
    }

    /**
     * A stored, eligible product with a full payload's worth of data.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $quantity
     */
    private function product(array $attributes = [], ?array $quantity = ['inventory' => 20]): Product
    {
        $product = Product::factory()->create(array_merge([
            'bc_id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'sku' => 'POLY1',
            'name' => 'Poly Bin',
            'name_2' => '',
            'type' => 'Inventory',
            'price' => 10,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'gtin' => '9419423000794',
            'bc_payload' => [
                'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 50]],
                'itemDefaultDimensions' => [
                    ['dimensionCode' => 'BRAND', 'dimensionValueCode' => 'PAW', 'dimensionValueName' => 'Paw'],
                    ['dimensionCode' => 'DEPARTMENT', 'dimensionValueCode' => 'DOG', 'dimensionValueName' => 'Dog'],
                ],
                'itemAttributes' => [['itemAttributeName' => 'Size', 'itemAttributeValueName' => 'Small']],
                'priceListLines' => [['salesCode' => 'RRP', 'unitPrice' => 14.95]],
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

        return $product->fresh();
    }

    /**
     * Deliver whatever is currently desired, so later changes diff against it.
     */
    private function deliver(Product $product): void
    {
        $record = $this->ledger()->reconcile($product) ?? $this->ledger()->find($product);
        $this->ledger()->markSynced($record);
    }

    // ------------------------------------------------------------- full payload

    public function test_the_full_payload_carries_every_website_field(): void
    {
        $payload = $this->builder()->build($this->product());

        $this->assertSame([
            'bc_id', 'sku', 'name', 'name_2', 'description', 'short_description',
            'price', 'rrp', 'purchasable', 'manage_stock', 'website_quantity',
            'stock_status', 'inventory_type', 'barcode', 'location', 'brand',
            'categories', 'shipping_class', 'attributes', 'group_prices', 'attachments',
        ], array_keys($payload));
    }

    public function test_the_full_payload_uses_the_values_the_domain_already_calculated(): void
    {
        $payload = $this->builder()->build($this->product());

        $this->assertSame('ab3349b2-3d1c-f111-8341-6045bde65a16', $payload['bc_id']);
        $this->assertSame('POLY1', $payload['sku']);
        $this->assertSame(10.0, $payload['price']);
        $this->assertSame(14.95, $payload['rrp']);
        $this->assertTrue($payload['purchasable']);
        $this->assertTrue($payload['manage_stock']);
        $this->assertSame(20.0, $payload['website_quantity']);
        $this->assertSame('instock', $payload['stock_status']);
        $this->assertSame('INVENTORY', $payload['inventory_type']);
        $this->assertSame('9419423000794', $payload['barcode']);
        $this->assertSame(['code' => 'BROOKLANDS', 'inventory' => 50], $payload['location']);
        $this->assertSame(['code' => 'PAW', 'title' => 'Paw'], $payload['brand']);
        $this->assertSame(['code' => 'DOG', 'title' => 'Dog'], $payload['categories']['department']);
        $this->assertSame('standard', $payload['shipping_class']);
        $this->assertSame([['name' => 'Size', 'value' => 'Small']], $payload['attributes']);
    }

    public function test_the_payload_is_deterministic(): void
    {
        $product = $this->product();

        $this->assertSame(
            $this->builder()->build($product),
            $this->builder()->build($product->fresh()),
        );
    }

    // ----------------------------------------------------------- remove payload

    public function test_an_excluded_product_produces_a_minimal_remove_payload(): void
    {
        $payload = $this->builder()->build($this->product(['gppg' => 'RETIRED']));

        $this->assertSame(['bc_id', 'sku', 'reasons'], array_keys($payload));
        $this->assertSame(['not_finished_goods'], $payload['reasons']);
    }

    /**
     * The website is told what to do, not asked to work out eligibility again.
     */
    public function test_the_remove_payload_carries_reason_codes_not_sentences(): void
    {
        $payload = $this->builder()->build(
            $this->product(['gppg' => 'RETIRED', 'price' => 0]),
        );

        $this->assertSame(['no_price', 'not_finished_goods'], $payload['reasons']);
    }

    // -------------------------------------------------------------- first sync

    public function test_a_first_delivery_sends_the_full_payload(): void
    {
        $plan = $this->ledger()->plan($this->product());

        $this->assertSame(DeliveryType::Full, $plan->type);
        $this->assertSame(WebsiteAction::Upsert, $plan->action);
        $this->assertSame($plan->fullPayload, $plan->envelope['changes']);
        $this->assertSame('upsert', $plan->envelope['action']);
    }

    public function test_an_unchanged_product_has_nothing_to_send(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(DeliveryType::None, $plan->type);
        $this->assertTrue($plan->diff->isEmpty());
        $this->assertSame([], $plan->envelope);
        $this->assertFalse($plan->sendsAnything());
    }

    // ----------------------------------------------------------------- partials

    public function test_a_price_change_sends_only_the_price(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $product->update(['price' => 12]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(DeliveryType::Partial, $plan->type);
        $this->assertSame(['price' => 12.0], $plan->diff->changes);
        $this->assertSame(
            ['action' => 'upsert', 'bc_id' => $product->bc_id, 'changes' => ['price' => 12.0]],
            $plan->envelope,
        );
    }

    public function test_a_name_change_sends_only_the_name(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $product->update(['name' => 'Poly Bin Large']);

        $this->assertSame(['name' => 'Poly Bin Large'], $this->ledger()->plan($product->fresh())->diff->changes);
    }

    /**
     * The reason the diff compares website payloads rather than Business Central
     * fields: one quantity row moves several website values at once.
     */
    public function test_a_stock_change_moves_several_website_fields_at_once(): void
    {
        $product = $this->product(quantity: ['inventory' => 20]);
        $this->deliver($product);

        // The stock runs out. Nothing on the product itself changes.
        $product->quantity->update(['inventory' => 0]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(DeliveryType::Partial, $plan->type);
        $this->assertSame(['stock_status', 'website_quantity'], $plan->diff->changedFields);
        $this->assertSame('outofstock', $plan->diff->changes['stock_status']);
        $this->assertSame(0.0, $plan->diff->changes['website_quantity']);
    }

    /**
     * A sales block moves three website fields from one Business Central flag.
     */
    public function test_a_sales_block_moves_several_website_fields_at_once(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $product->update(['sales_blocked' => true]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(
            ['manage_stock', 'purchasable', 'stock_status', 'website_quantity'],
            $plan->diff->changedFields,
        );
        $this->assertFalse($plan->diff->changes['purchasable']);
        $this->assertSame('outofstock', $plan->diff->changes['stock_status']);
    }

    // ------------------------------------------------------- nested structures

    public function test_a_pricing_change_is_detected(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $payload = $product->bc_payload;
        $payload['priceListLines'] = [['salesCode' => 'RRP', 'unitPrice' => 19.95]];
        $product->update(['bc_payload' => $payload]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertContains('rrp', $plan->diff->changedFields);
        $this->assertSame(19.95, $plan->diff->changes['rrp']);
    }

    public function test_a_group_price_change_resends_the_whole_group_price_list(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $payload = $product->bc_payload;
        $payload['priceListLines'] = [[
            'salesType' => 'Customer_x0020_Price_x0020_Group',
            'salesCode' => 'TRADE',
            'unitPrice' => 8.5,
        ]];
        $product->update(['bc_payload' => $payload]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertContains('group_prices', $plan->diff->changedFields);
        $this->assertCount(1, $plan->diff->changes['group_prices']);
    }

    public function test_a_category_change_is_detected_and_sent_whole(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $payload = $product->bc_payload;
        $payload['itemDefaultDimensions'] = [
            ['dimensionCode' => 'BRAND', 'dimensionValueCode' => 'PAW', 'dimensionValueName' => 'Paw'],
            ['dimensionCode' => 'DEPARTMENT', 'dimensionValueCode' => 'CAT', 'dimensionValueName' => 'Cat'],
        ];
        $product->update(['bc_payload' => $payload]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(['categories'], $plan->diff->changedFields);
        $this->assertSame(['code' => 'CAT', 'title' => 'Cat'], $plan->diff->changes['categories']['department']);
        // Sent whole: the unchanged levels travel with it.
        $this->assertArrayHasKey('category', $plan->diff->changes['categories']);
    }

    public function test_an_attribute_change_is_detected_and_sent_whole(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $payload = $product->bc_payload;
        $payload['itemAttributes'] = [
            ['itemAttributeName' => 'Size', 'itemAttributeValueName' => 'Large'],
            ['itemAttributeName' => 'Colour', 'itemAttributeValueName' => 'Blue'],
        ];
        $product->update(['bc_payload' => $payload]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(['attributes'], $plan->diff->changedFields);
        $this->assertCount(2, $plan->diff->changes['attributes']);
    }

    public function test_a_location_change_is_detected_and_sent_whole(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $payload = $product->bc_payload;
        $payload['stockkeepingUnits'] = [['locationCode' => 'BROOKLANDS', 'inventory' => 3]];
        $product->update(['bc_payload' => $payload]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertContains('location', $plan->diff->changedFields);
        $this->assertSame(['code' => 'BROOKLANDS', 'inventory' => 3], $plan->diff->changes['location']);
    }

    // ------------------------------------------------------------- transitions

    public function test_eligible_to_excluded_switches_to_a_remove_delivery(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $product->update(['gppg' => 'RETIRED']);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(WebsiteAction::Remove, $plan->action);
        $this->assertSame(DeliveryType::Remove, $plan->type);
        $this->assertSame(['not_finished_goods'], $plan->envelope['reasons']);
        // A removal never diffs field values.
        $this->assertArrayNotHasKey('changes', $plan->envelope);
    }

    public function test_excluded_to_eligible_switches_back_to_a_full_upsert(): void
    {
        $product = $this->product(['gppg' => 'RETIRED']);
        $this->deliver($product);

        $product->update(['gppg' => 'FINISHED GOODS']);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(WebsiteAction::Upsert, $plan->action);
        // The website holds nothing to apply a partial change to.
        $this->assertSame(DeliveryType::Full, $plan->type);
        $this->assertSame($plan->fullPayload, $plan->envelope['changes']);
    }

    // ------------------------------------------------------------------ hashes

    public function test_equivalent_payloads_produce_identical_hashes(): void
    {
        $ledger = $this->ledger();
        $payload = ['price' => 10, 'location' => ['code' => 'A', 'inventory' => 1]];
        $reordered = ['location' => ['inventory' => 1, 'code' => 'A'], 'price' => 10];

        $this->assertSame($ledger->hash($payload), $ledger->hash($reordered));
    }

    public function test_a_different_payload_produces_a_different_hash(): void
    {
        $ledger = $this->ledger();

        $this->assertNotSame($ledger->hash(['price' => 10]), $ledger->hash(['price' => 12]));
    }

    // -------------------------------------------------------------- persistence

    public function test_the_ledger_stores_the_desired_payload(): void
    {
        $record = $this->ledger()->reconcile($this->product());

        $this->assertSame('POLY1', $record->payload['sku']);
        $this->assertNull($record->delivered_payload);
        $this->assertSame($this->ledger()->hash($record->payload), $record->payload_hash);
    }

    public function test_the_ledger_stores_the_delivered_payload_on_success(): void
    {
        $product = $this->product();
        $this->deliver($product);

        $record = $this->ledger()->find($product);

        $this->assertSame($record->payload, $record->delivered_payload);
        $this->assertSame($record->payload_hash, $record->delivered_hash);
    }

    // ------------------------------------------------------- marketing copy

    /**
     * Copy is stored sanitised, so the payload carries it as it stands. This is
     * what puts a description on the website at all.
     */
    public function test_the_payload_carries_the_marketing_copy(): void
    {
        $product = $this->product();

        ProductMarketingText::factory()->create([
            'bc_id' => $product->bc_id,
            'sku' => $product->sku,
            'marketing_text' => 'A sturdy poly bin. Holds twenty litres.',
            'short_description' => 'A sturdy poly bin.',
        ]);

        $payload = $this->builder()->build($product->fresh());

        $this->assertSame('A sturdy poly bin. Holds twenty litres.', $payload['description']);
        $this->assertSame('A sturdy poly bin.', $payload['short_description']);
    }

    /**
     * Most items have no copy written for them. The fields are still sent, as
     * empty strings, so the website is told to hold nothing rather than left
     * with whatever it had.
     */
    public function test_a_product_with_no_copy_sends_empty_strings(): void
    {
        $payload = $this->builder()->build($this->product());

        $this->assertSame('', $payload['description']);
        $this->assertSame('', $payload['short_description']);
    }

    /**
     * An edit in Business Central has to reach the website, and only the two
     * copy fields should move with it.
     */
    public function test_editing_the_copy_produces_a_partial_delivery(): void
    {
        $product = $this->product();

        $copy = ProductMarketingText::factory()->create([
            'bc_id' => $product->bc_id,
            'sku' => $product->sku,
            'marketing_text' => 'First copy. Second sentence.',
            'short_description' => 'First copy.',
        ]);

        $this->deliver($product->fresh());

        $copy->update([
            'marketing_text' => 'Rewritten copy. Second sentence.',
            'short_description' => 'Rewritten copy.',
        ]);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(['description', 'short_description'], $plan->diff->changedFields);
        $this->assertSame('Rewritten copy. Second sentence.', $plan->diff->changes['description']);
        $this->assertSame('Rewritten copy.', $plan->diff->changes['short_description']);
    }

    /**
     * Clearing the copy in Business Central must clear it on the website, so
     * the empty value is sent rather than the field being dropped.
     */
    public function test_clearing_the_copy_sends_empty_strings(): void
    {
        $product = $this->product();

        $copy = ProductMarketingText::factory()->create([
            'bc_id' => $product->bc_id,
            'sku' => $product->sku,
            'marketing_text' => 'Copy that is about to go. And more.',
            'short_description' => 'Copy that is about to go.',
        ]);

        $this->deliver($product->fresh());

        $copy->update(['marketing_text' => '', 'short_description' => '']);

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(['description', 'short_description'], $plan->diff->changedFields);
        $this->assertSame('', $plan->diff->changes['description']);
        $this->assertSame('', $plan->diff->changes['short_description']);
    }

    // --------------------------------------------------------------------- UI

    public function test_the_detail_page_previews_a_full_delivery(): void
    {
        $this->get(route('products.show', $this->product()))
            ->assertOk()
            ->assertSee('Delivery preview')
            ->assertSee('Desired action')
            ->assertSee('Full payload hash')
            ->assertSee('Delivery type')
            ->assertSee('Full');
    }

    public function test_the_detail_page_previews_a_partial_delivery(): void
    {
        $product = $this->product();
        $this->deliver($product);
        $product->update(['price' => 12]);

        $this->get(route('products.show', $product->fresh()))
            ->assertOk()
            ->assertSee('Partial')
            ->assertSee('&quot;price&quot;: 12', false);
    }

    public function test_the_detail_page_previews_a_removal(): void
    {
        $this->get(route('products.show', $this->product(['gppg' => 'RETIRED'])))
            ->assertOk()
            ->assertSee('Delivery preview')
            ->assertSee('Remove')
            ->assertSee('not_finished_goods');
    }
}
