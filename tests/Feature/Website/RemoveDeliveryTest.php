<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Jobs\WebsiteDeliveryFailed;
use App\Models\Product;
use App\Sync\Payload\DeliveryType;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivering Laravel's decision that a product should leave the website.
 */
class RemoveDeliveryTest extends TestCase
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
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'sku' => 'V2RM-A',
            'name' => 'Remove test A',
            'type' => 'Inventory',
            'price' => 9.5,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_payload' => [],
        ], $attributes));
    }

    private function deliver(Product $product): void
    {
        (new DeliverProductToWebsite($product->bc_id))
            ->handle($this->ledger(), app(WebsiteClient::class));
    }

    /**
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

    // ------------------------------------------------------------- envelope

    public function test_an_excluded_product_sends_a_remove_envelope(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            ['ok' => true, 'applied' => true, 'removed' => true, 'wp_id' => 116068], 200,
        )]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $this->ledger()->reconcile($product);
        $this->deliver($product);

        $body = $this->sentBody();

        $this->assertSame('remove', $body['action']);
        $this->assertSame($product->bc_id, $body['bc_id']);
        $this->assertSame(['not_finished_goods'], $body['reasons']);
        $this->assertArrayNotHasKey('payload', $body);
        $this->assertArrayNotHasKey('changes', $body);
    }

    public function test_every_exclusion_reason_travels_with_the_removal(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true, 'removed' => true], 200)]);

        $product = $this->product(['gppg' => 'RETIRED', 'price' => 0]);
        $this->ledger()->reconcile($product);
        $this->deliver($product);

        $this->assertSame(['no_price', 'not_finished_goods'], $this->sentBody()['reasons']);
    }

    // --------------------------------------------------------------- ledger

    public function test_a_successful_remove_marks_the_ledger_synced(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            ['ok' => true, 'applied' => true, 'removed' => true, 'wp_id' => 116068], 200,
        )]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $record = $this->ledger()->reconcile($product);
        $this->deliver($product);

        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Remove, $record->delivered_action);
        $this->assertSame($record->payload_hash, $record->delivered_hash);
        $this->assertSame(['not_finished_goods'], $record->delivered_payload['reasons']);
        $this->assertNotNull($record->synced_at);
    }

    /**
     * Removing something already absent has achieved what was asked, so the
     * website is in the desired state and the removal counts as delivered.
     */
    public function test_an_already_absent_removal_is_still_synced(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            ['ok' => true, 'applied' => true, 'removed' => false], 200,
        )]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $record = $this->ledger()->reconcile($product);
        $this->deliver($product);

        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Remove, $record->delivered_action);
    }

    public function test_a_repeated_remove_opens_no_further_work(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            ['ok' => true, 'applied' => true, 'removed' => true, 'wp_id' => 116068], 200,
        )]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $this->ledger()->reconcile($product);
        $this->deliver($product);

        // Re-importing the same excluded product must not queue another removal.
        $this->assertNull($this->ledger()->reconcile($product->fresh()));
        $this->assertSame(DeliveryType::None, $this->ledger()->plan($product->fresh())->type);
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------- return trip

    /**
     * The removed product was deleted outright, so a product coming back has
     * nothing on the website to apply a partial to and needs the whole payload.
     */
    public function test_a_product_becoming_eligible_again_plans_a_full_not_a_partial(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true, 'removed' => true], 200)]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $record = $this->ledger()->reconcile($product);
        $this->deliver($product);

        $this->assertSame(WebsiteAction::Remove, $record->refresh()->delivered_action);

        $product->update(['gppg' => 'FINISHED GOODS']);
        $this->ledger()->reconcile($product->fresh());

        $plan = $this->ledger()->plan($product->fresh());

        $this->assertSame(WebsiteAction::Upsert, $plan->action);
        $this->assertSame(DeliveryType::Full, $plan->type);
        $this->assertNotSame(DeliveryType::Partial, $plan->type);
    }

    public function test_the_returning_full_carries_the_complete_payload(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['ok' => true, 'applied' => true, 'removed' => true, 'wp_id' => 116068], 200)
            // A new WordPress id: the old product was deleted, not kept aside.
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 116070, 'created' => true], 200),
        ]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $record = $this->ledger()->reconcile($product);
        $this->deliver($product);

        $product->update(['gppg' => 'FINISHED GOODS']);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());

        $body = $this->sentBody();
        $record->refresh();

        $this->assertSame('full', $body['mode']);
        $this->assertSame('V2RM-A', $body['payload']['sku']);
        $this->assertArrayHasKey('group_prices', $body['payload']);
        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->delivered_action);
    }

    /**
     * The whole lifecycle in one place: created, deleted, and created again.
     *
     * The returning product gets a new WordPress id, which is expected: the
     * previous one was permanently deleted, and Laravel holds the state needed
     * to build a fresh product from nothing.
     */
    public function test_the_full_remove_full_lifecycle(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 116068, 'created' => true], 200)
            ->push(['ok' => true, 'applied' => true, 'removed' => true, 'wp_id' => 116068], 200)
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 116070, 'created' => true], 200),
        ]);

        $product = $this->product();
        $record = $this->ledger()->reconcile($product);

        $this->deliver($product);
        $record->refresh();
        $this->assertSame(WebsiteAction::Upsert, $record->delivered_action);
        $this->assertSame(SyncStatus::Synced, $record->status);

        $product->update(['gppg' => 'RETIRED']);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());
        $record->refresh();
        $this->assertSame(WebsiteAction::Remove, $record->delivered_action);
        $this->assertSame(SyncStatus::Synced, $record->status);

        $product->update(['gppg' => 'FINISHED GOODS']);
        $this->ledger()->reconcile($product->fresh());
        $this->assertSame(DeliveryType::Full, $this->ledger()->plan($product->fresh())->type);

        $this->deliver($product->fresh());
        $record->refresh();
        $this->assertSame(WebsiteAction::Upsert, $record->delivered_action);
        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(DeliveryType::None, $this->ledger()->plan($product->fresh())->type);

        // A different WordPress product, because the first was deleted.
        $this->assertSame('full', $this->sentBody()['mode']);
    }

    /**
     * The website reports a different product id after a return trip, because
     * the original was deleted rather than set aside. Laravel does not care
     * which id the website uses — it identifies products by Business Central id
     * — but the change must not disturb the ledger.
     */
    public function test_a_returning_product_gets_a_new_website_id(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 116068, 'created' => true], 200)
            ->push(['ok' => true, 'applied' => true, 'removed' => true, 'wp_id' => 116068], 200)
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 116070, 'created' => true], 200),
        ]);

        $product = $this->product();
        $record = $this->ledger()->reconcile($product);
        $this->deliver($product);

        $product->update(['gppg' => 'RETIRED']);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());

        $product->update(['gppg' => 'FINISHED GOODS']);
        $this->ledger()->reconcile($product->fresh());
        $this->deliver($product->fresh());

        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->delivered_action);
        // The ledger keys on the Business Central id, which never moved.
        $this->assertSame($product->bc_id, $record->bc_id);
    }

    /**
     * Removal is by Business Central id alone. A product sharing a SKU is a
     * different product and must not be affected by this one's removal.
     */
    public function test_removal_identifies_the_product_by_business_central_id_only(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true, 'removed' => true], 200)]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $this->ledger()->reconcile($product);
        $this->deliver($product);

        $body = $this->sentBody();

        $this->assertSame($product->bc_id, $body['bc_id']);
        // The SKU travels for diagnostics; the receiver never identifies by it.
        $this->assertArrayNotHasKey('gtin', $body);
    }

    // ------------------------------------------------------------ failures

    public function test_a_failed_remove_is_not_marked_delivered(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => false, 'error' => 'boom'], 500)]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $record = $this->ledger()->reconcile($product);

        try {
            $this->deliver($product);
        } catch (WebsiteDeliveryFailed) {
            // Transient failures throw so the queue retries.
        }

        $record->refresh();

        $this->assertSame(SyncStatus::Failed, $record->status);
        $this->assertNull($record->delivered_action);
    }

    /**
     * A removal that reports applied=false has not happened.
     */
    public function test_a_remove_not_applied_leaves_the_record_pending(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'accepted' => true, 'applied' => false], 200)]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $record = $this->ledger()->reconcile($product);
        $this->deliver($product);

        $record->refresh();

        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertNull($record->delivered_action);
    }

    // ------------------------------------------------------------------- UI

    public function test_the_detail_page_shows_the_removal_and_its_reasons(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true, 'removed' => true], 200)]);

        $product = $this->product(['gppg' => 'RETIRED']);
        $this->ledger()->reconcile($product);
        $this->deliver($product);

        $this->get(route('products.show', $product->fresh()))
            ->assertOk()
            ->assertSee('Desired website state')
            ->assertSee('Remove')
            ->assertSee('Product is not classified as FINISHED GOODS (RETIRED)')
            ->assertSee('Delivery status')
            ->assertSee('Synced');
    }
}
