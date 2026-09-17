<?php

namespace Tests\Feature\Http;

use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\SalesInvoices\SalesInvoiceFilter;
use App\Sync\SalesInvoiceSyncLedger;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The sales invoice list and detail pages.
 */
class SalesInvoiceControllerTest extends TestCase
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
    private function salesInvoice(array $attributes = []): SalesInvoice
    {
        return SalesInvoice::factory()->create($attributes);
    }

    public function test_the_list_renders(): void
    {
        $this->salesInvoice(['number' => 'INV000001', 'order_number' => 'SO000009', 'customer_name' => 'Woo Woo Ltd']);

        $this->get(route('sales-invoices.index'))
            ->assertOk()
            ->assertSee('Sales invoices')
            ->assertSee('INV000001')
            ->assertSee('SO000009')
            ->assertSee('Woo Woo Ltd');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get(route('sales-invoices.index'))
            ->assertOk()
            ->assertSee('No sales invoices or credit memos have been imported yet.');
    }

    public function test_the_search_filter_matches_number_order_customer_or_po(): void
    {
        $this->salesInvoice(['number' => 'INV000001', 'order_number' => 'SO000011', 'customer_name' => 'Three Stone Vets', 'external_document_number' => 'PO-77']);
        $this->salesInvoice(['number' => 'INV000002', 'order_number' => 'SO000022', 'customer_name' => 'Four Paws', 'external_document_number' => 'PO-88']);

        $this->get(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_SEARCH => 'Stone']))->assertOk()->assertSee('INV000001')->assertDontSee('INV000002');
        $this->get(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_SEARCH => 'SO000022']))->assertOk()->assertSee('INV000002')->assertDontSee('INV000001');
        $this->get(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_SEARCH => 'PO-77']))->assertOk()->assertSee('INV000001')->assertDontSee('INV000002');
    }

    public function test_the_kind_filter_narrows_to_that_kind(): void
    {
        $this->salesInvoice(['number' => 'INV-ONE']);
        SalesInvoice::factory()->creditMemo()->create(['number' => 'CM-ONE']);

        $this->get(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_KIND => 'credit_memo']))
            ->assertOk()
            ->assertSee('CM-ONE')
            ->assertDontSee('INV-ONE');

        $this->get(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_KIND => 'invoice']))
            ->assertOk()
            ->assertSee('INV-ONE')
            ->assertDontSee('CM-ONE');
    }

    public function test_an_unrecognised_kind_is_ignored(): void
    {
        $this->salesInvoice(['number' => 'INV-ONE']);

        $this->get(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_KIND => 'nonsense']))
            ->assertOk()
            ->assertSee('INV-ONE');
    }

    public function test_the_list_shows_the_kind(): void
    {
        SalesInvoice::factory()->creditMemo()->create(['number' => 'CM-ONE']);

        $this->get(route('sales-invoices.index'))->assertOk()->assertSee('Credit memo');
    }

    public function test_the_detail_page_of_a_credit_memo_says_so(): void
    {
        $memo = SalesInvoice::factory()->creditMemo()->create([
            'number' => 'CM104006',
            'customer_name' => 'Animates',
            'document_api_id' => '47519c62-298a-f111-8072-7ced8da08480',
        ]);

        $this->get(route('sales-invoices.show', $memo))
            ->assertOk()
            ->assertSee('Sales Credit Memo CM104006 for Animates')
            ->assertSee('Posted credit memo')
            ->assertSee('Credit memo date')
            ->assertSee('Document API id')
            ->assertSee('47519c62-298a-f111-8072-7ced8da08480')
            ->assertDontSee('Order date');
    }

    public function test_the_not_synced_filter_finds_invoices_with_no_ledger_row(): void
    {
        $delivered = $this->salesInvoice(['number' => 'INV-SENT']);
        $this->salesInvoice(['number' => 'INV-NEW']);

        app(SalesInvoiceSyncLedger::class)->markPending($delivered, []);

        $this->get(route('sales-invoices.index', [
            SalesInvoiceFilter::PARAM_SYNC => SalesInvoiceFilter::SYNC_NOT_SYNCED,
        ]))
            ->assertOk()
            ->assertSee('INV-NEW')
            ->assertDontSee('INV-SENT');
    }

    public function test_a_ledger_status_filter_reads_the_ledger(): void
    {
        $salesInvoice = $this->salesInvoice(['number' => 'INV-PENDING']);
        app(SalesInvoiceSyncLedger::class)->markPending($salesInvoice, []);

        $this->get(route('sales-invoices.index', [
            SalesInvoiceFilter::PARAM_SYNC => SyncStatus::Pending->value,
        ]))->assertOk()->assertSee('INV-PENDING');
    }

    public function test_the_detail_page_renders(): void
    {
        $salesInvoice = $this->salesInvoice([
            'number' => 'INV000001',
            'customer_name' => 'Woo Woo Ltd',
            'lines' => [SalesInvoiceFactory::line(['item_number' => 'CT35', 'description' => 'Thermometer'])],
        ]);

        $this->get(route('sales-invoices.show', $salesInvoice))
            ->assertOk()
            ->assertSee('INV000001')
            ->assertSee('Woo Woo Ltd')
            ->assertSee('CT35')
            ->assertSee('Thermometer')
            ->assertSee('Next delivery')
            ->assertSee('Full desired payload');
    }

    public function test_the_detail_page_links_to_the_order_while_it_is_still_here(): void
    {
        $order = SalesOrder::factory()->create(['number' => 'SO101164']);
        $salesInvoice = SalesInvoice::factory()->forOrder($order)->create();

        $this->get(route('sales-invoices.show', $salesInvoice))
            ->assertOk()
            ->assertSee(route('sales-orders.show', $order))
            ->assertDontSee('no longer in Business Central');
    }

    public function test_the_detail_page_says_when_the_order_is_gone(): void
    {
        $salesInvoice = $this->salesInvoice(['order_number' => 'SO000001']);

        $this->get(route('sales-invoices.show', $salesInvoice))
            ->assertOk()
            ->assertSee('SO000001')
            ->assertSee('no longer in Business Central');
    }

    public function test_the_detail_page_links_to_an_imported_customer(): void
    {
        $customer = Customer::factory()->create(['number' => 'CUST0001']);
        $salesInvoice = SalesInvoice::factory()->forCustomer($customer)->create();

        $this->get(route('sales-invoices.show', $salesInvoice))
            ->assertOk()
            ->assertSee(route('customers.show', $customer))
            ->assertDontSee('has not been imported yet');
    }

    public function test_the_detail_page_never_shows_a_removal(): void
    {
        $this->get(route('sales-invoices.show', $this->salesInvoice()))
            ->assertOk()
            ->assertDontSee('>Remove<', false);
    }

    public function test_an_unknown_invoice_is_not_found(): void
    {
        $this->get('/sales-invoice/99999')->assertNotFound();
    }

    public function test_the_sales_invoices_link_is_in_the_sidebar(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('sales-invoices.index'));
    }

    public function test_the_credit_memos_link_is_in_the_sidebar(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Sales credit memos')
            ->assertSee(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_KIND => 'credit_memo']), false);
    }

    /**
     * The two sidebar entries share one route, so the kind filter decides
     * which one is current; the heading follows it too.
     */
    public function test_the_credit_memo_list_is_its_own_page_in_the_sidebar(): void
    {
        $memoList = $this->get(route('sales-invoices.index', [SalesInvoiceFilter::PARAM_KIND => 'credit_memo']))->assertOk();

        $memoList->assertSee('<h1 class="text-[32px] font-extrabold tracking-tight">Sales credit memos</h1>', false);
        $memoList->assertSee('No sales credit memos have been imported yet.');
        $this->assertSame('credit_memo', $this->currentNavKind($memoList->getContent()));

        $invoiceList = $this->get(route('sales-invoices.index'))->assertOk();

        $invoiceList->assertSee('<h1 class="text-[32px] font-extrabold tracking-tight">Sales invoices</h1>', false);
        $this->assertSame('invoice', $this->currentNavKind($invoiceList->getContent()));
    }

    public function test_a_credit_memo_detail_page_belongs_to_the_credit_memo_entry(): void
    {
        $memo = SalesInvoice::factory()->creditMemo()->create();

        $page = $this->get(route('sales-invoices.show', $memo))->assertOk();

        $this->assertSame('credit_memo', $this->currentNavKind($page->getContent()));
        $page->assertSee('>Sales credit memos</a>', false);
    }

    public function test_an_invoice_detail_page_belongs_to_the_invoice_entry(): void
    {
        $page = $this->get(route('sales-invoices.show', $this->salesInvoice()))->assertOk();

        $this->assertSame('invoice', $this->currentNavKind($page->getContent()));
        $page->assertSee('>Sales invoices</a>', false);
    }

    /**
     * Which sales document entry the sidebar marks as current on this page.
     */
    private function currentNavKind(string $html): ?string
    {
        if (! preg_match_all('/<a href="([^"]+)"\s+aria-current="page"/', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $href) {
            if (str_contains($href, 'kind=credit_memo')) {
                return 'credit_memo';
            }

            if (str_contains($href, '/sales-invoice')) {
                return 'invoice';
            }
        }

        return null;
    }

    public function test_a_delivered_invoice_absent_from_the_website_is_flagged(): void
    {
        $salesInvoice = $this->salesInvoice(['number' => 'INV000001']);
        $ledger = app(SalesInvoiceSyncLedger::class);
        $ledger->markSynced($ledger->markPending($salesInvoice, []));

        $this->websiteHolds([]);

        $this->get(route('sales-invoices.show', $salesInvoice))
            ->assertOk()
            ->assertSee('Not on the website')
            ->assertSee('website:deliver-sales-invoices --number=INV000001 --send');
    }

    public function test_an_unreachable_website_reads_as_unknown_rather_than_missing(): void
    {
        $salesInvoice = $this->salesInvoice();

        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('sales-invoices.show', $salesInvoice))
            ->assertOk()
            ->assertDontSee('Not on the website')
            ->assertSee('could not be reached');
    }

    public function test_the_list_asks_the_website_once(): void
    {
        foreach (range(1, 4) as $n) {
            $this->salesInvoice(['number' => sprintf('INV%06d', $n)]);
        }

        $this->websiteHolds([]);

        $this->get(route('sales-invoices.index'))->assertOk();

        Http::assertSentCount(1);
    }
}
