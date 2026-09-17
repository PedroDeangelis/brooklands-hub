<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverSalesInvoiceToWebsite;
use App\Jobs\WebsiteDeliveryFailed;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Sync\CustomerSyncLedger;
use App\Sync\Payload\DeliveryType;
use App\Sync\SalesInvoiceSyncLedger;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use App\Website\WebsiteRequest;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivering a posted invoice's desired website state.
 */
class DeliverSalesInvoiceToWebsiteTest extends TestCase
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

    private function ledger(): SalesInvoiceSyncLedger
    {
        return app(SalesInvoiceSyncLedger::class);
    }

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * An invoice whose customer has already reached the website.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function salesInvoice(array $attributes = []): SalesInvoice
    {
        $customer = Customer::factory()->create(['number' => 'WOOWOO', 'display_name' => 'Sara Is Woo Woo Ltd']);

        $customerLedger = app(CustomerSyncLedger::class);
        $customerLedger->markSynced($customerLedger->markPending($customer, []));

        return SalesInvoice::factory()->forCustomer($customer)->create($attributes + [
            'bc_id' => '974b40cf-a5b0-f111-aaa9-6045bde73f9b',
            'number' => 'INV103044',
            'order_number' => 'SO101164',
        ]);
    }

    /**
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

    private function deliver(SalesInvoice $salesInvoice): void
    {
        (new DeliverSalesInvoiceToWebsite($salesInvoice->bc_id))->handle(
            $this->ledger(),
            app(WebsiteClient::class),
        );
    }

    public function test_a_first_delivery_sends_the_full_payload(): void
    {
        $salesInvoice = $this->salesInvoice();
        $this->ledger()->markPending($salesInvoice, []);
        $this->accepted();

        $this->deliver($salesInvoice);

        $body = $this->sentBody();

        $this->assertSame('sales_invoice', $body['entity']);
        $this->assertSame('upsert', $body['action']);
        $this->assertSame(WebsiteRequest::MODE_FULL, $body['mode']);
        $this->assertSame($salesInvoice->bc_id, $body['bc_id']);
        $this->assertSame('INV103044', $body['payload']['number']);
        $this->assertSame('SO101164', $body['payload']['order_number']);
        $this->assertSame('invoice', $body['payload']['status']);
        $this->assertCount(1, $body['payload']['items']);
    }

    /**
     * A credit memo goes over the same entity, told apart only by status.
     */
    public function test_a_credit_memo_delivers_as_a_sales_invoice_with_the_credit_memo_status(): void
    {
        $customer = Customer::factory()->create();
        $customerLedger = app(CustomerSyncLedger::class);
        $customerLedger->markSynced($customerLedger->markPending($customer, []));

        $memo = SalesInvoice::factory()->creditMemo()->forCustomer($customer)->create([
            'document_api_id' => '47519c62-298a-f111-8072-7ced8da08480',
        ]);
        $this->ledger()->markPending($memo, []);
        $this->accepted();

        $this->deliver($memo);

        $body = $this->sentBody();

        $this->assertSame('sales_invoice', $body['entity']);
        $this->assertSame('credit_memo', $body['payload']['status']);
        $this->assertSame('47519c62-298a-f111-8072-7ced8da08480', $body['payload']['document_api_id']);
    }

    public function test_a_successful_delivery_moves_the_record_to_synced(): void
    {
        $salesInvoice = $this->salesInvoice();
        $record = $this->ledger()->markPending($salesInvoice, []);
        $this->accepted();

        $this->deliver($salesInvoice);

        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
        $this->assertSame(WebsiteAction::Upsert, $record->fresh()->delivered_action);
    }

    public function test_a_line_change_resends_the_complete_list_as_a_partial(): void
    {
        $salesInvoice = $this->salesInvoice();
        $this->ledger()->markPending($salesInvoice, []);
        $this->accepted();
        $this->deliver($salesInvoice);

        $salesInvoice->update(['lines' => [SalesInvoiceFactory::line(['bc_id' => 'a']), SalesInvoiceFactory::line(['bc_id' => 'b'])]]);
        $this->ledger()->reconcile($salesInvoice, ['lines']);
        $this->accepted();
        $this->deliver($salesInvoice->fresh());

        $body = $this->sentBody();

        $this->assertSame(WebsiteRequest::MODE_PARTIAL, $body['mode']);
        $this->assertSame(['items'], array_keys($body['changes']));
        $this->assertCount(2, $body['changes']['items']);
    }

    public function test_an_invoice_whose_customer_is_not_on_the_website_is_held_pending(): void
    {
        $salesInvoice = SalesInvoice::factory()->create();
        $record = $this->ledger()->markPending($salesInvoice, []);
        $this->accepted();

        $this->deliver($salesInvoice);

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Pending, $record->fresh()->status);
        $this->assertNull($record->fresh()->last_error);
    }

    public function test_no_invoice_state_ever_plans_a_removal(): void
    {
        foreach ([['lines' => []], ['customer_bc_id' => null], ['order_number' => '']] as $i => $state) {
            $salesInvoice = SalesInvoice::factory()->create($state + ['bc_id' => sprintf('%08d-a51d-f111-8340-7ced8d32d199', $i), 'number' => "N{$i}"]);

            $this->assertSame(WebsiteAction::Upsert, $this->ledger()->desiredAction($salesInvoice), "state {$i}");
            $this->assertNotSame(DeliveryType::Remove, $this->ledger()->plan($salesInvoice)->type, "state {$i}");
        }
    }

    public function test_a_second_delivery_of_an_unchanged_invoice_sends_nothing(): void
    {
        $salesInvoice = $this->salesInvoice();
        $this->ledger()->markPending($salesInvoice, []);
        $this->accepted();
        $this->deliver($salesInvoice);

        $record = $this->ledger()->find($salesInvoice);

        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
        $this->deliver($salesInvoice->fresh());

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
    }

    public function test_a_transient_failure_records_the_error_and_throws_for_a_retry(): void
    {
        $salesInvoice = $this->salesInvoice();
        $record = $this->ledger()->markPending($salesInvoice, []);
        Http::fake([self::ENDPOINT => Http::response('boom', 503)]);

        try {
            $this->deliver($salesInvoice);
            $this->fail('Expected a transient failure to throw.');
        } catch (WebsiteDeliveryFailed) {
            // expected
        }

        $this->assertSame(SyncStatus::Failed, $record->fresh()->status);
    }

    public function test_the_ledger_row_records_the_sales_invoice_entity(): void
    {
        $salesInvoice = $this->salesInvoice();
        $record = $this->ledger()->markPending($salesInvoice, []);

        $this->assertSame('sales_invoice', $record->entity);
        $this->assertSame('website', $record->channel);
        $this->assertSame($salesInvoice->bc_id, $record->bc_id);
    }
}
