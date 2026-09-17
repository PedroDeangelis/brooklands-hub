<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverCustomerToWebsite;
use App\Models\Customer;
use App\Sync\CustomerSyncLedger;
use App\Sync\Payload\DeliveryType;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use App\Website\WebsiteRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivering a customer's desired website state.
 *
 * No real request is ever made: the destination is a fake URL and every
 * response is stubbed.
 */
class DeliverCustomerToWebsiteTest extends TestCase
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

    private function ledger(): CustomerSyncLedger
    {
        return app(CustomerSyncLedger::class);
    }

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes + [
            'bc_id' => '71431cfe-a51d-f111-8340-7ced8d32d199',
            'number' => '3STONEVE',
            'display_name' => '3 Stone Veterinary Services',
            'shipping_addresses' => [['bc_id' => 'a', 'code' => 'MAIN', 'city' => 'Te Awamutu']],
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

    private function deliver(Customer $customer): void
    {
        (new DeliverCustomerToWebsite($customer->bc_id))->handle(
            $this->ledger(),
            app(WebsiteClient::class),
        );
    }

    // -------------------------------------------------------------- full

    public function test_a_first_delivery_sends_the_full_payload(): void
    {
        $customer = $this->customer();
        $this->ledger()->markPending($customer, []);
        $this->accepted();

        $this->deliver($customer);

        $body = $this->sentBody();

        $this->assertSame('customer', $body['entity']);
        $this->assertSame('upsert', $body['action']);
        $this->assertSame(WebsiteRequest::MODE_FULL, $body['mode']);
        $this->assertSame($customer->bc_id, $body['bc_id']);
        $this->assertSame('3STONEVE', $body['payload']['number']);
        $this->assertSame('3 Stone Veterinary Services', $body['payload']['name']);
        $this->assertSame('MAIN', $body['payload']['shipping_addresses'][0]['code']);
        $this->assertSame('none', $body['payload']['blocked']);
    }

    /**
     * The raw Business Central row carries fields the website has no use for,
     * and any movement inside it would change the hash and resend an otherwise
     * identical payload.
     */
    public function test_the_payload_does_not_carry_the_raw_business_central_row(): void
    {
        $customer = $this->customer(['bc_payload' => ['shipmentMethodCode' => '']]);
        $this->ledger()->markPending($customer, []);
        $this->accepted();

        $this->deliver($customer);

        $this->assertArrayNotHasKey('bc_payload', $this->sentBody()['payload']);
        $this->assertSame(
            ['address_1', 'address_2', 'attachments', 'bc_id', 'blocked', 'city', 'country', 'customer_price_group',
                'email', 'name', 'number', 'phone', 'postal_code', 'salesperson_code', 'shipment_location_code',
                'shipment_method', 'shipping_addresses', 'state', 'type'],
            collect(array_keys($this->sentBody()['payload']))->sort()->values()->all(),
        );
    }

    public function test_a_successful_delivery_moves_the_record_to_synced(): void
    {
        $customer = $this->customer();
        $record = $this->ledger()->markPending($customer, []);
        $this->accepted();

        $this->deliver($customer);

        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
        $this->assertSame(WebsiteAction::Upsert, $record->fresh()->delivered_action);
    }

    // ----------------------------------------------------------- partial

    public function test_a_name_change_sends_a_partial_with_only_that_field(): void
    {
        $customer = $this->customer();
        $this->ledger()->markPending($customer, []);
        $this->accepted();
        $this->deliver($customer);

        $customer->update(['display_name' => 'Three Stone Vets']);
        $this->ledger()->reconcile($customer, ['display_name']);
        $this->accepted();
        $this->deliver($customer->fresh());

        $body = $this->sentBody();

        $this->assertSame('customer', $body['entity']);
        $this->assertSame(WebsiteRequest::MODE_PARTIAL, $body['mode']);
        $this->assertSame(['name'], array_keys($body['changes']));
        $this->assertSame('Three Stone Vets', $body['changes']['name']);
    }

    /**
     * The ship-to list is a set of whole addresses. Adding one resends the
     * whole normalised list, because merging positionally into a sorted array
     * would silently reassign addresses.
     */
    public function test_a_shipping_address_change_resends_the_complete_list(): void
    {
        $customer = $this->customer();
        $this->ledger()->markPending($customer, []);
        $this->accepted();
        $this->deliver($customer);

        $customer->update(['shipping_addresses' => [
            ['bc_id' => 'a', 'code' => 'MAIN', 'city' => 'Te Awamutu'],
            ['bc_id' => 'b', 'code' => 'WAREHOUSE', 'city' => 'Hamilton'],
        ]]);
        $this->ledger()->reconcile($customer, ['shipping_addresses']);
        $this->accepted();
        $this->deliver($customer->fresh());

        $body = $this->sentBody();

        $this->assertSame(['shipping_addresses'], array_keys($body['changes']));
        $this->assertCount(2, $body['changes']['shipping_addresses']);
    }

    public function test_an_emptied_address_list_is_sent_as_an_empty_list(): void
    {
        $customer = $this->customer();
        $this->ledger()->markPending($customer, []);
        $this->accepted();
        $this->deliver($customer);

        $customer->update(['shipping_addresses' => []]);
        $this->ledger()->reconcile($customer, ['shipping_addresses']);
        $this->accepted();
        $this->deliver($customer->fresh());

        $this->assertSame([], $this->sentBody()['changes']['shipping_addresses']);
    }

    // -------------------------------------------- never removed, only mirrored

    /**
     * A blocked customer is still a fact the website needs: it goes over as an
     * upsert carrying the block, and the website decides what that restricts.
     */
    public function test_a_blocked_customer_is_upserted_with_the_block(): void
    {
        $customer = $this->customer(['blocked' => 'All']);
        $this->ledger()->markPending($customer, []);
        $this->accepted();

        $this->deliver($customer);

        $body = $this->sentBody();

        $this->assertSame('customer', $body['entity']);
        $this->assertSame('upsert', $body['action']);
        $this->assertSame('All', $body['payload']['blocked']);
    }

    public function test_no_customer_state_ever_plans_a_removal(): void
    {
        foreach ([['blocked' => 'none'], ['blocked' => 'All'], ['shipping_addresses' => []], ['customer_price_group' => '', 'customer_disc_group' => '']] as $i => $state) {
            $customer = Customer::factory()->create($state + ['bc_id' => sprintf('%08d-a51d-f111-8340-7ced8d32d199', $i), 'number' => "N{$i}"]);

            $this->assertSame(WebsiteAction::Upsert, $this->ledger()->desiredAction($customer), "state {$i}");
            $this->assertNotSame(DeliveryType::Remove, $this->ledger()->plan($customer)->type, "state {$i}");
        }
    }

    /**
     * The discount group takes precedence over the price group, reproducing
     * the legacy upserter because price-group.php reads whatever is stored.
     */
    public function test_the_discount_group_wins_over_the_price_group(): void
    {
        $customer = $this->customer(['customer_price_group' => 'LIST PRICE', 'customer_disc_group' => 'DISC5%']);
        $this->ledger()->markPending($customer, []);
        $this->accepted();
        $this->deliver($customer);

        $this->assertSame('DISC5%', $this->sentBody()['payload']['customer_price_group']);
    }

    public function test_livestock_location_maps_to_the_website_choice(): void
    {
        $customer = $this->customer(['shipping_location_code' => 'LIVESTOCK', 'shipment_method_code' => '']);
        $this->ledger()->markPending($customer, []);
        $this->accepted();
        $this->deliver($customer);

        $this->assertSame('livestock', $this->sentBody()['payload']['shipment_location_code']);
        $this->assertSame('std', $this->sentBody()['payload']['shipment_method']);
    }

    // ------------------------------------------------------------ nothing

    public function test_an_unchanged_customer_plans_nothing(): void
    {
        $customer = $this->customer();
        $this->ledger()->markPending($customer, []);
        $this->accepted();
        $this->deliver($customer);

        $this->assertSame(
            DeliveryType::None,
            $this->ledger()->plan($customer->fresh())->type,
        );
    }

    public function test_a_second_delivery_of_an_unchanged_customer_sends_nothing(): void
    {
        $customer = $this->customer();
        $this->ledger()->markPending($customer, []);
        $this->accepted();
        $this->deliver($customer);

        $record = $this->ledger()->find($customer);

        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
        $this->deliver($customer->fresh());

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
    }

    public function test_an_unchanged_reconcile_opens_no_work(): void
    {
        $customer = $this->customer();
        $this->ledger()->markPending($customer, []);

        $this->assertNull($this->ledger()->reconcile($customer));
    }

    // ----------------------------------------------------------- identity

    /**
     * Identity is the triple, so a customer and a product carrying the same
     * Business Central id cannot be mistaken for one another.
     */
    public function test_the_ledger_row_records_the_customer_entity(): void
    {
        $customer = $this->customer();
        $record = $this->ledger()->markPending($customer, []);

        $this->assertSame('customer', $record->entity);
        $this->assertSame('website', $record->channel);
        $this->assertSame($customer->bc_id, $record->bc_id);
    }
}
