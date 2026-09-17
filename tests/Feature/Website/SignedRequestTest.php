<?php

namespace Tests\Feature\Website;

use App\Sync\Payload\DeliveryPlan;
use App\Sync\Payload\PayloadDiff;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use App\Website\WebsiteRequest;
use App\Website\WebsiteSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What actually goes over the wire, and whether the receiver could verify it.
 *
 * Each case re-derives the signature the way the WordPress endpoint does, from
 * the bytes the request carried, so a mismatch between what is signed and what
 * is sent would fail here rather than in production.
 */
class SignedRequestTest extends TestCase
{
    private const ENDPOINT = 'https://website.test/wp-json/horizon/v2/products';

    private const SECRET = 'shared-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.website', [
            'url' => self::ENDPOINT,
            'secret' => self::SECRET,
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * Send one envelope and return the request that went out.
     *
     * Built through DeliveryPlan so the shapes under test are the ones
     * production actually produces.
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(WebsiteAction $action, array $payload, ?array $delivered = null): Request
    {
        $plan = DeliveryPlan::make($action, $payload, $delivered, 'hash');

        app(WebsiteClient::class)->deliver(WebsiteRequest::fromPlan($plan));

        $sent = null;
        Http::assertSent(function (Request $request) use (&$sent): bool {
            $sent = $request;

            return true;
        });

        return $sent;
    }

    /**
     * A complete website payload, as the builder produces one.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'bc_id' => 'ab3349b2',
            'sku' => 'POLY1',
            'price' => 10,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function removePayload(): array
    {
        return ['bc_id' => 'ab3349b2', 'sku' => 'AA27', 'reasons' => ['not_finished_goods']];
    }

    /**
     * Verify a captured request exactly as the WordPress endpoint does.
     */
    private function verifies(Request $request): bool
    {
        $timestamp = $request->header(WebsiteSigner::HEADER_TIMESTAMP)[0] ?? '';
        $signature = $request->header(WebsiteSigner::HEADER_SIGNATURE)[0] ?? '';

        return hash_equals(
            hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET),
            $signature,
        );
    }

    public function test_a_full_request_is_signed_over_the_bytes_it_carries(): void
    {
        $request = $this->send(WebsiteAction::Upsert, $this->fullPayload());

        $this->assertTrue($this->verifies($request));
        $this->assertSame(
            '{"action":"upsert","mode":"full","bc_id":"ab3349b2","payload":{"bc_id":"ab3349b2","sku":"POLY1","price":10}}',
            $request->body(),
        );
    }

    public function test_a_partial_request_is_signed(): void
    {
        $request = $this->send(
            WebsiteAction::Upsert,
            $this->fullPayload(['price' => 18.08]),
            $this->fullPayload(),
        );

        $this->assertTrue($this->verifies($request));
        $this->assertStringContainsString('"mode":"partial"', $request->body());
        $this->assertStringContainsString('"changes":{"price":18.08}', $request->body());
    }

    public function test_a_remove_request_is_signed(): void
    {
        $request = $this->send(WebsiteAction::Remove, $this->removePayload());

        $this->assertTrue($this->verifies($request));
        $this->assertStringContainsString('"action":"remove"', $request->body());
    }

    public function test_both_signature_headers_are_present(): void
    {
        $request = $this->send(WebsiteAction::Remove, $this->removePayload());

        $this->assertNotEmpty($request->header(WebsiteSigner::HEADER_TIMESTAMP));
        $this->assertNotEmpty($request->header(WebsiteSigner::HEADER_SIGNATURE));
        $this->assertSame(64, strlen($request->header(WebsiteSigner::HEADER_SIGNATURE)[0]));
    }

    public function test_the_request_is_sent_as_json(): void
    {
        $request = $this->send(WebsiteAction::Remove, $this->removePayload());

        $this->assertStringContainsString('application/json', $request->header('Content-Type')[0] ?? '');
    }

    /**
     * Altering a single byte must break verification, or the signature is not
     * protecting the body.
     */
    public function test_a_tampered_body_does_not_verify(): void
    {
        $request = $this->send(
            WebsiteAction::Upsert,
            $this->fullPayload(['price' => 10]),
            $this->fullPayload(['price' => 5]),
        );

        $timestamp = $request->header(WebsiteSigner::HEADER_TIMESTAMP)[0];
        $tampered = str_replace('10', '99', $request->body());

        $this->assertFalse(hash_equals(
            hash_hmac('sha256', $timestamp.'.'.$tampered, self::SECRET),
            $request->header(WebsiteSigner::HEADER_SIGNATURE)[0],
        ));
    }

    public function test_the_secret_is_never_sent_in_a_header_or_body(): void
    {
        $request = $this->send(WebsiteAction::Remove, $this->removePayload());

        $this->assertStringNotContainsString(self::SECRET, $request->body());

        foreach ($request->headers() as $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString(self::SECRET, (string) $value);
            }
        }
    }

    public function test_the_timestamp_is_current(): void
    {
        $request = $this->send(WebsiteAction::Remove, $this->removePayload());

        $this->assertLessThanOrEqual(
            5,
            abs(time() - (int) $request->header(WebsiteSigner::HEADER_TIMESTAMP)[0]),
        );
    }

    public function test_the_diff_helper_is_unused_here_but_the_envelope_shapes_match(): void
    {
        // Guards the three shapes the receiver validates against drifting apart
        // from the ones DeliveryPlan produces.
        $this->assertSame('full', WebsiteRequest::MODE_FULL);
        $this->assertSame('partial', WebsiteRequest::MODE_PARTIAL);
        $this->assertTrue(PayloadDiff::none()->isEmpty());
    }
}
