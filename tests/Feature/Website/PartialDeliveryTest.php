<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Models\Product;
use App\Models\ProductQuantity;
use App\Models\SyncRecord;
use App\Sync\Payload\DeliveryType;
use App\Sync\SyncLedger;
use App\Website\WebsiteClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivering only the website fields that changed.
 */
class PartialDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = 'https://website.test/wp-json/horizon/v2/products';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.website', [
            'url' => self::ENDPOINT,
            'secret' => 'shared-secret-for-tests',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
    }

    private function ledger(): SyncLedger
    {
        return app(SyncLedger::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $quantity
     */
    private function product(array $attributes = [], ?array $quantity = ['inventory' => 25]): Product
    {
        $product = Product::factory()->create(array_merge([
            'sku' => 'V2PART-A',
            'name' => 'Partial test A',
            'type' => 'Inventory',
            'price' => 10,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'gtin' => '8880000000011',
            'bc_payload' => [
                'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 60]],
                'priceListLines' => [['salesCode' => 'RRP', 'unitPrice' => 19.95]],
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

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true, 'wp_id' => 116064], 200)]);
    }

    /**
     * Deliver the current state so later changes diff against it.
     *
     * $then is the response the delivery under test should meet, queued behind
     * the baseline's success: Http::fake() stubs accumulate rather than replace,
     * so a sequence is the only way to say "succeed, then answer this".
     *
     * @param  array<string, mixed>|null  $then
     */
    private function baseline(Product $product, ?array $then = null, int $thenStatus = 200): SyncRecord
    {
        if ($then === null) {
            $this->accepted();
        } else {
            Http::fake([self::ENDPOINT => Http::sequence()
                ->push(['ok' => true, 'applied' => true, 'wp_id' => 116064], 200)
                ->push($then, $thenStatus),
            ]);
        }

        $record = $this->ledger()->reconcile($product) ?? $this->ledger()->find($product);
        $this->deliver($product);

        return $record->refresh();
    }

    private function deliver(Product $product): void
    {
        (new DeliverProductToWebsite($product->bc_id))
            ->handle($this->ledger(), app(WebsiteClient::class));
    }

    /**
     * The body of the most recent request.
     *
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $sent = [];

        Http::assertSent(function (Request $request) use (&$sent): bool {
            $sent = $request->data();

            return true;
        });

        return $sent;
    }

    // ------------------------------------------------------------- envelopes

    public function test_a_price_change_sends_a_partial_carrying_only_the_price(): void
    {
        $product = $this->product();
        $this->baseline($product);

        $product->update(['price' => 18.08]);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $body = $this->sentBody();

        $this->assertSame('partial', $body['mode']);
        $this->assertSame(['price' => 18.08], $body['changes']);
        $this->assertArrayNotHasKey('payload', $body);
    }

    public function test_a_name_change_sends_only_the_name(): void
    {
        $product = $this->product();
        $this->baseline($product);

        $product->update(['name' => 'Renamed']);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $this->assertSame(['name' => 'Renamed'], $this->sentBody()['changes']);
    }

    public function test_a_stock_change_sends_every_affected_website_field(): void
    {
        $product = $this->product(quantity: ['inventory' => 25]);
        $this->baseline($product);

        $product->quantity->update(['inventory' => 0]);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $changes = $this->sentBody()['changes'];

        $this->assertSame(['stock_status', 'website_quantity'], array_keys($changes));
        $this->assertSame('outofstock', $changes['stock_status']);
    }

    /**
     * One Business Central flag moves four website fields at once.
     */
    public function test_a_sales_block_sends_all_the_fields_it_affects(): void
    {
        $product = $this->product();
        $this->baseline($product);

        $product->update(['sales_blocked' => true]);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $changes = $this->sentBody()['changes'];

        $this->assertSame(
            ['manage_stock', 'purchasable', 'stock_status', 'website_quantity'],
            array_keys($changes),
        );
        $this->assertFalse($changes['purchasable']);
    }

    /**
     * Null is an instruction to clear, not an absence of one.
     */
    public function test_a_cleared_value_is_sent_as_an_explicit_null(): void
    {
        $product = $this->product();
        $this->baseline($product);

        // Removing the RRP price list line clears the website's RRP.
        $product->update(['bc_payload' => ['stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 60]]]]);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $changes = $this->sentBody()['changes'];

        $this->assertArrayHasKey('rrp', $changes);
        $this->assertNull($changes['rrp']);
    }

    public function test_a_cleared_barcode_is_sent_as_an_explicit_null(): void
    {
        $product = $this->product();
        $this->baseline($product);

        $product->update(['gtin' => '']);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $this->assertNull($this->sentBody()['changes']['barcode']);
    }

    /**
     * Nested values are sent whole so the website never has to merge.
     */
    public function test_a_nested_change_sends_the_complete_structure(): void
    {
        $product = $this->product();
        $this->baseline($product);

        $payload = $product->bc_payload;
        $payload['stockkeepingUnits'] = [['locationCode' => 'LIVESTOCK', 'inventory' => 3]];
        $product->update(['bc_payload' => $payload]);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $changes = $this->sentBody()['changes'];

        $this->assertSame(['code' => 'LIVESTOCK', 'inventory' => 3], $changes['location']);
    }

    // ---------------------------------------------------------------- ledger

    /**
     * The website now holds the whole state, so the next diff is computed
     * against all of it rather than the fragment sent.
     */
    public function test_a_successful_partial_marks_the_complete_payload_delivered(): void
    {
        $product = $this->product();
        $record = $this->baseline($product);

        $product->update(['price' => 18.08]);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame($record->payload, $record->delivered_payload);
        $this->assertSame(18.08, $record->delivered_payload['price']);
        $this->assertSame('V2PART-A', $record->delivered_payload['sku']);
        $this->assertSame($record->payload_hash, $record->delivered_hash);
    }

    public function test_the_next_plan_is_nothing_once_the_partial_lands(): void
    {
        $product = $this->product();
        $this->baseline($product);

        $product->update(['price' => 18.08]);
        $this->ledger()->reconcile($product->fresh());
        $this->accepted();
        $this->deliver($product->fresh());

        $this->assertSame(DeliveryType::None, $this->ledger()->plan($product->fresh())->type);
    }

    // -------------------------------------------------------------- conflict

    public function test_a_partial_conflict_becomes_a_conflict_not_a_failure(): void
    {
        $product = $this->product();
        $record = $this->baseline($product, [
            'ok' => false,
            'conflict' => true,
            'applied' => false,
            'conflicts' => [[
                'code' => 'sku_conflict',
                'field' => 'sku',
                'value' => 'V2PART-B',
                'message' => 'SKU already belongs to another WordPress product',
                'existing_wp_id' => 116065,
                'existing_bc_id' => 'v2part-bbbb-0002',
            ]],
        ], 409);

        $product->update(['sku' => 'V2PART-B']);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());
        $record->refresh();

        $this->assertSame(SyncStatus::Conflict, $record->status);
        $this->assertSame('sku_conflict', $record->conflict_details[0]['code']);
    }

    /**
     * A conflicted partial must not record the state as delivered, or the next
     * diff would be computed against something the website never received.
     */
    public function test_a_conflicted_partial_keeps_the_previous_delivered_state(): void
    {
        $product = $this->product();
        $record = $this->baseline($product, [
            'ok' => false, 'conflict' => true, 'applied' => false,
            'conflicts' => [['code' => 'sku_conflict', 'field' => 'sku', 'value' => 'V2PART-B']],
        ], 409);
        $deliveredBefore = $record->delivered_hash;

        $product->update(['sku' => 'V2PART-B']);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());
        $record->refresh();

        $this->assertSame($deliveredBefore, $record->delivered_hash);
        $this->assertNotSame($record->payload_hash, $record->delivered_hash);
    }

    // ------------------------------------------------------ full sync recovery

    /**
     * The website has no record of the product, so the payload we were diffing
     * against describes nothing. Laravel forgets it and starts again.
     */
    public function test_a_missing_target_makes_the_next_delivery_a_full_payload(): void
    {
        $product = $this->product();
        $record = $this->baseline($product, [
            'ok' => false,
            'applied' => false,
            'full_sync_required' => true,
            'error' => 'No WordPress product carries Business Central id',
        ], 409);

        $this->assertNotNull($record->delivered_payload);

        $product->update(['price' => 18.08]);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());
        $record->refresh();

        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertNull($record->delivered_payload);
        $this->assertNull($record->delivered_hash);
        $this->assertNull($record->delivered_action);

        // The desired state survives, and the plan is now a full delivery.
        $this->assertNotNull($record->payload);
        $this->assertSame(DeliveryType::Full, $this->ledger()->plan($product->fresh())->type);
    }

    public function test_a_missing_target_is_not_a_failure_or_a_conflict(): void
    {
        $product = $this->product();
        $record = $this->baseline($product, [
            'ok' => false, 'applied' => false, 'full_sync_required' => true, 'error' => 'gone',
        ], 409);

        $product->update(['price' => 18.08]);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());
        $record->refresh();

        $this->assertNotSame(SyncStatus::Failed, $record->status);
        $this->assertNotSame(SyncStatus::Conflict, $record->status);
        $this->assertNull($record->conflict_details);
    }

    public function test_the_recovery_full_delivery_then_succeeds(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 1], 200)
            ->push(['ok' => false, 'applied' => false, 'full_sync_required' => true, 'error' => 'gone'], 409)
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 2], 200),
        ]);

        $product = $this->product();
        $record = $this->ledger()->reconcile($product);
        $this->deliver($product);

        $product->update(['price' => 18.08]);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());
        $this->assertSame(SyncStatus::Pending, $record->refresh()->status);

        $this->deliver($product->fresh());
        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame('full', $this->sentBody()['mode']);
    }

    /**
     * A 409 that is neither contract is still an ordinary failure.
     */
    public function test_a_bare_409_remains_a_failure(): void
    {
        $product = $this->product();
        $record = $this->baseline($product, ['error' => 'Conflict'], 409);

        $product->update(['price' => 18.08]);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());

        $this->assertSame(SyncStatus::Failed, $record->refresh()->status);
    }
}
