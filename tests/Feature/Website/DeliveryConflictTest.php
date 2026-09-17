<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\ProductFilter;
use App\Products\WebsiteEligibility;
use App\Sync\DeliveryStatus;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A delivery blocked by an identity collision on the website.
 *
 * Conflict is deliberately a third thing. Excluded means Laravel decided the
 * product does not belong on the website; Failed means something went wrong;
 * Conflict means Laravel wants it there and the website already has that SKU or
 * GTIN on a different product.
 */
class DeliveryConflictTest extends TestCase
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
            'sku' => 'SF180',
            'type' => 'Inventory',
            'price' => 10,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_payload' => [],
        ], $attributes));
    }

    /**
     * The 409 body the WordPress endpoint returns.
     *
     * @param  array<int, array<string, mixed>>  $conflicts
     * @return array<string, mixed>
     */
    private function conflictBody(array $conflicts): array
    {
        return ['ok' => false, 'conflict' => true, 'applied' => false, 'conflicts' => $conflicts];
    }

    /**
     * @return array<string, mixed>
     */
    private function skuConflict(string $sku = 'SF180', int $wpId = 1234): array
    {
        return [
            'code' => 'sku_conflict',
            'field' => 'sku',
            'value' => $sku,
            'message' => 'SKU already belongs to another WordPress product',
            'existing_wp_id' => $wpId,
            'existing_bc_id' => '7c5278e6-f01d-f111-8340-7ced8d344639',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gtinConflict(string $gtin = '9419423000794', int $wpId = 5678): array
    {
        return [
            'code' => 'gtin_conflict',
            'field' => 'gtin',
            'value' => $gtin,
            'message' => 'GTIN already belongs to another WordPress product',
            'existing_wp_id' => $wpId,
            'existing_bc_id' => 'aa1178e6-f01d-f111-8340-7ced8d344639',
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function deliver(array $body, int $status = 409, ?Product $product = null): SyncRecord
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);

        $product ??= $this->product();
        $record = $this->ledger()->reconcile($product) ?? $this->ledger()->find($product);

        (new DeliverProductToWebsite($product->bc_id))
            ->handle($this->ledger(), app(WebsiteClient::class));

        return $record->refresh();
    }

    // ------------------------------------------------------- ledger behaviour

    public function test_a_structured_409_becomes_conflict_not_failed(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]));

        $this->assertSame(SyncStatus::Conflict, $record->status);
        $this->assertNotSame(SyncStatus::Failed, $record->status);
    }

    public function test_a_conflict_updates_no_delivered_fields(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]));

        $this->assertNull($record->delivered_action);
        $this->assertNull($record->delivered_hash);
        $this->assertNull($record->delivered_payload);
        $this->assertNull($record->synced_at);
    }

    /**
     * The product is still wanted, so the payload must survive to be sent once
     * the collision is resolved.
     */
    public function test_a_conflict_keeps_the_desired_payload_and_action(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]));

        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame('SF180', $record->payload['sku']);
        $this->assertNotNull($record->payload_hash);
    }

    public function test_the_conflict_details_are_stored_for_inspection(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]));

        $this->assertCount(1, $record->conflict_details);
        $this->assertSame('sku_conflict', $record->conflict_details[0]['code']);
        $this->assertSame(1234, $record->conflict_details[0]['existing_wp_id']);
        $this->assertNotNull($record->conflicted_at);
    }

    public function test_a_readable_summary_is_kept_for_a_human(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]));

        $this->assertStringContainsString('SF180', (string) $record->last_error);
        $this->assertStringContainsString('1234', (string) $record->last_error);
    }

    public function test_both_sku_and_gtin_conflicts_are_reported_together(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict(), $this->gtinConflict()]));

        $this->assertCount(2, $record->conflict_details);
        $this->assertSame(
            ['sku_conflict', 'gtin_conflict'],
            array_column($record->conflict_details, 'code'),
        );
    }

    /**
     * Retrying the same payload would collide identically, so the job must not
     * throw: throwing is what hands the work back to the queue.
     */
    public function test_a_conflict_is_not_retried(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]));

        $this->assertSame(SyncStatus::Conflict, $record->status);
        $this->assertSame(1, $record->attempts);
        Http::assertSentCount(1);
        $this->assertFalse($record->status->isRetryable());
    }

    // --------------------------------------------------- contract strictness

    /**
     * A 409 from a proxy or an unrelated plugin is not a conflict report.
     */
    public function test_a_bare_409_is_a_permanent_failure_not_a_conflict(): void
    {
        $record = $this->deliver(['error' => 'Conflict'], 409);

        $this->assertSame(SyncStatus::Failed, $record->status);
        $this->assertNull($record->conflict_details);
    }

    public function test_a_409_without_the_conflict_flag_is_a_failure(): void
    {
        $record = $this->deliver(['ok' => false, 'conflicts' => [$this->skuConflict()]], 409);

        $this->assertSame(SyncStatus::Failed, $record->status);
    }

    public function test_a_409_with_an_empty_conflicts_list_is_a_failure(): void
    {
        $record = $this->deliver(['ok' => false, 'conflict' => true, 'conflicts' => []], 409);

        $this->assertSame(SyncStatus::Failed, $record->status);
    }

    public function test_a_conflict_shaped_body_on_another_status_is_not_a_conflict(): void
    {
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]), 422);

        $this->assertSame(SyncStatus::Failed, $record->status);
    }

    // ------------------------------------------------------------ recovery

    /**
     * Stale conflict information must not outlive the payload it described.
     */
    public function test_a_new_desired_state_clears_the_conflict_and_returns_to_pending(): void
    {
        $product = $this->product();
        $record = $this->deliver($this->conflictBody([$this->skuConflict()]), product: $product);

        $this->assertSame(SyncStatus::Conflict, $record->status);

        $product->update(['sku' => 'SF180-NEW']);
        $this->ledger()->reconcile($product->fresh());

        $record->refresh();

        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertNull($record->conflict_details);
        $this->assertNull($record->conflicted_at);
    }

    public function test_a_resolved_conflict_can_then_be_delivered(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->conflictBody([$this->skuConflict()]), 409)
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 9], 200),
        ]);

        $product = $this->product();
        $record = $this->ledger()->reconcile($product);
        $client = app(WebsiteClient::class);

        (new DeliverProductToWebsite($product->bc_id))->handle($this->ledger(), $client);
        $this->assertSame(SyncStatus::Conflict, $record->refresh()->status);

        $product->update(['sku' => 'SF180-NEW']);
        $this->ledger()->reconcile($product->fresh());

        (new DeliverProductToWebsite($product->bc_id))->handle($this->ledger(), $client);

        $record = $this->ledger()->find($product->fresh());

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertNull($record->conflict_details);
    }

    // ------------------------------------------------- three distinct concepts

    /**
     * Excluded, Conflict and Failed must never collapse into one another.
     */
    public function test_excluded_conflict_and_failed_stay_distinct(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->conflictBody([$this->skuConflict()]), 409)
            ->push(['error' => 'boom'], 422),
        ]);

        $client = app(WebsiteClient::class);

        // Excluded: Laravel says it does not belong on the website. No delivery
        // is attempted for the comparison, because exclusion is decided here.
        $excluded = $this->product(['sku' => 'GONE1', 'gppg' => 'RETIRED']);
        $excludedRecord = $this->ledger()->reconcile($excluded);

        $this->assertSame(WebsiteAction::Remove, $excludedRecord->action);
        $this->assertSame(SyncStatus::Pending, $excludedRecord->status);
        $this->assertNull($excludedRecord->conflict_details);

        // Conflict: wanted, but the website cannot take it as addressed.
        $clash = $this->product(['sku' => 'CLASH1']);
        $conflicted = $this->ledger()->reconcile($clash);
        (new DeliverProductToWebsite($clash->bc_id))->handle($this->ledger(), $client);
        $conflicted->refresh();

        $this->assertSame(SyncStatus::Conflict, $conflicted->status);
        $this->assertSame(WebsiteAction::Upsert, $conflicted->action);

        // Failed: something went wrong.
        $broke = $this->product(['sku' => 'BROKE1']);
        $failed = $this->ledger()->reconcile($broke);
        (new DeliverProductToWebsite($broke->bc_id))->handle($this->ledger(), $client);
        $failed->refresh();

        $this->assertSame(SyncStatus::Failed, $failed->status);
        $this->assertNull($failed->conflict_details);

        $this->assertNotSame($conflicted->status, $failed->status);
        $this->assertNotSame($conflicted->status, $excludedRecord->status);
    }

    public function test_the_delivery_status_reports_conflict_separately(): void
    {
        $product = $this->product();
        $this->deliver($this->conflictBody([$this->skuConflict()]), product: $product);

        $status = DeliveryStatus::for(
            $product->fresh(),
            app(WebsiteEligibility::class),
            $this->ledger()->find($product),
        );

        $this->assertSame(DeliveryStatus::Conflict, $status);
        $this->assertTrue($status->isProblem());
        $this->assertNotSame(DeliveryStatus::NotApplicable, $status);
    }

    // ------------------------------------------------------------------- UI

    public function test_the_dashboard_counts_conflicts_separately(): void
    {
        $this->deliver($this->conflictBody([$this->skuConflict()]), product: $this->product(['sku' => 'CLASH1']));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Conflicts')
            ->assertSeeInOrder(['data-stat="conflicts"', '1'], false);
    }

    public function test_the_conflict_card_links_to_the_conflict_filter(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('products.index', [ProductFilter::PARAM_SYNC => 'conflict']), false);
    }

    public function test_the_conflict_filter_lists_only_conflicted_products(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->conflictBody([$this->skuConflict()]), 409)
            ->push(['ok' => true, 'applied' => true], 200),
        ]);

        $client = app(WebsiteClient::class);

        foreach (['CLASH1', 'FINE1'] as $sku) {
            $p = $this->product(['sku' => $sku]);
            $this->ledger()->reconcile($p);
            (new DeliverProductToWebsite($p->bc_id))->handle($this->ledger(), $client);
        }

        $response = $this->get(route('products.index', [ProductFilter::PARAM_SYNC => 'conflict']))->assertOk();

        $this->assertSame(['CLASH1'], $response->viewData('products')->pluck('sku')->all());
    }

    public function test_the_detail_page_explains_the_conflict(): void
    {
        $product = $this->product();
        $this->deliver($this->conflictBody([$this->skuConflict()]), product: $product);

        $this->get(route('products.show', $product->fresh()))
            ->assertOk()
            ->assertSee('Why can&rsquo;t this product sync?', false)
            ->assertSee('SF180')
            ->assertSee('#1234', false)
            ->assertSee('Laravel BC ID:')
            ->assertSee('Existing WordPress BC ID:')
            ->assertSee('7c5278e6-f01d-f111-8340-7ced8d344639')
            ->assertSee('Conflict');
    }

    public function test_the_detail_page_explains_a_gtin_conflict_differently(): void
    {
        $product = $this->product();
        $this->deliver($this->conflictBody([$this->gtinConflict()]), product: $product);

        $this->get(route('products.show', $product->fresh()))
            ->assertOk()
            ->assertSee('GTIN')
            ->assertSee('9419423000794');
    }

    public function test_the_detail_page_lists_every_conflict(): void
    {
        $product = $this->product();
        $this->deliver($this->conflictBody([$this->skuConflict(), $this->gtinConflict()]), product: $product);

        $this->get(route('products.show', $product->fresh()))
            ->assertOk()
            ->assertSee('SF180')
            ->assertSee('9419423000794')
            ->assertSee('#1234', false)
            ->assertSee('#5678', false);
    }

    public function test_the_dashboard_shows_the_configured_environments_without_secrets(): void
    {
        config()->set('services.bc.instance', 'Sandbox_UAT_Brooklands');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Environment')
            ->assertSee('Sandbox_UAT_Brooklands')
            ->assertSee('website.test')
            ->assertDontSee('shared-secret-for-tests');
    }
}
