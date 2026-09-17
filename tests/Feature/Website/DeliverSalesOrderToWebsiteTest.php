<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverSalesOrderToWebsite;
use App\Jobs\WebsiteDeliveryFailed;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Sync\CustomerSyncLedger;
use App\Sync\Payload\DeliveryType;
use App\Sync\SalesOrderSyncLedger;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use App\Website\WebsiteRequest;
use Database\Factories\SalesOrderFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivering a sales order's desired website state.
 *
 * No real request is ever made: the destination is a fake URL and every
 * response is stubbed.
 */
class DeliverSalesOrderToWebsiteTest extends TestCase
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

    private function ledger(): SalesOrderSyncLedger
    {
        return app(SalesOrderSyncLedger::class);
    }

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * An order whose customer has already reached the website.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function salesOrder(array $attributes = []): SalesOrder
    {
        $customer = Customer::factory()->create(['number' => '3STONEVE', 'display_name' => '3 Stone Veterinary Services']);

        $customerLedger = app(CustomerSyncLedger::class);
        $customerLedger->markSynced($customerLedger->markPending($customer, []));

        return SalesOrder::factory()->forCustomer($customer)->create($attributes + [
            'bc_id' => '8a2d5c1e-1b2c-4d3e-9f10-1112131415aa',
            'number' => 'SO000123',
        ]);
    }

    /**
     * The body of the one request that was sent.
     *
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $bodies = [];

        Http::assertSent(function (Request $request) use (&$bodies): bool {
            $bodies[] = json_decode($request->body(), true);

            return true;
        });

        return $bodies[0] ?? [];
    }

    private function deliver(SalesOrder $salesOrder): void
    {
        (new DeliverSalesOrderToWebsite($salesOrder->bc_id))->handle(
            $this->ledger(),
            app(WebsiteClient::class),
        );
    }

    // -------------------------------------------------------------- full

    public function test_a_first_delivery_sends_the_full_payload(): void
    {
        $salesOrder = $this->salesOrder();
        $this->ledger()->markPending($salesOrder, []);
        $this->accepted();

        $this->deliver($salesOrder);

        $body = $this->sentBody();

        $this->assertSame('sales_order', $body['entity']);
        $this->assertSame('upsert', $body['action']);
        $this->assertSame(WebsiteRequest::MODE_FULL, $body['mode']);
        $this->assertSame($salesOrder->bc_id, $body['bc_id']);
        $this->assertSame('SO000123', $body['payload']['number']);
        $this->assertSame($salesOrder->customer_bc_id, $body['payload']['customer_bc_id']);
        $this->assertSame('processing', $body['payload']['status']);
        $this->assertCount(1, $body['payload']['items']);
    }

    public function test_the_payload_does_not_carry_the_raw_business_central_row(): void
    {
        $salesOrder = $this->salesOrder(['bc_payload' => ['workDescription' => 'x']]);
        $this->ledger()->markPending($salesOrder, []);
        $this->accepted();

        $this->deliver($salesOrder);

        $this->assertArrayNotHasKey('bc_payload', $this->sentBody()['payload']);
    }

    public function test_a_successful_delivery_moves_the_record_to_synced(): void
    {
        $salesOrder = $this->salesOrder();
        $record = $this->ledger()->markPending($salesOrder, []);
        $this->accepted();

        $this->deliver($salesOrder);

        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
        $this->assertSame(WebsiteAction::Upsert, $record->fresh()->delivered_action);
    }

    // ----------------------------------------------------------- partial

    public function test_a_status_change_sends_a_partial_with_only_the_moved_fields(): void
    {
        $salesOrder = $this->salesOrder();
        $this->ledger()->markPending($salesOrder, []);
        $this->accepted();
        $this->deliver($salesOrder);

        $salesOrder->update(['bc_status' => 'Open', 'website_status' => 'received']);
        $this->ledger()->reconcile($salesOrder, ['bc_status', 'website_status']);
        $this->accepted();
        $this->deliver($salesOrder->fresh());

        $body = $this->sentBody();

        $this->assertSame(WebsiteRequest::MODE_PARTIAL, $body['mode']);
        $this->assertSame(['bc_status', 'status'], array_keys($body['changes']));
        $this->assertSame('received', $body['changes']['status']);
    }

    /**
     * The lines are a set of whole documents. A shipment moving one line's
     * quantity resends the whole list, because merging positionally would
     * silently move a quantity from one line to another.
     */
    public function test_a_line_change_resends_the_complete_list(): void
    {
        $salesOrder = $this->salesOrder();
        $this->ledger()->markPending($salesOrder, []);
        $this->accepted();
        $this->deliver($salesOrder);

        $salesOrder->update(['lines' => [
            SalesOrderFactory::line(['bc_id' => 'a', 'quantity_shipped' => 2.0]),
            SalesOrderFactory::line(['bc_id' => 'b']),
        ]]);
        $this->ledger()->reconcile($salesOrder, ['lines']);
        $this->accepted();
        $this->deliver($salesOrder->fresh());

        $body = $this->sentBody();

        $this->assertSame(['items'], array_keys($body['changes']));
        $this->assertCount(2, $body['changes']['items']);
    }

    // ----------------------------------------------- waits for the customer

    /**
     * The order post links to the customer post, so an order whose customer
     * has not reached the website is held pending rather than sent or failed.
     */
    public function test_an_order_whose_customer_is_not_on_the_website_is_held_pending(): void
    {
        $salesOrder = SalesOrder::factory()->create();
        $record = $this->ledger()->markPending($salesOrder, []);
        $this->accepted();

        $this->deliver($salesOrder);

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Pending, $record->fresh()->status);
        $this->assertNull($record->fresh()->last_error);
    }

    public function test_an_order_with_no_customer_is_held_pending(): void
    {
        $salesOrder = SalesOrder::factory()->create(['customer_bc_id' => null]);
        $record = $this->ledger()->markPending($salesOrder, []);
        $this->accepted();

        $this->deliver($salesOrder);

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Pending, $record->fresh()->status);
    }

    public function test_a_customer_only_pending_is_not_enough(): void
    {
        $customer = Customer::factory()->create();
        app(CustomerSyncLedger::class)->markPending($customer, []);

        $salesOrder = SalesOrder::factory()->forCustomer($customer)->create();
        $this->ledger()->markPending($salesOrder, []);
        $this->accepted();

        $this->deliver($salesOrder);

        Http::assertNothingSent();
    }

    // -------------------------------------------- never removed, only mirrored

    public function test_no_order_state_ever_plans_a_removal(): void
    {
        foreach ([['bc_status' => 'Open'], ['bc_status' => 'Released'], ['lines' => []], ['customer_bc_id' => null]] as $i => $state) {
            $salesOrder = SalesOrder::factory()->create($state + ['bc_id' => sprintf('%08d-a51d-f111-8340-7ced8d32d199', $i), 'number' => "N{$i}"]);

            $this->assertSame(WebsiteAction::Upsert, $this->ledger()->desiredAction($salesOrder), "state {$i}");
            $this->assertNotSame(DeliveryType::Remove, $this->ledger()->plan($salesOrder)->type, "state {$i}");
        }
    }

    public function test_a_completed_order_is_upserted_as_completed(): void
    {
        $customer = Customer::factory()->create();
        $customerLedger = app(CustomerSyncLedger::class);
        $customerLedger->markSynced($customerLedger->markPending($customer, []));

        $salesOrder = SalesOrder::factory()->forCustomer($customer)->completed()->create();
        $this->ledger()->markPending($salesOrder, []);
        $this->accepted();

        $this->deliver($salesOrder);

        $this->assertSame('upsert', $this->sentBody()['action']);
        $this->assertSame('completed', $this->sentBody()['payload']['status']);
    }

    // ------------------------------------------------------------ nothing

    public function test_a_second_delivery_of_an_unchanged_order_sends_nothing(): void
    {
        $salesOrder = $this->salesOrder();
        $this->ledger()->markPending($salesOrder, []);
        $this->accepted();
        $this->deliver($salesOrder);

        $record = $this->ledger()->find($salesOrder);

        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
        $this->deliver($salesOrder->fresh());

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
    }

    public function test_an_unchanged_reconcile_opens_no_work(): void
    {
        $salesOrder = $this->salesOrder();
        $this->ledger()->markPending($salesOrder, []);

        $this->assertNull($this->ledger()->reconcile($salesOrder));
    }

    // --------------------------------------------------------- failures

    public function test_a_transient_failure_records_the_error_and_throws_for_a_retry(): void
    {
        $salesOrder = $this->salesOrder();
        $record = $this->ledger()->markPending($salesOrder, []);
        Http::fake([self::ENDPOINT => Http::response('boom', 503)]);

        try {
            $this->deliver($salesOrder);
            $this->fail('Expected a transient failure to throw.');
        } catch (WebsiteDeliveryFailed) {
            // expected
        }

        $this->assertSame(SyncStatus::Failed, $record->fresh()->status);
        $this->assertStringContainsString('503', (string) $record->fresh()->last_error);
    }

    public function test_a_missing_order_post_forgets_the_delivered_state(): void
    {
        $salesOrder = $this->salesOrder();
        $record = $this->ledger()->markPending($salesOrder, []);

        // One fake with both answers: a second Http::fake() never overrides
        // the first, so the 409 has to be queued behind the 200 up front.
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['ok' => true, 'applied' => true], 200)
            ->push(['ok' => false, 'full_sync_required' => true], 409)]);

        $this->deliver($salesOrder);

        $salesOrder->update(['bc_status' => 'Open', 'website_status' => 'received']);
        $this->ledger()->reconcile($salesOrder, ['bc_status']);
        $this->deliver($salesOrder->fresh());

        $this->assertNull($record->fresh()->delivered_payload);
        $this->assertSame(DeliveryType::Full, $this->ledger()->plan($salesOrder->fresh())->type);
    }

    // ----------------------------------------------------------- identity

    public function test_the_ledger_row_records_the_sales_order_entity(): void
    {
        $salesOrder = $this->salesOrder();
        $record = $this->ledger()->markPending($salesOrder, []);

        $this->assertSame('sales_order', $record->entity);
        $this->assertSame('website', $record->channel);
        $this->assertSame($salesOrder->bc_id, $record->bc_id);
    }
}
