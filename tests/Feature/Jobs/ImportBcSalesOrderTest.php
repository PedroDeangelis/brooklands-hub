<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\Import\SalesOrderImporter;
use App\BusinessCentral\SalesOrderDetailFetcher;
use App\Jobs\DeliverSalesOrderToWebsite;
use App\Jobs\ImportBcSalesOrder;
use App\Models\SalesOrder;
use App\SalesOrders\SalesOrderStatus;
use App\Sync\SalesOrderSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing one sales order header, completing it with its documents from the
 * standard API, and opening the delivery it calls for.
 */
class ImportBcSalesOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const STANDARD = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/v2.0/companies(company-guid)';

    private const ORDER_ID = '8a2d5c1e-1b2c-4d3e-9f10-1112131415aa';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com', 'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc', 'client_secret' => 'secret-abc', 'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid', 'api_version' => 'v2.0', 'http_timeout' => 30, 'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Queue::fake([DeliverSalesOrderToWebsite::class]);

        // Registered once, reading $this->documents at request time: a second
        // Http::fake() never overrides the first, so a test that changes the
        // documents between imports swaps the data rather than the stubs.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD.'/salesOrders('.self::ORDER_ID.')/salesOrderLines*' => fn () => $this->documents['lines'] === null
                ? Http::response('boom', 500)
                : Http::response(['value' => $this->documents['lines']]),
            self::STANDARD.'/salesShipments*' => fn () => Http::response(['value' => $this->documents['shipments']]),
            self::STANDARD.'/salesInvoices*' => fn () => Http::response(['value' => $this->documents['invoices']]),
        ]);
    }

    /**
     * What the standard API answers for the order's documents. A null lines
     * list makes the lines request fail.
     *
     * @var array{lines: list<array<string, mixed>>|null, shipments: list<array<string, mixed>>, invoices: list<array<string, mixed>>}
     */
    private array $documents = ['lines' => [], 'shipments' => [], 'invoices' => []];

    /**
     * @return array<string, mixed>
     */
    private function row(string $city = 'Te Awamutu', string $status = 'Released'): array
    {
        return [
            'id' => self::ORDER_ID,
            'number' => 'SO000123',
            'orderDate' => '2026-06-14',
            'customerId' => '71431cfe-a51d-f111-8340-7ced8d32d199',
            'customerName' => '3 Stone Veterinary Services',
            'status' => $status,
            'shipToCity' => $city,
            'SystemCreatedAt' => '2026-06-14T20:07:51.167Z',
            'lastModifiedDateTime' => '2026-06-15T00:23:21.653Z',
        ];
    }

    /**
     * Set what the three document reads behind the header answer.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $shipments
     * @param  list<array<string, mixed>>  $invoices
     */
    private function fakeDocuments(array $lines = [], array $shipments = [], array $invoices = []): void
    {
        $this->documents = ['lines' => $lines, 'shipments' => $shipments, 'invoices' => $invoices];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bcLine(array $overrides = []): array
    {
        return array_replace([
            'id' => 'line-0001', 'sequence' => 10000, 'lineType' => 'Item', 'itemId' => 'item-aaaa',
            'lineObjectNumber' => 'ITEM0001', 'description' => 'Drench 5L', 'quantity' => 2, 'unitPrice' => 50,
            'shipQuantity' => 2, 'shippedQuantity' => 0, 'invoicedQuantity' => 0,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row, bool $force = false): void
    {
        (new ImportBcSalesOrder($row, $force))->handle(
            app(SalesOrderDetailFetcher::class),
            app(SalesOrderImporter::class),
            app(SalesOrderSyncLedger::class),
        );
    }

    // -------------------------------------------------------- documents

    public function test_the_documents_are_read_from_the_standard_api_and_stored(): void
    {
        $this->fakeDocuments(
            lines: [$this->bcLine(['shippedQuantity' => 2, 'invoicedQuantity' => 2])],
            shipments: [['id' => 's1', 'number' => 'SH001', 'externalDocumentNumber' => 'PO-77']],
            invoices: [['id' => 'i1', 'number' => 'INV001']],
        );

        $this->import($this->row());

        $order = SalesOrder::first();

        $this->assertCount(1, $order->lines());
        $this->assertSame('SH001', $order->shipments()[0]['number']);
        $this->assertSame('INV001', $order->invoices()[0]['number']);
        $this->assertSame(SalesOrderStatus::Completed, $order->websiteStatus());
    }

    public function test_shipments_and_invoices_are_looked_up_by_the_order_number(): void
    {
        $this->fakeDocuments();

        $this->import($this->row());

        Http::assertSent(function (Request $request): bool {
            if (str_contains($request->url(), '/salesShipments') || str_contains($request->url(), '/salesInvoices')) {
                $this->assertSame("orderNumber eq 'SO000123'", $request['$filter']);
            }

            return true;
        });
    }

    /**
     * Importing the header without its lines would derive a status from
     * nothing, so a failed read fails the job and the queue retries it.
     */
    public function test_a_failed_document_read_imports_nothing(): void
    {
        $this->documents['lines'] = null;

        try {
            $this->import($this->row());
            $this->fail('Expected the document read to throw.');
        } catch (BusinessCentralException) {
            // expected
        }

        $this->assertSame(0, SalesOrder::count());
        Queue::assertNothingPushed();
    }

    // --------------------------------------------------------- delivery

    public function test_a_new_order_opens_a_delivery(): void
    {
        $this->fakeDocuments([$this->bcLine()]);

        $this->import($this->row());

        $order = SalesOrder::first();

        $this->assertNotNull(app(SalesOrderSyncLedger::class)->find($order));
        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 1);
    }

    /**
     * The guard that stops an unchanged re-import queueing a delivery on every
     * pass: the ledger already wants exactly this, so there is no new work.
     */
    public function test_an_unchanged_reimport_dispatches_nothing_once_delivered(): void
    {
        $this->fakeDocuments([$this->bcLine()]);
        $ledger = app(SalesOrderSyncLedger::class);

        $this->import($this->row());
        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 1);

        // Delivered, so the pending re-queue below does not apply.
        $ledger->markSynced($ledger->find(SalesOrder::first()));

        $this->import($this->row());

        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 1);
    }

    /**
     * A row still pending is re-queued even when nothing moved: a delivery
     * can be held back waiting for the order's customer to reach the website,
     * and the next import is what gives it another go.
     */
    public function test_a_pending_order_is_requeued_even_when_unchanged(): void
    {
        $this->fakeDocuments([$this->bcLine()]);

        $this->import($this->row());
        $this->import($this->row());

        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 2);
    }

    public function test_a_shipment_posting_opens_another_delivery(): void
    {
        $this->fakeDocuments([$this->bcLine()]);
        $this->import($this->row());

        $ledger = app(SalesOrderSyncLedger::class);
        $ledger->markSynced($ledger->find(SalesOrder::first()));

        $this->fakeDocuments([$this->bcLine(['shippedQuantity' => 2])], [['id' => 's1', 'number' => 'SH001']]);
        $this->import($this->row());

        $record = $ledger->find(SalesOrder::first());

        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame(['lines', 'shipments', 'website_status'], $record->changed_fields);
        $this->assertSame('fully-shipped', $record->payload['status']);
        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 2);
    }

    public function test_force_dispatches_an_unchanged_order(): void
    {
        $this->fakeDocuments([$this->bcLine()]);
        $this->import($this->row());

        $ledger = app(SalesOrderSyncLedger::class);
        $ledger->markSynced($ledger->find(SalesOrder::first()));

        $this->import($this->row(), force: true);

        Queue::assertPushed(DeliverSalesOrderToWebsite::class, 2);
    }
}
