<?php

namespace Tests\Feature\Http;

use App\Contacts\ContactExclusionReason;
use App\Contacts\ContactFilter;
use App\Models\Contact;
use App\Models\Customer;
use App\Sync\ContactSyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The contact list and detail pages.
 */
class ContactControllerTest extends TestCase
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

    // ------------------------------------------------------------- the list

    public function test_the_list_renders(): void
    {
        Contact::factory()->eligible()->create(['number' => 'CT000001', 'display_name' => 'Jane Doe']);
        $this->websiteHolds([]);

        $this->get(route('contacts.index'))
            ->assertOk()
            ->assertSee('CT000001')
            ->assertSee('Jane Doe')
            ->assertSee('Eligible');
    }

    public function test_the_list_shows_every_contact_whatever_its_state(): void
    {
        Contact::factory()->eligible()->create(['number' => 'CT-OK']);
        Contact::factory()->withoutEmail()->create(['number' => 'CT-NOMAIL']);
        $this->websiteHolds([]);

        $this->get(route('contacts.index'))->assertOk()->assertSee('CT-OK')->assertSee('CT-NOMAIL');
    }

    public function test_an_excluded_contact_shows_its_first_reason(): void
    {
        Contact::factory()->withoutEmail()->create(['number' => 'CT-NOMAIL', 'customer_bc_id' => null]);
        $this->websiteHolds([]);

        $this->get(route('contacts.index'))
            ->assertOk()
            ->assertSee('Excluded')
            ->assertSee('no email address')
            ->assertSee('+1 more');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get(route('contacts.index'))->assertOk()->assertSee('No contacts have been imported yet');
    }

    public function test_the_linked_customer_is_shown(): void
    {
        $customer = Customer::factory()->create(['number' => 'BROOKLAN']);
        Contact::factory()->linkedTo($customer)->create();
        $this->websiteHolds([]);

        $this->get(route('contacts.index'))->assertOk()->assertSee('BROOKLAN')->assertSee(route('customers.show', $customer));
    }

    // -------------------------------------------------------------- filters

    public function test_the_website_filter_agrees_with_the_badge(): void
    {
        Contact::factory()->eligible()->create(['number' => 'CT-OK']);
        Contact::factory()->withoutEmail()->create(['number' => 'CT-NOMAIL']);
        $this->websiteHolds([]);

        $this->get(route('contacts.index', [ContactFilter::PARAM_WEBSITE => ContactFilter::WEBSITE_ELIGIBLE]))
            ->assertOk()->assertSee('CT-OK')->assertDontSee('CT-NOMAIL');

        $this->get(route('contacts.index', [ContactFilter::PARAM_WEBSITE => ContactFilter::WEBSITE_EXCLUDED]))
            ->assertOk()->assertSee('CT-NOMAIL')->assertDontSee('CT-OK');
    }

    public function test_the_reason_filter_narrows_to_that_reason(): void
    {
        Contact::factory()->eligible()->privacyBlocked()->create(['number' => 'CT-PRIV']);
        Contact::factory()->eligible()->withoutEmail()->create(['number' => 'CT-NOMAIL']);
        $this->websiteHolds([]);

        $this->get(route('contacts.index', [ContactFilter::PARAM_REASON => ContactExclusionReason::PrivacyBlocked->value]))
            ->assertOk()->assertSee('CT-PRIV')->assertDontSee('CT-NOMAIL');
    }

    public function test_the_search_filter_matches_number_name_or_email(): void
    {
        Contact::factory()->create(['number' => 'CT000001', 'display_name' => 'Jane Doe', 'email' => 'jane@example.com']);
        Contact::factory()->create(['number' => 'CT000002', 'display_name' => 'John Roe', 'email' => 'john@example.com']);
        $this->websiteHolds([]);

        $this->get(route('contacts.index', [ContactFilter::PARAM_SEARCH => 'jane@']))
            ->assertOk()->assertSee('CT000001')->assertDontSee('CT000002');
    }

    public function test_the_not_synced_filter_finds_contacts_with_no_ledger_row(): void
    {
        $synced = Contact::factory()->eligible()->create(['number' => 'CT-SYNCED']);
        Contact::factory()->eligible()->create(['number' => 'CT-NEW']);
        $ledger = app(ContactSyncLedger::class);
        $ledger->markSynced($ledger->markPending($synced, []));
        $this->websiteHolds([]);

        $this->get(route('contacts.index', [ContactFilter::PARAM_SYNC => ContactFilter::SYNC_NOT_SYNCED]))
            ->assertOk()->assertSee('CT-NEW')->assertDontSee('CT-SYNCED');
    }

    public function test_an_unrecognised_filter_value_is_ignored(): void
    {
        Contact::factory()->create(['number' => 'CT000001']);
        $this->websiteHolds([]);

        $this->get(route('contacts.index', [ContactFilter::PARAM_WEBSITE => 'bogus', ContactFilter::PARAM_REASON => 'nope']))
            ->assertOk()->assertSee('CT000001');
    }

    // ------------------------------------------------------------- the detail

    public function test_the_detail_page_renders(): void
    {
        $contact = Contact::factory()->eligible()->create(['number' => 'CT000001', 'display_name' => 'Jane Doe']);
        $this->websiteHolds([]);

        $this->get(route('contacts.show', $contact))
            ->assertOk()
            ->assertSee('Jane Doe')
            ->assertSee('Website eligibility')
            ->assertSee('Rules checked')
            ->assertSee('Next delivery')
            ->assertSee('21 McGiven Drive');
    }

    public function test_the_detail_page_lists_every_failed_rule(): void
    {
        $contact = Contact::factory()->privacyBlocked()->withoutEmail()->create(['customer_bc_id' => null]);
        $this->websiteHolds([]);

        $this->get(route('contacts.show', $contact))
            ->assertOk()
            ->assertSee('privacy blocked')
            ->assertSee('no email address')
            ->assertSee('not linked to a customer')
            ->assertSee('does not qualify, so nothing below will be sent');
    }

    public function test_the_detail_page_never_shows_a_removal(): void
    {
        $contact = Contact::factory()->privacyBlocked()->create();
        $this->websiteHolds([]);

        $this->get(route('contacts.show', $contact))->assertOk()->assertDontSee('"action": "remove"');
    }

    public function test_an_unknown_contact_is_not_found(): void
    {
        $this->get('/contact/999999')->assertNotFound();
    }

    public function test_the_contacts_link_is_in_the_sidebar(): void
    {
        $this->get(route('products.index'))->assertOk()->assertSee(route('contacts.index'));
    }

    // ------------------------------------------- what the website actually holds

    public function test_a_delivered_contact_absent_from_the_website_is_flagged(): void
    {
        $contact = Contact::factory()->eligible()->create(['number' => 'CT000001']);
        $ledger = app(ContactSyncLedger::class);
        $ledger->markSynced($ledger->markPending($contact, []));
        $this->websiteHolds([]);

        $this->get(route('contacts.show', $contact))
            ->assertOk()
            ->assertSee('Not on the website')
            ->assertSee('website:deliver-contacts --number=CT000001 --send');
    }

    /**
     * A never-delivered excluded contact is expected to be absent, and must
     * not be flagged as missing.
     */
    public function test_an_excluded_contact_that_was_never_delivered_is_not_flagged_as_missing(): void
    {
        $contact = Contact::factory()->withoutEmail()->create();
        $this->websiteHolds([]);

        $this->get(route('contacts.show', $contact))->assertOk()->assertDontSee('Not on the website');
    }

    /**
     * The one deliberate contradiction: on the site, but no longer qualifies.
     * Shown, never resolved by deleting the user.
     */
    public function test_an_excluded_contact_the_website_holds_is_called_out(): void
    {
        $contact = Contact::factory()->privacyBlocked()->create();
        $this->websiteHolds([$contact->bc_id => ['wp_id' => 77, 'status' => 'customer']]);

        $this->get(route('contacts.show', $contact))
            ->assertOk()
            ->assertSee('no longer qualifies')
            ->assertSee('User 77');
    }

    public function test_a_contact_the_website_holds_is_not_flagged(): void
    {
        $contact = Contact::factory()->eligible()->create();
        $this->websiteHolds([$contact->bc_id => ['wp_id' => 77, 'status' => 'customer']]);

        $this->get(route('contacts.show', $contact))->assertOk()->assertDontSee('Not on the website')->assertSee('user 77');
    }

    public function test_an_unreachable_website_reads_as_unknown_rather_than_missing(): void
    {
        $contact = Contact::factory()->eligible()->create();
        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('contacts.show', $contact))->assertOk()->assertDontSee('Not on the website')->assertSee('could not be reached');
    }

    public function test_the_list_asks_the_website_once(): void
    {
        Contact::factory()->count(4)->create();
        $this->websiteHolds([]);

        $this->get(route('contacts.index'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_list_still_renders_when_the_website_is_unreachable(): void
    {
        Contact::factory()->create(['number' => 'CT000001']);
        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('contacts.index'))->assertOk()->assertSee('CT000001')->assertSee('unknown');
    }
}
