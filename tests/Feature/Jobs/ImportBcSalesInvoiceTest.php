<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\Import\SalesInvoiceImporter;
use App\BusinessCentral\SalesInvoiceDetailFetcher;
use App\Jobs\DeliverSalesInvoiceToWebsite;
use App\Jobs\ImportBcSalesInvoice;
use App\Models\SalesInvoice;
use App\Sync\SalesInvoiceSyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing one posted invoice header, completing it with its lines from the
 * standard API, and opening the delivery it calls for.
 */
class ImportBcSalesInvoiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const STANDARD = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/v2.0/companies(company-guid)';

    private const INVOICE_ID = '974b40cf-a5b0-f111-aaa9-6045bde73f9b';

    /**
     * What the standard API answers for the invoice's lines. Null makes the
     * request fail. Read at request time, because a second Http::fake() never
     * overrides the first.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com', 'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc', 'client_secret' => 'secret-abc', 'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid', 'api_version' => 'v2.0', 'http_timeout' => 30, 'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Queue::fake([DeliverSalesInvoiceToWebsite::class]);

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD.'/salesInvoices('.self::INVOICE_ID.')/salesInvoiceLines*' => fn () => $this->lines === null
                ? Http::response('boom', 500)
                : Http::response(['value' => $this->lines]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $po = 'PO-9'): array
    {
        return [
            'id' => self::INVOICE_ID,
            'number' => 'INV103044',
            'orderNumber' => 'SO101164',
            'invoiceDate' => '2026-09-15',
            'customerId' => 'cabd0691-8521-f111-8340-7ced8d32d199',
            'customerName' => 'Sara Is Woo Woo Ltd',
            'externalDocumentNumber' => $po,
            'SystemCreatedAt' => '2026-09-15T01:36:05.47Z',
            'lastModifiedDateTime' => '2026-09-15T01:36:07.28Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bcLine(): array
    {
        return [
            'id' => 'line-0001', 'sequence' => 10000, 'lineType' => 'Item', 'itemId' => 'item-aaaa',
            'lineObjectNumber' => 'CT35', 'description' => 'Hailea Electronic Thermometer', 'quantity' => 11, 'unitPrice' => 7.75,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row, bool $force = false): void
    {
        (new ImportBcSalesInvoice($row, $force))->handle(
            app(SalesInvoiceDetailFetcher::class),
            app(SalesInvoiceImporter::class),
            app(SalesInvoiceSyncLedger::class),
        );
    }

    public function test_the_lines_are_read_from_the_standard_api_and_stored(): void
    {
        $this->lines = [$this->bcLine()];

        $this->import($this->row());

        $invoice = SalesInvoice::first();

        $this->assertCount(1, $invoice->lines());
        $this->assertSame('CT35', $invoice->lines()[0]['item_number']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/salesInvoices('.self::INVOICE_ID.')/salesInvoiceLines')
            || str_contains($request->url(), 'token'));
    }

    public function test_a_failed_line_read_imports_nothing(): void
    {
        $this->lines = null;

        try {
            $this->import($this->row());
            $this->fail('Expected the line read to throw.');
        } catch (BusinessCentralException) {
            // expected
        }

        $this->assertSame(0, SalesInvoice::count());
        Queue::assertNothingPushed();
    }

    public function test_a_new_invoice_opens_a_delivery(): void
    {
        $this->import($this->row());

        $this->assertNotNull(app(SalesInvoiceSyncLedger::class)->find(SalesInvoice::first()));
        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 1);
    }

    public function test_an_unchanged_reimport_dispatches_nothing_once_delivered(): void
    {
        $ledger = app(SalesInvoiceSyncLedger::class);

        $this->import($this->row());
        $ledger->markSynced($ledger->find(SalesInvoice::first()));

        $this->import($this->row());

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 1);
    }

    public function test_a_pending_invoice_is_requeued_even_when_unchanged(): void
    {
        $this->import($this->row());
        $this->import($this->row());

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 2);
    }

    public function test_a_changed_invoice_opens_another_delivery(): void
    {
        $ledger = app(SalesInvoiceSyncLedger::class);

        $this->import($this->row());
        $ledger->markSynced($ledger->find(SalesInvoice::first()));

        $this->import($this->row(po: 'PO-10'));

        $this->assertSame(['external_document_number'], $ledger->find(SalesInvoice::first())->changed_fields);
        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 2);
    }

    public function test_force_dispatches_an_unchanged_invoice(): void
    {
        $ledger = app(SalesInvoiceSyncLedger::class);

        $this->import($this->row());
        $ledger->markSynced($ledger->find(SalesInvoice::first()));

        $this->import($this->row(), force: true);

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 2);
    }
}
