<?php

namespace Tests\Feature\Http;

use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\SalesOrders\SalesOrderFilter;
use App\SalesOrders\SalesOrderStatus;
use App\Sync\SalesOrderSyncLedger;
use Database\Factories\SalesOrderFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The sales order list and detail pages.
 */
class SalesOrderControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = 'https://website.test/wp-json/horizon/v2/products';

    private const STATUS_ENDPOINT = 'https://website.test/wp-json/horizon/v2/status';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.website', [
            'url' => self::ENDPOINT,
            'secret' => 'shared-secret-for-tests',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * @param  array<string, array{wp_id: int, status: string}>  $records
     */
    private function websiteHolds(array $records): void
    {
        Http::fake([
            self::STATUS_ENDPOINT => Http::response(['ok' => true, 'records' => $records], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function salesOrder(array $attributes = []): SalesOrder
    {
        return SalesOrder::factory()->create($attributes);
    }

    // ------------------------------------------------------------- the list

    public function test_the_list_renders(): void
    {
        $this->salesOrder(['number' => 'SO000001', 'customer_name' => 'Three Stone Vets']);

        $this->get(route('sales-orders.index'))
            ->assertOk()
            ->assertSee('Sales orders')
            ->assertSee('SO000001')
            ->assertSee('Three Stone Vets');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get(route('sales-orders.index'))
            ->assertOk()
            ->assertSee('No sales orders have been imported yet.');
    }

    // ---------------------------------------------------------- filtering

    /**
     * The filter reads the stored website status, which the importer writes
     * from the same rule the badge shows, so the two cannot disagree.
     */
    public function test_the_status_filter_narrows_to_that_status(): void
    {
        $this->salesOrder(['number' => 'SO-PROC']);
        SalesOrder::factory()->completed()->create(['number' => 'SO-DONE']);

        $this->get(route('sales-orders.index', [
            SalesOrderFilter::PARAM_STATUS => SalesOrderStatus::Completed->value,
        ]))
            ->assertOk()
            ->assertSee('SO-DONE')
            ->assertDontSee('SO-PROC');
    }

    public function test_the_search_filter_matches_number_customer_or_po_number(): void
    {
        $this->salesOrder(['number' => 'SO000001', 'customer_name' => 'Three Stone Vets', 'external_document_number' => 'PO-77']);
        $this->salesOrder(['number' => 'SO000002', 'customer_name' => 'Four Paws', 'external_document_number' => 'PO-88']);

        $this->get(route('sales-orders.index', [SalesOrderFilter::PARAM_SEARCH => 'Stone']))->assertOk()->assertSee('SO000001')->assertDontSee('SO000002');
        $this->get(route('sales-orders.index', [SalesOrderFilter::PARAM_SEARCH => 'PO-88']))->assertOk()->assertSee('SO000002')->assertDontSee('SO000001');
    }

    public function test_the_not_synced_filter_finds_orders_with_no_ledger_row(): void
    {
        $delivered = $this->salesOrder(['number' => 'SO-SENT']);
        $this->salesOrder(['number' => 'SO-NEW']);

        app(SalesOrderSyncLedger::class)->markPending($delivered, []);

        $this->get(route('sales-orders.index', [
            SalesOrderFilter::PARAM_SYNC => SalesOrderFilter::SYNC_NOT_SYNCED,
        ]))
            ->assertOk()
            ->assertSee('SO-NEW')
            ->assertDontSee('SO-SENT');
    }

    public function test_a_ledger_status_filter_reads_the_ledger(): void
    {
        $salesOrder = $this->salesOrder(['number' => 'SO-PENDING']);
        app(SalesOrderSyncLedger::class)->markPending($salesOrder, []);

        $this->get(route('sales-orders.index', [
            SalesOrderFilter::PARAM_SYNC => SyncStatus::Pending->value,
        ]))->assertOk()->assertSee('SO-PENDING');
    }

    public function test_an_unrecognised_filter_value_is_ignored(): void
    {
        $this->salesOrder(['number' => 'SO000001']);

        $this->get(route('sales-orders.index', [
            SalesOrderFilter::PARAM_STATUS => 'nonsense',
            SalesOrderFilter::PARAM_SYNC => 'nonsense',
        ]))->assertOk()->assertSee('SO000001');
    }

    // ----------------------------------------------------------- the detail

    public function test_the_detail_page_renders(): void
    {
        $salesOrder = $this->salesOrder([
            'number' => 'SO000001',
            'customer_name' => 'Three Stone Vets',
            'lines' => [SalesOrderFactory::line(['item_number' => 'ITEM0001', 'description' => 'Drench 5L'])],
        ]);

        $this->get(route('sales-orders.show', $salesOrder))
            ->assertOk()
            ->assertSee('SO000001')
            ->assertSee('Three Stone Vets')
            ->assertSee('Business Central')
            ->assertSee('ITEM0001')
            ->assertSee('Drench 5L')
            ->assertSee('Next delivery')
            ->assertSee('Full desired payload');
    }

    public function test_the_detail_page_links_to_an_imported_customer(): void
    {
        $customer = Customer::factory()->create(['number' => 'CUST0001']);
        $salesOrder = SalesOrder::factory()->forCustomer($customer)->create();

        $this->get(route('sales-orders.show', $salesOrder))
            ->assertOk()
            ->assertSee(route('customers.show', $customer))
            ->assertDontSee('has not been imported yet');
    }

    public function test_the_detail_page_warns_when_the_customer_is_not_imported(): void
    {
        $salesOrder = $this->salesOrder();

        $this->get(route('sales-orders.show', $salesOrder))
            ->assertOk()
            ->assertSee('has not been imported yet');
    }

    public function test_the_detail_page_never_shows_a_removal(): void
    {
        $this->get(route('sales-orders.show', $this->salesOrder()))
            ->assertOk()
            ->assertDontSee('>Remove<', false);
    }

    public function test_an_order_with_no_ledger_row_says_so(): void
    {
        $this->get(route('sales-orders.show', $this->salesOrder()))
            ->assertOk()
            ->assertSee('No ledger row yet.');
    }

    public function test_an_unknown_order_is_not_found(): void
    {
        $this->get('/sales-order/99999')->assertNotFound();
    }

    // ----------------------------------------------------------- navigation

    public function test_the_sales_orders_link_is_in_the_sidebar(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('sales-orders.index'));
    }

    // ------------------------------------------- what the website actually holds

    public function test_a_delivered_order_absent_from_the_website_is_flagged(): void
    {
        $salesOrder = $this->salesOrder(['number' => 'SO000001']);
        $ledger = app(SalesOrderSyncLedger::class);
        $ledger->markSynced($ledger->markPending($salesOrder, []));

        $this->websiteHolds([]);

        $this->get(route('sales-orders.show', $salesOrder))
            ->assertOk()
            ->assertSee('Not on the website')
            ->assertSee('website:deliver-sales-orders --number=SO000001 --send');
    }

    public function test_an_order_the_website_holds_is_not_flagged(): void
    {
        $salesOrder = $this->salesOrder();

        $this->websiteHolds([$salesOrder->bc_id => ['wp_id' => 121638, 'status' => 'publish']]);

        $this->get(route('sales-orders.show', $salesOrder))
            ->assertOk()
            ->assertDontSee('Not on the website')
            ->assertSee('post 121638');
    }

    public function test_an_unreachable_website_reads_as_unknown_rather_than_missing(): void
    {
        $salesOrder = $this->salesOrder();

        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('sales-orders.show', $salesOrder))
            ->assertOk()
            ->assertDontSee('Not on the website')
            ->assertSee('could not be reached');
    }

    public function test_the_list_asks_the_website_once(): void
    {
        foreach (range(1, 4) as $n) {
            $this->salesOrder(['number' => sprintf('SO%06d', $n)]);
        }

        $this->websiteHolds([]);

        $this->get(route('sales-orders.index'))->assertOk();

        Http::assertSentCount(1);
    }
}
