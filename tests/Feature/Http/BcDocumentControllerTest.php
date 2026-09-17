<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The signed routes that stream attachments and rendered PDFs out of
 * Business Central.
 *
 * The invoice cases are the point of this file: the id the website holds does
 * not always address the document in the standard API, and the fallback that
 * rescues those invoices has no other coverage.
 */
class BcDocumentControllerTest extends TestCase
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const STANDARD_BASE = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/v2.0/companies(company-guid)';

    private const CUSTOM_BASE = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)';

    private const DOC_ID = '11111111-1111-1111-1111-111111111111';

    private const RESOLVED_ID = '22222222-2222-2222-2222-222222222222';

    private const MEDIA_URL = 'https://bc.example/media/document-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com',
            'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc',
            'client_secret' => 'secret-abc',
            'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid',
            'api_version' => 'v2.0',
            'http_timeout' => 30,
            'http_connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
    }

    public function test_streams_an_attachment_as_a_pdf_under_its_own_filename(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/documentAttachments('.self::DOC_ID.')' => Http::response([
                'fileName' => 'datasheet.pdf',
                'attachmentContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response('%PDF-1.4 bytes', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->get($this->signed('/bc-doc/'.self::DOC_ID));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'inline; filename="datasheet.pdf"');
        $this->assertSame('%PDF-1.4 bytes', $response->getContent());
    }

    public function test_serves_an_image_attachment_with_its_own_content_type(): void
    {
        // An image sent as application/pdf renders as a broken document.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/documentAttachments('.self::DOC_ID.')' => Http::response([
                'fileName' => 'fitment.PNG',
                'attachmentContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->get($this->signed('/bc-doc/'.self::DOC_ID));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_answers_404_when_an_attachment_has_no_content(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/documentAttachments('.self::DOC_ID.')' => Http::response([
                'fileName' => 'gone.pdf',
            ]),
        ]);

        $this->get($this->signed('/bc-doc/'.self::DOC_ID))->assertNotFound();
    }

    public function test_streams_an_invoice_pdf_addressed_by_the_id_the_website_holds(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/salesInvoices('.self::DOC_ID.')/pdfDocument' => Http::response([
                'pdfDocumentContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response('%PDF invoice', 200),
        ]);

        $response = $this->get($this->signed('/bc-sales-invoice-pdf/'.self::DOC_ID));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Cache-Control', 'max-age=3600, private');
        $this->assertSame('%PDF invoice', $response->getContent());
    }

    public function test_resolves_a_draft_posted_invoice_through_its_number(): void
    {
        // An invoice posted from a draft is keyed in the standard API by the
        // draft's id, so the direct lookup finds nothing and the document has
        // to be re-addressed via its number.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/salesInvoices('.self::DOC_ID.')/pdfDocument' => Http::response([]),
            self::CUSTOM_BASE.'/postedSalesInvoicesExt('.self::DOC_ID.')*' => Http::response([
                'number' => 'INV-1001',
            ]),
            self::STANDARD_BASE.'/salesInvoices?*' => Http::response([
                'value' => [['id' => self::RESOLVED_ID]],
            ]),
            self::STANDARD_BASE.'/salesInvoices('.self::RESOLVED_ID.')/pdfDocument' => Http::response([
                'pdfDocumentContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response('%PDF resolved', 200),
        ]);

        $response = $this->get($this->signed('/bc-sales-invoice-pdf/'.self::DOC_ID));

        $response->assertOk();
        $this->assertSame('%PDF resolved', $response->getContent());
    }

    public function test_answers_404_when_an_invoice_pdf_cannot_be_resolved_at_all(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/salesInvoices('.self::DOC_ID.')/pdfDocument' => Http::response([]),
            self::CUSTOM_BASE.'/postedSalesInvoicesExt('.self::DOC_ID.')*' => Http::response('nope', 404),
        ]);

        $this->get($this->signed('/bc-sales-invoice-pdf/'.self::DOC_ID))->assertNotFound();
    }

    public function test_addresses_a_credit_memo_pdf_by_its_document_api_id(): void
    {
        // The posted page's own id is not the GUID the standard entity answers
        // to, so asking by it would 404 for some memos.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOM_BASE.'/postedSalesCreditMemosExt('.self::DOC_ID.')*' => Http::response([
                'id' => self::DOC_ID,
                'documentApiId' => self::RESOLVED_ID,
            ]),
            self::STANDARD_BASE.'/salesCreditMemos('.self::RESOLVED_ID.')/pdfDocument' => Http::response([
                'pdfDocumentContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response('%PDF memo', 200),
        ]);

        $response = $this->get($this->signed('/bc-credit-memo-pdf/'.self::DOC_ID));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'inline; filename="credit-memo-'.self::DOC_ID.'.pdf"');
        $this->assertSame('%PDF memo', $response->getContent());
    }

    public function test_falls_back_to_the_requested_id_when_a_credit_memo_lookup_fails(): void
    {
        // A failed translation must not cost a memo whose ids do agree.
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOM_BASE.'/postedSalesCreditMemosExt('.self::DOC_ID.')*' => Http::response('nope', 404),
            self::STANDARD_BASE.'/salesCreditMemos('.self::DOC_ID.')/pdfDocument' => Http::response([
                'pdfDocumentContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response('%PDF memo', 200),
        ]);

        $this->get($this->signed('/bc-credit-memo-pdf/'.self::DOC_ID))->assertOk();
    }

    public function test_rejects_an_unsigned_document_request(): void
    {
        $this->get('/bc-doc/'.self::DOC_ID)->assertForbidden();
        $this->get('/bc-sales-invoice-pdf/'.self::DOC_ID)->assertForbidden();
        $this->get('/bc-credit-memo-pdf/'.self::DOC_ID)->assertForbidden();
    }

    /**
     * A URL signed the way the WordPress site signs one.
     */
    private function signed(string $path, int $uid = 7): string
    {
        $expires = time() + 300;
        $signature = hash_hmac(
            'sha256',
            $path."\n".$uid."\n".$expires,
            (string) env('BC_ROUTE_SECRET'),
        );

        return $path.'?uid='.$uid.'&expires='.$expires.'&sig='.$signature;
    }
}
