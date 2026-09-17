<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Jobs\ImportBcProduct;
use App\Jobs\WebsiteDeliveryFailed;
use App\Models\Product;
use App\Models\ProductQuantity;
use App\Models\SyncRecord;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use App\Website\WebsiteRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Delivering a product's desired website state.
 *
 * No real request is ever made: the destination is a fake URL and every
 * response is stubbed.
 */
class DeliverProductToWebsiteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = 'https://website.test/sync';

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

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * A stored, eligible product with a quantity row.
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

        return $product->fresh();
    }

    private function queueDelivery(Product $product): SyncRecord
    {
        return $this->ledger()->reconcile($product) ?? $this->ledger()->find($product);
    }

    private function runDelivery(Product $product): void
    {
        (new DeliverProductToWebsite($product->bc_id))
            ->handle($this->ledger(), app(WebsiteClient::class));
    }

    /**
     * Deliver the current state so later changes diff against it.
     */
    private function deliverBaseline(Product $product): void
    {
        $this->accepted();
        $this->queueDelivery($product);
        $this->runDelivery($product);
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * The decoded body of the last request sent.
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

    // -------------------------------------------------------------- full sync

    public function test_a_first_delivery_sends_the_full_payload(): void
    {
        $this->accepted();
        $product = $this->product();
        $this->queueDelivery($product);

        $this->runDelivery($product);

        $body = $this->sentBody();

        $this->assertSame('upsert', $body['action']);
        $this->assertSame(WebsiteRequest::MODE_FULL, $body['mode']);
        $this->assertSame($product->bc_id, $body['bc_id']);
        $this->assertSame('POLY1', $body['payload']['sku']);
        $this->assertArrayNotHasKey('changes', $body);
    }

    public function test_a_successful_delivery_moves_the_record_to_synced(): void
    {
        $this->accepted();
        $product = $this->product();
        $record = $this->queueDelivery($product);

        $this->assertSame(SyncStatus::Pending, $record->status);

        $this->runDelivery($product);
        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertNotNull($record->synced_at);
        $this->assertNull($record->last_error);
        $this->assertSame($record->payload, $record->delivered_payload);
        $this->assertSame($record->payload_hash, $record->delivered_hash);
        $this->assertSame(WebsiteAction::Upsert, $record->delivered_action);
    }

    // ----------------------------------------------------------------- partial

    public function test_a_price_only_change_sends_a_partial_with_only_the_price(): void
    {
        $product = $this->product();
        $this->deliverBaseline($product);

        $product->update(['price' => 18.08]);
        $this->queueDelivery($product->fresh());
        $this->runDelivery($product->fresh());

        $body = $this->sentBody();

        $this->assertSame(WebsiteRequest::MODE_PARTIAL, $body['mode']);
        $this->assertSame(['price' => 18.08], $body['changes']);
        $this->assertArrayNotHasKey('payload', $body);
    }

    /**
     * One Business Central change can move several website fields at once.
     */
    public function test_a_stock_change_sends_every_affected_website_field(): void
    {
        $product = $this->product(quantity: ['inventory' => 20]);
        $this->deliverBaseline($product);

        $product->quantity->update(['inventory' => 0]);
        $this->queueDelivery($product->fresh());
        $this->runDelivery($product->fresh());

        $changes = $this->sentBody()['changes'];

        $this->assertSame(['stock_status', 'website_quantity'], array_keys($changes));
        $this->assertSame('outofstock', $changes['stock_status']);
        $this->assertEquals(0, $changes['website_quantity']);
    }

    /**
     * The website holds the whole state after a partial, so the next diff must
     * be computed against all of it rather than the fragment sent.
     */
    public function test_a_successful_partial_stores_the_complete_payload_as_delivered(): void
    {
        $product = $this->product();
        $this->deliverBaseline($product);

        $product->update(['price' => 18.08]);
        $record = $this->queueDelivery($product->fresh());
        $this->runDelivery($product->fresh());

        $record->refresh();

        $this->assertSame(['price' => 18.08], $this->sentBody()['changes']);
        // Everything, not just the price.
        $this->assertSame($record->payload, $record->delivered_payload);
        $this->assertSame('POLY1', $record->delivered_payload['sku']);
        $this->assertSame(18.08, $record->delivered_payload['price']);
    }

    // ------------------------------------------------------------------ remove

    public function test_an_excluded_product_sends_a_remove(): void
    {
        $this->accepted();
        $product = $this->product(['gppg' => 'RETIRED']);
        $this->queueDelivery($product);

        $this->runDelivery($product);

        $body = $this->sentBody();

        $this->assertSame('remove', $body['action']);
        $this->assertSame($product->bc_id, $body['bc_id']);
        $this->assertSame(['not_finished_goods'], $body['reasons']);
        $this->assertArrayNotHasKey('payload', $body);
        $this->assertArrayNotHasKey('changes', $body);
    }

    public function test_a_successful_remove_stores_the_delivered_action(): void
    {
        $this->accepted();
        $product = $this->product(['gppg' => 'RETIRED']);
        $record = $this->queueDelivery($product);

        $this->runDelivery($product);
        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Remove, $record->delivered_action);
        $this->assertSame(['not_finished_goods'], $record->delivered_payload['reasons']);
    }

    /**
     * Removing something already absent has achieved what was asked, so any 2xx
     * counts as delivered. Laravel never asks whether the product was there.
     */
    public function test_a_remove_is_idempotent_whatever_the_website_already_held(): void
    {
        $product = $this->product(['gppg' => 'RETIRED']);

        foreach ([['applied' => true, 'removed' => true], ['applied' => true, 'removed' => false, 'note' => 'already absent']] as $body) {
            Http::fake([self::ENDPOINT => Http::response($body, 200)]);

            $record = $this->queueDelivery($product);
            $this->ledger()->releaseToPending($record);
            $this->runDelivery($product);

            $this->assertSame(SyncStatus::Synced, $record->refresh()->status);
        }
    }

    // ---------------------------------------------------------------- failures

    public function test_a_transient_failure_throws_so_the_queue_retries(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'gateway'], 502)]);
        $product = $this->product();
        $record = $this->queueDelivery($product);

        $this->expectException(WebsiteDeliveryFailed::class);

        try {
            $this->runDelivery($product);
        } finally {
            $record->refresh();
            $this->assertSame(SyncStatus::Failed, $record->status);
            $this->assertStringContainsString('502', (string) $record->last_error);
            // The desired state is untouched by a failure.
            $this->assertNull($record->delivered_hash);
        }
    }

    public function test_a_connection_failure_is_treated_as_transient(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $product = $this->product();
        $this->queueDelivery($product);

        $this->expectException(WebsiteDeliveryFailed::class);

        $this->runDelivery($product);
    }

    public function test_a_429_is_treated_as_transient(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'slow down'], 429)]);
        $product = $this->product();
        $this->queueDelivery($product);

        $this->expectException(WebsiteDeliveryFailed::class);

        $this->runDelivery($product);
    }

    public function test_a_deterministic_failure_becomes_failed_without_retrying(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'unknown field'], 422)]);
        $product = $this->product();
        $record = $this->queueDelivery($product);

        $job = new DeliverProductToWebsite($product->bc_id);
        $job->handle($this->ledger(), app(WebsiteClient::class));

        $record->refresh();

        $this->assertSame(SyncStatus::Failed, $record->status);
        $this->assertStringContainsString('unknown field', (string) $record->last_error);
        $this->assertNotNull($record->failed_at);
    }

    public function test_a_missing_destination_fails_rather_than_posting_nowhere(): void
    {
        config()->set('services.website.url', '');
        $product = $this->product();
        $record = $this->queueDelivery($product);

        (new DeliverProductToWebsite($product->bc_id))
            ->handle($this->ledger(), app(WebsiteClient::class));

        $this->assertSame(SyncStatus::Failed, $record->refresh()->status);
        $this->assertStringContainsString('services.website.url', (string) $record->last_error);
    }

    // ------------------------------------------------------------ stale safety

    /**
     * The job rebuilds from current state, so a job queued before a change
     * delivers the newer state rather than the one it was queued for.
     */
    public function test_the_job_rebuilds_the_payload_when_it_runs(): void
    {
        $this->accepted();
        $product = $this->product();
        $this->queueDelivery($product);

        // The price moves after the job was queued but before it runs.
        $product->update(['price' => 99]);

        $this->runDelivery($product->fresh());

        $body = $this->sentBody();

        $this->assertEquals(99, $body['payload']['price'] ?? $body['changes']['price'] ?? null);
    }

    /**
     * If the product changes while the request is in flight, the newer state
     * must not be recorded as delivered: it was never sent.
     */
    public function test_a_product_changing_mid_delivery_is_not_marked_synced(): void
    {
        $product = $this->product();
        $record = $this->queueDelivery($product);
        $sentHash = $record->payload_hash;

        // The website accepts the request, but the product moves on first.
        Http::fake(function () use ($product) {
            $product->update(['price' => 77]);
            $this->ledger()->reconcile($product->fresh());

            return Http::response(['ok' => true, 'applied' => true], 200);
        });

        $this->runDelivery($product);
        $record->refresh();

        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertNull($record->delivered_hash);
        $this->assertNotSame($sentHash, $record->payload_hash);
    }

    public function test_a_stale_delivery_leaves_the_newer_state_deliverable(): void
    {
        $product = $this->product();
        $record = $this->queueDelivery($product);

        Http::fake(function () use ($product) {
            $product->update(['price' => 77]);
            $this->ledger()->reconcile($product->fresh());

            return Http::response(['ok' => true, 'applied' => true], 200);
        });

        $this->runDelivery($product);

        // A second delivery now succeeds against the newer state.
        $this->accepted();
        $this->runDelivery($product->fresh());
        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertEquals(77, $record->delivered_payload['price']);
    }

    public function test_a_ledger_row_without_a_product_fails_rather_than_guessing(): void
    {
        $product = $this->product();
        $record = $this->queueDelivery($product);
        $product->delete();

        (new DeliverProductToWebsite($record->bc_id))
            ->handle($this->ledger(), app(WebsiteClient::class));

        $this->assertSame(SyncStatus::Failed, $record->refresh()->status);
    }

    public function test_nothing_is_sent_when_the_website_already_matches(): void
    {
        $product = $this->product();
        $this->deliverBaseline($product);

        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);

        $this->runDelivery($product->fresh());

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Synced, $this->ledger()->find($product)->status);
    }

    // -------------------------------------------------------------- dispatching

    public function test_an_import_dispatches_one_delivery_for_genuinely_new_work(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        ImportBcProduct::dispatchSync($this->row());

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    /**
     * An unchanged re-import must not queue another delivery.
     */
    public function test_an_unchanged_reimport_dispatches_no_further_delivery(): void
    {
        Queue::fake([DeliverProductToWebsite::class]);

        ImportBcProduct::dispatchSync($this->row());
        ImportBcProduct::dispatchSync($this->row());
        ImportBcProduct::dispatchSync($this->row());

        Queue::assertPushed(DeliverProductToWebsite::class, 1);
    }

    // ----------------------------------------------------------------- overlap

    public function test_only_one_delivery_runs_per_product_at_a_time(): void
    {
        $this->accepted();
        $product = $this->product();
        $this->queueDelivery($product);

        $job = new DeliverProductToWebsite($product->bc_id);
        $middleware = $job->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);

        // Holding the lock means a second job is dropped rather than duplicating
        // the request the holder is already making.
        $lock = Cache::lock($middleware->getLockKey($job), 180);
        $this->assertTrue($lock->get());

        $ran = false;
        $middleware->handle($job, function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran, 'a second delivery ran while another held the lock');

        $lock->release();
    }

    public function test_the_lock_is_released_so_a_later_delivery_can_run(): void
    {
        $product = $this->product();
        $job = new DeliverProductToWebsite($product->bc_id);
        $middleware = $job->middleware()[0];

        $ran = 0;
        $middleware->handle($job, function () use (&$ran): void {
            $ran++;
        });
        $middleware->handle($job, function () use (&$ran): void {
            $ran++;
        });

        $this->assertSame(2, $ran);
    }

    public function test_the_lock_key_is_per_product_and_channel(): void
    {
        $a = new DeliverProductToWebsite('bc-a');
        $b = new DeliverProductToWebsite('bc-b');

        $this->assertNotSame(
            $a->middleware()[0]->getLockKey($a),
            $b->middleware()[0]->getLockKey($b),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'number' => 'POLY1',
            'displayName' => 'Poly Bin',
            'type' => 'Inventory',
            'unitPrice' => 10,
            'gppg' => 'FINISHED GOODS',
            'itemCategoryId' => '',
            'blocked' => false,
            'lastModifiedDateTime' => '2026-03-12T11:06:22.503Z',
        ];
    }
}
