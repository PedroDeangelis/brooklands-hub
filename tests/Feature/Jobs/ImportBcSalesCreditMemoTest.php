<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\Import\SalesInvoiceImporter;
use App\BusinessCentral\SalesCreditMemoDetailFetcher;
use App\Jobs\DeliverSalesInvoiceToWebsite;
use App\Jobs\ImportBcSalesCreditMemo;
use App\Models\SalesInvoice;
use App\SalesInvoices\SalesInvoiceKind;
use App\Sync\SalesInvoiceSyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing one posted credit memo header, completing it with its lines from
 * the standard API, and opening the delivery it calls for.
 *
 * The assertion that matters most is which GUID the lines are read by: the
 * memo's documentApiId, never its own id, because for some memos the two
 * differ and the id answers 404.
 */
class ImportBcSalesCreditMemoTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const STANDARD = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/v2.0/companies(company-guid)';

    private const ID = '3e18e2f7-298a-f111-8072-7ced8da08480';

    private const DOCUMENT_API_ID = '47519c62-298a-f111-8072-7ced8da08480';

    /**
     * What the standard API answers for the memo's lines, by the GUID asked
     * for. Read at request time, because a second Http::fake() never
     * overrides the first. A GUID absent from the map answers 404, as
     * Business Central does for a memo addressed by the wrong GUID.
     *
     * @var array<string, list<array<string, mixed>>|null>
     */
    private array $linesByGuid = [];

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
            self::STANDARD.'/salesCreditMemos(*' => function (Request $request) {
                preg_match('/salesCreditMemos\(([^)]+)\)/', $request->url(), $m);
                $guid = $m[1] ?? '';

                if (! array_key_exists($guid, $this->linesByGuid)) {
                    return Http::response(['error' => ['code' => 'BadRequest_NotFound']], 404);
                }

                return $this->linesByGuid[$guid] === null
                    ? Http::response('boom', 500)
                    : Http::response(['value' => $this->linesByGuid[$guid]]);
            },
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => self::ID,
            'documentApiId' => self::DOCUMENT_API_ID,
            'number' => 'CM104006',
            'returnOrderNumber' => '',
            'creditMemoDate' => '2026-07-28',
            'customerId' => '8e431cfe-a51d-f111-8340-7ced8d32d199',
            'customerName' => 'Animates - Head Office Account',
            'externalDocumentNumber' => 'RB01',
            'SystemCreatedAt' => '2026-07-28T02:13:58.163Z',
            'lastModifiedDateTime' => '2026-07-28T02:13:58.937Z',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function bcLine(): array
    {
        return [
            'id' => '4dab7a94-298a-f111-8072-7ced8da08480', 'documentId' => self::DOCUMENT_API_ID, 'sequence' => 10000,
            'lineType' => 'Account', 'itemId' => '00000000-0000-0000-0000-000000000000', 'lineObjectNumber' => '6931',
            'description' => 'Rebates - Contractual', 'quantity' => 1, 'unitPrice' => 100,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row, bool $force = false): void
    {
        (new ImportBcSalesCreditMemo($row, $force))->handle(
            app(SalesCreditMemoDetailFetcher::class),
            app(SalesInvoiceImporter::class),
            app(SalesInvoiceSyncLedger::class),
        );
    }

    public function test_the_lines_are_read_by_the_document_api_id_not_the_id(): void
    {
        $this->linesByGuid = [self::DOCUMENT_API_ID => [$this->bcLine()]];

        $this->import($this->row());

        $memo = SalesInvoice::first();

        $this->assertSame(SalesInvoiceKind::CreditMemo, $memo->kind());
        $this->assertSame(self::DOCUMENT_API_ID, $memo->document_api_id);
        $this->assertCount(1, $memo->lines());
        $this->assertSame('', $memo->lines()[0]['item_bc_id']);
        $this->assertSame('6931', $memo->lines()[0]['item_number']);

        Http::assertSent(function (Request $request): bool {
            if (str_contains($request->url(), '/salesCreditMemos(')) {
                $this->assertStringContainsString('salesCreditMemos('.self::DOCUMENT_API_ID.')/salesCreditMemoLines', $request->url());
            }

            return true;
        });
    }

    public function test_a_memo_without_a_document_api_id_reads_its_lines_by_its_id(): void
    {
        $this->linesByGuid = [self::ID => [$this->bcLine()]];

        $this->import($this->row(['documentApiId' => '']));

        $memo = SalesInvoice::first();

        $this->assertSame(self::ID, $memo->document_api_id);
        $this->assertCount(1, $memo->lines());
    }

    public function test_a_failed_line_read_imports_nothing(): void
    {
        $this->linesByGuid = [self::DOCUMENT_API_ID => null];

        try {
            $this->import($this->row());
            $this->fail('Expected the line read to throw.');
        } catch (BusinessCentralException) {
            // expected
        }

        $this->assertSame(0, SalesInvoice::count());
        Queue::assertNothingPushed();
    }

    public function test_a_new_memo_opens_a_delivery_on_the_invoice_entity(): void
    {
        $this->linesByGuid = [self::DOCUMENT_API_ID => [$this->bcLine()]];

        $this->import($this->row());

        $record = app(SalesInvoiceSyncLedger::class)->find(SalesInvoice::first());

        $this->assertNotNull($record);
        $this->assertSame('sales_invoice', $record->entity);
        $this->assertSame('credit_memo', $record->payload['status']);
        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 1);
    }

    public function test_an_unchanged_reimport_dispatches_nothing_once_delivered(): void
    {
        $this->linesByGuid = [self::DOCUMENT_API_ID => [$this->bcLine()]];
        $ledger = app(SalesInvoiceSyncLedger::class);

        $this->import($this->row());
        $ledger->markSynced($ledger->find(SalesInvoice::first()));

        $this->import($this->row());

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 1);
    }

    public function test_a_pending_memo_is_requeued_even_when_unchanged(): void
    {
        $this->linesByGuid = [self::DOCUMENT_API_ID => [$this->bcLine()]];

        $this->import($this->row());
        $this->import($this->row());

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 2);
    }

    public function test_force_dispatches_an_unchanged_memo(): void
    {
        $this->linesByGuid = [self::DOCUMENT_API_ID => [$this->bcLine()]];
        $ledger = app(SalesInvoiceSyncLedger::class);

        $this->import($this->row());
        $ledger->markSynced($ledger->find(SalesInvoice::first()));

        $this->import($this->row(), force: true);

        Queue::assertPushed(DeliverSalesInvoiceToWebsite::class, 2);
    }
}
