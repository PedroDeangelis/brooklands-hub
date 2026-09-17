<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The signed routes that stream pictures out of Business Central.
 *
 * These are the one part of this application a public visitor's browser
 * touches, so the cases cover what the browser is actually handed — the bytes,
 * the content type and the caching headers — and not merely a 200.
 */
class BcImageControllerTest extends TestCase
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const STANDARD_BASE = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/v2.0/companies(company-guid)';

    private const CUSTOM_BASE = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)';

    private const ITEM_ID = '11111111-1111-1111-1111-111111111111';

    private const MEDIA_URL = 'https://bc.example/media/picture-bytes';

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

        // The cache is a real local disk in production; faking it keeps each
        // test's cache empty rather than inheriting the last run's files.
        Storage::fake('local');
    }

    public function test_streams_an_item_picture_and_caches_it_on_disk(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n".random_bytes(16);

        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/items('.self::ITEM_ID.')/picture' => Http::response([
                '@odata.etag' => 'W/"JzQ0O3c="',
                'contentType' => 'image/png',
                'pictureContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response($bytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->get($this->signed('/bc-image/'.self::ITEM_ID));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Cache-Control', 'max-age=86400, public');
        $this->assertSame($bytes, $response->getContent());

        // Cached under the etag, so a replaced picture becomes a different file
        // rather than an overwrite and no stale entry can be served.
        Storage::disk('local')->assertExists('bc-images/'.self::ITEM_ID.'-WJzQ0O3c.png');
    }

    public function test_serves_a_cached_item_picture_without_transferring_the_bytes_again(): void
    {
        Storage::disk('local')->put('bc-images/'.self::ITEM_ID.'-WJzQ0O3c.png', 'cached-bytes');

        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/items('.self::ITEM_ID.')/picture' => Http::response([
                '@odata.etag' => 'W/"JzQ0O3c="',
                'contentType' => 'image/png',
                'pictureContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
        ]);

        $response = $this->get($this->signed('/bc-image/'.self::ITEM_ID));

        $response->assertOk();
        $this->assertSame('cached-bytes', $response->streamedContent());

        // The metadata read still happens — it is what supplies the etag — but
        // the bytes must not be fetched a second time.
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::MEDIA_URL);
    }

    public function test_answers_404_when_an_item_has_no_picture(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/items('.self::ITEM_ID.')/picture' => Http::response([
                '@odata.etag' => 'W/"JzQ0O3c="',
            ]),
        ]);

        $this->get($this->signed('/bc-image/'.self::ITEM_ID))->assertNotFound();
    }

    public function test_streams_a_customer_picture(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::STANDARD_BASE.'/customers('.self::ITEM_ID.')/picture' => Http::response([
                '@odata.etag' => 'W/"abc="',
                'contentType' => 'image/jpeg',
                'pictureContent@odata.mediaReadLink' => self::MEDIA_URL,
            ]),
            self::MEDIA_URL => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $response = $this->get($this->signed('/bc-customer-image/'.self::ITEM_ID));

        $response->assertOk();
        $this->assertSame('jpeg-bytes', $response->getContent());

        // A non-PNG content type must land under .jpg, in its own directory.
        Storage::disk('local')->assertExists('bc-customer-images/'.self::ITEM_ID.'-Wabc.jpg');
    }

    public function test_decodes_a_base64_salesperson_image_and_sniffs_its_type(): void
    {
        // This page returns the picture inline as base64 rather than as a media
        // stream, so there is no mediaReadLink to follow.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAAAAAAALAAAAAABAAEAAAIBRAA7', true);

        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOM_BASE.'/salespersonImages('.self::ITEM_ID.')' => Http::response([
                'imageBase64' => base64_encode($gif),
            ]),
        ]);

        $response = $this->get($this->signed('/bc-salesperson-image/'.self::ITEM_ID));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/gif');
        $this->assertSame($gif, $response->getContent());
    }

    public function test_strips_a_data_uri_prefix_from_a_salesperson_image(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAAAAAAALAAAAAABAAEAAAIBRAA7', true);

        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOM_BASE.'/salespersonImages('.self::ITEM_ID.')' => Http::response([
                'imageBase64' => 'data:image/gif;base64,'.base64_encode($gif),
            ]),
        ]);

        $response = $this->get($this->signed('/bc-salesperson-image/'.self::ITEM_ID));

        $response->assertOk();
        $this->assertSame($gif, $response->getContent());
    }

    public function test_answers_404_when_a_salesperson_has_no_image(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::CUSTOM_BASE.'/salespersonImages('.self::ITEM_ID.')' => Http::response(['id' => 'x']),
        ]);

        $this->get($this->signed('/bc-salesperson-image/'.self::ITEM_ID))->assertNotFound();
    }

    public function test_rejects_an_unsigned_request(): void
    {
        // The middleware is the only thing standing in front of these routes,
        // so an unsigned URL must never reach Business Central at all.
        Http::preventStrayRequests();

        $this->get('/bc-image/'.self::ITEM_ID)->assertForbidden();
    }

    public function test_rejects_a_tampered_signature(): void
    {
        Http::preventStrayRequests();

        $url = $this->signed('/bc-image/'.self::ITEM_ID).'0';

        $this->get($url)->assertForbidden();
    }

    /**
     * A URL signed the way the WordPress site signs one.
     *
     * Derived here rather than hard-coded so a change to the signing scheme
     * fails in the middleware's own test rather than silently here.
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
