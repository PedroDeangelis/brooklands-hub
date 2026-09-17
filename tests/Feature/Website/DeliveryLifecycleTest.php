<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The delivery lifecycle of one product, start to finish.
 *
 * Written as a narrative rather than as isolated cases: the point is that the
 * states follow one another in order, which no single assertion shows.
 */
class DeliveryLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = 'https://website.test/sync';

    /** @var list<string> */
    private array $observed = [];

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

    private function product(): Product
    {
        return Product::factory()->create([
            'bc_id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'sku' => 'POLY1',
            'name' => 'Poly Bin',
            'type' => 'Inventory',
            'price' => 10,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_payload' => [],
        ]);
    }

    private function ledgerStatus(Product $product): SyncStatus
    {
        return SyncRecord::query()
            ->forChannel(SyncLedger::CHANNEL_ITEMS)
            ->where('bc_id', $product->bc_id)
            ->sole()
            ->status;
    }

    /**
     * Pending → Syncing → Synced.
     */
    public function test_a_product_moves_from_pending_through_syncing_to_synced(): void
    {
        $ledger = app(SyncLedger::class);
        $product = $this->product();

        $record = $ledger->reconcile($product);
        $this->observed[] = $record->status->value;
        $this->assertSame(SyncStatus::Pending, $record->status);

        // Observe the state while the request is in flight: the job has claimed
        // the record, so it reads as syncing until the response comes back.
        Http::fake(function () use ($product) {
            $this->observed[] = $this->ledgerStatus($product)->value;

            return Http::response(['ok' => true, 'applied' => true], 200);
        });

        (new DeliverProductToWebsite($product->bc_id))->handle($ledger, app(WebsiteClient::class));

        $record->refresh();
        $this->observed[] = $record->status->value;

        $this->assertSame(['pending', 'syncing', 'synced'], $this->observed);

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->delivered_action);
        $this->assertSame($record->payload_hash, $record->delivered_hash);
        $this->assertNotNull($record->synced_at);
        $this->assertNull($record->last_error);
        $this->assertSame(1, $record->attempts);
    }

    /**
     * Pending → Syncing → Failed, with the desired state left intact so the
     * delivery can be attempted again.
     */
    public function test_a_failed_delivery_records_the_error_and_keeps_the_desired_state(): void
    {
        $ledger = app(SyncLedger::class);
        $product = $this->product();

        $record = $ledger->reconcile($product);
        $this->assertSame(SyncStatus::Pending, $record->status);

        // The website rejects it, then accepts the identical retry.
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['error' => 'Unprocessable payload'], 422)
            ->push(['ok' => true, 'applied' => true], 200),
        ]);

        (new DeliverProductToWebsite($product->bc_id))->handle($ledger, app(WebsiteClient::class));

        $record->refresh();

        $this->assertSame(SyncStatus::Failed, $record->status);
        $this->assertSame('HTTP 422: Unprocessable payload', $record->last_error);
        $this->assertNotNull($record->failed_at);
        $this->assertSame(1, $record->attempts);

        // The desired state survives: nothing was delivered, so nothing is recorded
        // as delivered, and the payload is still there to send again.
        $this->assertNull($record->delivered_hash);
        $this->assertNull($record->delivered_payload);
        $this->assertNotNull($record->payload);

        // A later successful attempt clears the error.
        (new DeliverProductToWebsite($product->bc_id))->handle($ledger, app(WebsiteClient::class));

        $record->refresh();

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertNull($record->last_error);
        $this->assertSame(2, $record->attempts);
    }
}
