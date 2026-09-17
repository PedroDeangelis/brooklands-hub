<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverProductToWebsite;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Sync\SyncLedger;
use App\Website\WebsiteClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Synced must mean the website applied the desired state.
 *
 * A 2xx only says the request was understood. Treating it as a delivery would
 * record a payload as delivered that the website never applied, and every later
 * diff would then be computed against a state that does not exist.
 */
class AppliedSemanticsTest extends TestCase
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

    private function product(): Product
    {
        return Product::factory()->create([
            'sku' => 'POLY1',
            'type' => 'Inventory',
            'price' => 10,
            'blocked' => false,
            'sales_blocked' => false,
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_payload' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function deliver(array $body, int $status = 200): SyncRecord
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);

        $product = $this->product();
        $record = $this->ledger()->reconcile($product);

        (new DeliverProductToWebsite($product->bc_id))
            ->handle($this->ledger(), app(WebsiteClient::class));

        return $record->refresh();
    }

    /**
     * The transport-only endpoint's exact response.
     */
    public function test_accepted_without_applied_does_not_mark_synced(): void
    {
        $record = $this->deliver(['ok' => true, 'accepted' => true, 'applied' => false]);

        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertNotSame(SyncStatus::Synced, $record->status);
    }

    public function test_accepted_without_applied_records_nothing_as_delivered(): void
    {
        $record = $this->deliver(['ok' => true, 'accepted' => true, 'applied' => false]);

        $this->assertNull($record->delivered_action);
        $this->assertNull($record->delivered_hash);
        $this->assertNull($record->delivered_payload);
        $this->assertNull($record->synced_at);
    }

    /**
     * Nothing went wrong, so it must not read as a failure either.
     */
    public function test_accepted_without_applied_is_not_an_error(): void
    {
        $record = $this->deliver(['ok' => true, 'accepted' => true, 'applied' => false]);

        $this->assertNull($record->last_error);
        $this->assertNull($record->failed_at);
    }

    /**
     * The work is still owed, so a later delivery must still have it to send.
     */
    public function test_the_desired_state_survives_an_unapplied_response(): void
    {
        $record = $this->deliver(['ok' => true, 'accepted' => true, 'applied' => false]);

        $this->assertNotNull($record->payload);
        $this->assertSame('POLY1', $record->payload['sku']);

        $product = Product::query()->sole();
        $this->assertSame('full', $this->ledger()->plan($product)->type->value);
    }

    /**
     * A 2xx with no applied key at all is the same claim: nothing was applied.
     */
    public function test_a_bare_ok_response_does_not_mark_synced(): void
    {
        $this->assertSame(SyncStatus::Pending, $this->deliver(['ok' => true])->status);
    }

    public function test_an_empty_body_does_not_mark_synced(): void
    {
        $this->assertSame(SyncStatus::Pending, $this->deliver([])->status);
    }

    /**
     * Only the website's own explicit claim counts.
     */
    public function test_applied_true_marks_synced(): void
    {
        $record = $this->deliver(['ok' => true, 'applied' => true, 'wp_id' => 123]);

        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertNotNull($record->synced_at);
        $this->assertSame($record->payload, $record->delivered_payload);
        $this->assertSame($record->payload_hash, $record->delivered_hash);
    }

    public function test_a_truthy_but_non_boolean_applied_does_not_count(): void
    {
        // "true" as a string, or 1, is a sloppy receiver rather than a claim we
        // should act on. Requiring a real boolean keeps the contract exact.
        foreach (['true', 1, 'yes'] as $value) {
            $this->assertSame(
                SyncStatus::Pending,
                $this->deliver(['ok' => true, 'applied' => $value])->status,
                'applied='.var_export($value, true).' should not count as applied',
            );
        }
    }

    /**
     * An unapplied response leaves the record ready to try again, and the next
     * attempt can succeed.
     */
    public function test_a_later_applied_response_completes_the_delivery(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['ok' => true, 'accepted' => true, 'applied' => false], 200)
            ->push(['ok' => true, 'applied' => true, 'wp_id' => 123], 200),
        ]);

        $product = $this->product();
        $record = $this->ledger()->reconcile($product);
        $client = app(WebsiteClient::class);

        (new DeliverProductToWebsite($product->bc_id))->handle($this->ledger(), $client);
        $this->assertSame(SyncStatus::Pending, $record->refresh()->status);

        (new DeliverProductToWebsite($product->bc_id))->handle($this->ledger(), $client);
        $this->assertSame(SyncStatus::Synced, $record->refresh()->status);
    }
}
