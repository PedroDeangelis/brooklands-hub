<?php

namespace Tests\Feature\Website;

use App\Website\WebsiteConfigurationException;
use App\Website\WebsiteSigner;
use Tests\TestCase;

/**
 * Producing the signature the website verifies.
 */
class WebsiteSignerTest extends TestCase
{
    private const SECRET = 'shared-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.website.secret', self::SECRET);
    }

    private function signer(): WebsiteSigner
    {
        return app(WebsiteSigner::class);
    }

    public function test_the_signature_is_hmac_sha256_over_timestamp_dot_body(): void
    {
        $raw = '{"action":"upsert"}';

        $this->assertSame(
            hash_hmac('sha256', '1700000000.'.$raw, self::SECRET),
            $this->signer()->sign($raw, 1700000000),
        );
    }

    public function test_the_headers_carry_the_timestamp_and_signature(): void
    {
        $headers = $this->signer()->headers('{"a":1}', 1700000000);

        $this->assertSame('1700000000', $headers[WebsiteSigner::HEADER_TIMESTAMP]);
        $this->assertSame(
            $this->signer()->sign('{"a":1}', 1700000000),
            $headers[WebsiteSigner::HEADER_SIGNATURE],
        );
    }

    /**
     * The timestamp is part of the signed message, so the same body signed a
     * second later is a different signature. That is what bounds replay.
     */
    public function test_the_same_body_signs_differently_at_a_different_time(): void
    {
        $signer = $this->signer();

        $this->assertNotSame($signer->sign('{"a":1}', 1700000000), $signer->sign('{"a":1}', 1700000001));
    }

    public function test_a_different_body_signs_differently(): void
    {
        $signer = $this->signer();

        $this->assertNotSame($signer->sign('{"a":1}', 1700000000), $signer->sign('{"a":2}', 1700000000));
    }

    public function test_a_different_secret_signs_differently(): void
    {
        $mine = $this->signer()->sign('{"a":1}', 1700000000);

        config()->set('services.website.secret', 'a-different-secret');

        $this->assertNotSame($mine, $this->signer()->sign('{"a":1}', 1700000000));
    }

    public function test_slashes_are_not_escaped_so_the_bytes_read_as_written(): void
    {
        $encoded = $this->signer()->encode(['url' => 'https://example.test/a/b']);

        $this->assertSame('{"url":"https://example.test/a/b"}', $encoded);
    }

    public function test_unicode_survives_encoding(): void
    {
        $encoded = $this->signer()->encode(['name' => 'Chrome Tap – 2 way']);

        $this->assertStringContainsString('–', $encoded);
    }

    /**
     * Signing one encoding and sending another produces a request that always
     * fails verification, so the bytes must come from here.
     */
    public function test_the_encoded_bytes_are_what_gets_signed(): void
    {
        $signer = $this->signer();
        $raw = $signer->encode(['action' => 'upsert', 'bc_id' => 'abc']);

        $headers = $signer->headers($raw, 1700000000);

        $this->assertSame(
            hash_hmac('sha256', '1700000000.'.$raw, self::SECRET),
            $headers[WebsiteSigner::HEADER_SIGNATURE],
        );
    }

    public function test_a_missing_secret_is_refused_rather_than_signed_with_nothing(): void
    {
        config()->set('services.website.secret', '');

        $this->expectException(WebsiteConfigurationException::class);

        $this->signer()->sign('{"a":1}', 1700000000);
    }

    /**
     * The secret must not travel in an exception message, which is the sort of
     * thing that reaches a log or an error page.
     */
    public function test_the_secret_never_appears_in_the_configuration_error(): void
    {
        config()->set('services.website.secret', '');

        try {
            $this->signer()->sign('{"a":1}', 1700000000);
            $this->fail('expected a configuration exception');
        } catch (WebsiteConfigurationException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringContainsString('services.website.secret', $e->getMessage());
        }
    }
}
