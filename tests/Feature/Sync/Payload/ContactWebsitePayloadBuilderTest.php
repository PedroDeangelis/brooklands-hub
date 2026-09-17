<?php

namespace Tests\Feature\Sync\Payload;

use App\Models\Contact;
use App\Sync\Payload\ContactWebsitePayloadBuilder;
use App\Sync\Payload\PayloadDiff;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The payload the website is given for a contact.
 *
 * The important test here is the pinned key list. The payload has no way to
 * ask the website to send an email, and this is what stops one being added.
 */
class ContactWebsitePayloadBuilderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function builder(): ContactWebsitePayloadBuilder
    {
        return app(ContactWebsitePayloadBuilder::class);
    }

    public function test_the_payload_carries_exactly_these_keys_and_nothing_that_could_send_mail(): void
    {
        $payload = $this->builder()->build(Contact::factory()->create());

        $this->assertSame(
            ['bc_id', 'billing', 'customer_bc_id', 'display_name', 'email', 'first_name', 'mobile', 'number', 'phone'],
            collect(array_keys($payload))->sort()->values()->all(),
        );

        foreach (array_keys($payload) as $key) {
            $this->assertDoesNotMatchRegularExpression('/notif|welcome|send|password|wp_mail/i', $key);
        }

        $this->assertSame(
            ['address_1', 'address_2', 'city', 'country', 'first_name', 'postcode', 'state'],
            collect(array_keys($payload['billing']))->sort()->values()->all(),
        );
    }

    public function test_the_email_is_lowercased_and_trimmed(): void
    {
        $payload = $this->builder()->build(Contact::factory()->make(['email' => ' Jane@Example.COM ']));

        $this->assertSame('jane@example.com', $payload['email']);
    }

    public function test_the_first_name_falls_back_when_the_contact_has_no_name(): void
    {
        $payload = $this->builder()->build(Contact::factory()->make(['display_name' => '']));

        $this->assertSame('', $payload['display_name']);
        $this->assertSame(ContactWebsitePayloadBuilder::FALLBACK_FIRST_NAME, $payload['first_name']);
    }

    public function test_the_first_name_is_the_display_name_when_there_is_one(): void
    {
        $payload = $this->builder()->build(Contact::factory()->make(['display_name' => 'Jane Doe']));

        $this->assertSame('Jane Doe', $payload['first_name']);
        $this->assertSame('Jane Doe', $payload['billing']['first_name']);
    }

    /**
     * Replicated from the legacy upserter and flagged in the plan.
     */
    public function test_every_contact_carries_the_head_office_billing_address(): void
    {
        $payload = $this->builder()->build(Contact::factory()->make());

        $this->assertSame('21 McGiven Drive', $payload['billing']['address_1']);
        $this->assertSame('New Plymouth', $payload['billing']['city']);
        $this->assertSame('TKI', $payload['billing']['state']);
        $this->assertSame('4371', $payload['billing']['postcode']);
        $this->assertSame('NZ', $payload['billing']['country']);
    }

    public function test_the_billing_block_is_atomic(): void
    {
        $this->assertTrue(PayloadDiff::isAtomic('billing'));
    }

    public function test_an_unlinked_contact_sends_an_empty_customer_id(): void
    {
        $payload = $this->builder()->build(Contact::factory()->make(['customer_bc_id' => null]));

        $this->assertSame('', $payload['customer_bc_id']);
    }
}
