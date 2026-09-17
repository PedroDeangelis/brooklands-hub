<?php

namespace Tests\Feature\Sync;

use App\BusinessCentral\Import\ProductImporter;
use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Jobs\ImportBcProduct;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\WebsiteEligibility;
use App\Sync\DeliveryStatus;
use App\Sync\DesiredWebsiteState;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The desired website state, and how it moves as a product's eligibility changes.
 *
 * The distinction under test throughout: what the website should hold is one
 * question, whether it already holds it is another. A product that stops
 * qualifying acquires a new desired state that still has to be delivered.
 */
class DesiredWebsiteStateTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about delivery intent, not delivery itself.
        Queue::fake([DeliverProductToWebsite::class]);
    }

    private function ledger(): SyncLedger
    {
        return app(SyncLedger::class);
    }

    /**
     * A raw Business Central row that passes every website rule.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'number' => 'POLY1',
            'displayName' => 'Tropical Fish 1 Poly Bin with Lid',
            'type' => 'Inventory',
            'unitPrice' => 2,
            'gppg' => 'FINISHED GOODS',
            'itemCategoryId' => '',
            'blocked' => false,
            'lastModifiedDateTime' => '2026-03-12T11:06:22.503Z',
        ], $overrides);
    }

    /**
     * Import a row the way the queue does, returning the ledger row afterwards.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function import(array $overrides = []): ?SyncRecord
    {
        ImportBcProduct::dispatchSync($this->row($overrides));

        return SyncRecord::query()->forChannel(SyncLedger::CHANNEL_ITEMS)->first();
    }

    private function product(): Product
    {
        return Product::query()->sole();
    }

    /**
     * Pretend the pending row was delivered successfully.
     */
    private function deliver(SyncRecord $record): SyncRecord
    {
        $this->ledger()->markSynced($record);

        return $record->refresh();
    }

    // ---------------------------------------------------------------- basics

    public function test_an_eligible_product_wants_an_upsert(): void
    {
        $state = DesiredWebsiteState::from(
            app(WebsiteEligibility::class)->for(Product::factory()->make([
                'blocked' => false, 'price' => 10, 'type' => 'Inventory',
                'gppg' => 'FINISHED GOODS', 'item_category_id' => '',
            ])),
        );

        $this->assertSame(WebsiteAction::Upsert, $state->action);
        $this->assertTrue($state->isUpsert());
        $this->assertSame([], $state->reasons);
        $this->assertNull($state->reason());
    }

    public function test_an_excluded_product_wants_a_removal_and_says_why(): void
    {
        $state = DesiredWebsiteState::from(
            app(WebsiteEligibility::class)->for(Product::factory()->make([
                'blocked' => false, 'price' => 10, 'type' => 'Inventory',
                'gppg' => 'RETIRED', 'item_category_id' => '',
            ])),
        );

        $this->assertSame(WebsiteAction::Remove, $state->action);
        $this->assertTrue($state->isRemoval());
        $this->assertSame('Product is not classified as FINISHED GOODS (RETIRED)', $state->reason());
    }

    /**
     * An excluded product is never simply "nothing to do": it may already be on
     * the website from when it qualified.
     */
    public function test_an_excluded_product_still_has_a_desired_action(): void
    {
        $this->import(['gppg' => 'RETIRED']);

        $this->assertSame(WebsiteAction::Remove, $this->ledger()->desiredAction($this->product()));
    }

    // ------------------------------------------------------------ transitions

    public function test_eligible_to_excluded_produces_a_pending_remove(): void
    {
        $record = $this->import();
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->deliver($record);

        // The item is reclassified in Business Central; no other field moves.
        $record = $this->import(['gppg' => 'RETIRED']);

        $this->assertSame(WebsiteAction::Remove, $record->action);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertFalse($record->isDelivered());
        $this->assertSame(['website_eligibility'], $record->changed_fields);
    }

    public function test_excluded_to_eligible_produces_a_pending_upsert(): void
    {
        $record = $this->import(['gppg' => 'RETIRED']);
        $this->assertSame(WebsiteAction::Remove, $record->action);
        $this->deliver($record);

        $record = $this->import();

        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertFalse($record->isDelivered());
    }

    /**
     * Re-importing an unchanged excluded product must not keep re-queueing the
     * same removal: the ledger already wants exactly that.
     */
    public function test_an_unchanged_excluded_product_does_not_repeatedly_create_work(): void
    {
        $record = $this->deliver($this->import(['gppg' => 'RETIRED']));
        $syncedAt = $record->synced_at;

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull(
                $this->ledger()->reconcile($this->product()),
                'a re-import of an unchanged excluded product opened new work',
            );
        }

        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Remove, $record->action);
        $this->assertTrue($record->isDelivered());
        $this->assertEquals($syncedAt, $record->synced_at);
        $this->assertSame(1, SyncRecord::query()->count());
    }

    public function test_an_unchanged_eligible_product_does_not_repeatedly_create_work(): void
    {
        $record = $this->deliver($this->import());
        $syncedAt = $record->synced_at;

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull(
                $this->ledger()->reconcile($this->product()),
                'a re-import of an unchanged eligible product opened new work',
            );
        }

        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertTrue($record->isDelivered());
        $this->assertEquals($syncedAt, $record->synced_at);
        $this->assertSame(1, SyncRecord::query()->count());
    }

    /**
     * Running the whole queued import repeatedly must be equally quiet: this is
     * what a scheduled full re-import does every night.
     */
    public function test_repeatedly_importing_an_unchanged_product_leaves_the_ledger_alone(): void
    {
        $record = $this->deliver($this->import(['gppg' => 'RETIRED']));
        $before = $record->only(['status', 'action', 'payload_hash', 'delivered_hash', 'synced_at']);

        $this->import(['gppg' => 'RETIRED']);
        $this->import(['gppg' => 'RETIRED']);

        $record->refresh();

        $this->assertEquals($before, $record->only(array_keys($before)));
        $this->assertSame(1, SyncRecord::query()->count());
    }

    /**
     * A change that is neither an eligibility flip nor a no-op still re-opens
     * work, so the guard does not swallow ordinary updates.
     */
    public function test_an_ordinary_field_change_still_creates_work(): void
    {
        $this->deliver($this->import());

        $record = $this->import(['unitPrice' => 99]);

        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertContains('price', $record->changed_fields);
    }

    // --------------------------------------------------- desired vs delivered

    /**
     * The distinction this whole step exists for.
     */
    public function test_a_pending_removal_is_reported_as_remove_and_pending(): void
    {
        $this->deliver($this->import());
        $record = $this->import(['gppg' => 'RETIRED']);

        $product = $this->product();
        $desired = $this->ledger()->desiredState($product);
        $delivery = DeliveryStatus::for($product, app(WebsiteEligibility::class), $record);

        $this->assertSame('Remove', $desired->label());
        $this->assertSame('Product is not classified as FINISHED GOODS (RETIRED)', $desired->reason());
        $this->assertSame('Pending', $delivery->label());
        $this->assertTrue($delivery->needsDelivery());
    }

    public function test_a_delivered_upsert_is_reported_as_upsert_and_synced(): void
    {
        $record = $this->deliver($this->import());

        $product = $this->product();

        $this->assertSame('Upsert', $this->ledger()->desiredState($product)->label());
        $this->assertSame(
            'Synced',
            DeliveryStatus::for($product, app(WebsiteEligibility::class), $record)->label(),
        );
    }

    // --------------------------------------------------------------------- UI

    public function test_the_detail_page_shows_the_desired_state_and_delivery_status(): void
    {
        $this->deliver($this->import());
        $this->import(['gppg' => 'RETIRED']);

        $this->get(route('products.show', $this->product()))
            ->assertOk()
            ->assertSee('Desired website state')
            ->assertSee('Remove')
            ->assertSee('Product is not classified as FINISHED GOODS (RETIRED)')
            ->assertSee('Delivery status')
            ->assertSee('Pending');
    }

    public function test_the_detail_page_shows_an_upsert_that_is_already_synced(): void
    {
        $this->deliver($this->import());

        $this->get(route('products.show', $this->product()))
            ->assertOk()
            ->assertSee('Desired website state')
            ->assertSee('Upsert')
            ->assertSee('Delivery status')
            ->assertSee('Synced');
    }

    /**
     * A first import of an excluded product records the removal intent, so the
     * work is visible even before any delivery exists.
     */
    public function test_a_first_import_of_an_excluded_product_records_a_removal(): void
    {
        $record = $this->import(['unitPrice' => 0]);

        $this->assertNotNull($record);
        $this->assertSame(WebsiteAction::Remove, $record->action);
        $this->assertSame(SyncStatus::Pending, $record->status);
    }

    public function test_the_importer_is_unaffected_by_the_ledger_decision(): void
    {
        app(ProductImporter::class)->import($this->row(['gppg' => 'RETIRED']));

        $this->assertDatabaseHas('products', ['sku' => 'POLY1', 'gppg' => 'RETIRED']);
    }
}
