<?php

namespace Tests\Feature\Contacts;

use App\Contacts\ContactEligibility;
use App\Contacts\ContactExclusionReason;
use App\Models\Contact;
use App\Models\Customer;
use App\Sync\ContactSyncLedger;
use App\Sync\DeliveryStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Which contacts qualify to become website users, and why not.
 *
 * Every rule exists twice, per contact and as SQL, and the last test proves
 * the two select the same contacts over a fixture that fails every rule at
 * least once.
 */
class ContactEligibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function eligibility(): ContactEligibility
    {
        return app(ContactEligibility::class);
    }

    private function customerWithShipTo(): Customer
    {
        return Customer::factory()->withShippingAddresses([
            ['bc_id' => fake()->uuid(), 'code' => 'MAIN', 'city' => 'New Plymouth'],
        ])->create();
    }

    // ---------------------------------------------------------- each rule

    public function test_a_contact_passing_every_rule_is_eligible(): void
    {
        $contact = Contact::factory()->eligible()->create();

        $result = $this->eligibility()->for($contact);

        $this->assertTrue($result->eligible);
        $this->assertSame([], $result->reasons());
    }

    public function test_not_user_level(): void
    {
        $contact = Contact::factory()->eligible()->notUserLevel('MANAGER')->create();

        $result = $this->eligibility()->for($contact);

        $this->assertTrue($result->hasReason(ContactExclusionReason::NotUserLevel));
        $this->assertStringContainsString('"MANAGER"', $result->reason());
    }

    public function test_the_level_rule_ignores_case_and_whitespace(): void
    {
        $contact = Contact::factory()->eligible()->create(['organisational_level_code' => ' user ']);

        $this->assertTrue($this->eligibility()->isEligible($contact));
    }

    public function test_not_customer_relation(): void
    {
        $contact = Contact::factory()->eligible()->notCustomerRelation('Vendor')->create();

        $this->assertTrue($this->eligibility()->for($contact)->hasReason(ContactExclusionReason::NotCustomerRelation));
    }

    public function test_privacy_blocked(): void
    {
        $contact = Contact::factory()->eligible()->privacyBlocked()->create();

        $this->assertTrue($this->eligibility()->for($contact)->hasReason(ContactExclusionReason::PrivacyBlocked));
    }

    public function test_no_email(): void
    {
        $contact = Contact::factory()->eligible()->withoutEmail()->create();

        $this->assertTrue($this->eligibility()->for($contact)->hasReason(ContactExclusionReason::NoEmail));
    }

    public function test_no_customer_when_unlinked(): void
    {
        $contact = Contact::factory()->create(['customer_bc_id' => null]);

        $result = $this->eligibility()->for($contact);

        $this->assertTrue($result->hasReason(ContactExclusionReason::NoCustomer));
        $this->assertSame('Contact is not linked to a customer', $result->reason());
    }

    public function test_no_customer_when_the_linked_customer_has_not_been_imported(): void
    {
        $contact = Contact::factory()->create(['customer_bc_id' => '136ebb7f-791e-f111-8340-7ced8d3493eb']);

        $result = $this->eligibility()->for($contact);

        $this->assertTrue($result->hasReason(ContactExclusionReason::NoCustomer));
        $this->assertStringContainsString('has not been imported', $result->reason());
    }

    /**
     * The legacy gate, now visible instead of a silent defer.
     */
    public function test_customer_has_no_shipping_address(): void
    {
        $customer = Customer::factory()->create(['number' => 'NOSHIP', 'shipping_addresses' => []]);
        $contact = Contact::factory()->linkedTo($customer)->create();

        $result = $this->eligibility()->for($contact);

        $this->assertFalse($result->eligible);
        $this->assertTrue($result->hasReason(ContactExclusionReason::CustomerHasNoShippingAddress));
        $this->assertSame('Customer NOSHIP has no ship-to address', $result->reason());
    }

    public function test_a_null_address_list_counts_as_none(): void
    {
        $customer = Customer::factory()->create(['shipping_addresses' => null]);
        $contact = Contact::factory()->linkedTo($customer)->create();

        $this->assertTrue($this->eligibility()->for($contact)->hasReason(ContactExclusionReason::CustomerHasNoShippingAddress));
    }

    public function test_every_failed_rule_is_reported_not_just_the_first(): void
    {
        $contact = Contact::factory()->privacyBlocked()->withoutEmail()->create(['customer_bc_id' => null]);

        $result = $this->eligibility()->for($contact);

        $this->assertSame(3, $result->reasonCount());
        $this->assertTrue($result->hasReason(ContactExclusionReason::PrivacyBlocked));
        $this->assertTrue($result->hasReason(ContactExclusionReason::NoEmail));
        $this->assertTrue($result->hasReason(ContactExclusionReason::NoCustomer));
    }

    // ------------------------------------------------------ duplicate email

    public function test_the_lowest_number_wins_a_shared_email(): void
    {
        $customer = $this->customerWithShipTo();
        $winner = Contact::factory()->linkedTo($customer)->create(['number' => 'CT000100', 'email' => 'shared@example.com']);
        $loser = Contact::factory()->linkedTo($customer)->create(['number' => 'CT000200', 'email' => 'shared@example.com']);

        $this->assertTrue($this->eligibility()->isEligible($winner));

        $result = $this->eligibility()->for($loser);

        $this->assertFalse($result->eligible);
        $this->assertTrue($result->hasReason(ContactExclusionReason::DuplicateEmail));
        $this->assertSame('Email address is already taken by contact CT000100, which has the lower number', $result->reason());
    }

    /**
     * Creation order must not matter: whichever contact arrived first, the
     * lower number wins.
     */
    public function test_the_winner_is_the_same_whichever_order_they_were_imported_in(): void
    {
        $customer = $this->customerWithShipTo();
        $later = Contact::factory()->linkedTo($customer)->create(['number' => 'CT000200', 'email' => 'shared@example.com']);
        $earlier = Contact::factory()->linkedTo($customer)->create(['number' => 'CT000100', 'email' => 'shared@example.com']);

        $this->assertTrue($this->eligibility()->isEligible($earlier));
        $this->assertFalse($this->eligibility()->isEligible($later));
    }

    /**
     * A contact that fails an intrinsic rule cannot hold an email against
     * another: it will never become a user, so the address is free.
     */
    public function test_an_intrinsically_excluded_contact_does_not_take_the_email(): void
    {
        $customer = $this->customerWithShipTo();
        Contact::factory()->linkedTo($customer)->privacyBlocked()->create(['number' => 'CT000100', 'email' => 'shared@example.com']);
        $contact = Contact::factory()->linkedTo($customer)->create(['number' => 'CT000200', 'email' => 'shared@example.com']);

        $this->assertTrue($this->eligibility()->isEligible($contact));
    }

    /**
     * The customer-dependent rules deliberately do not decide who wins: if
     * they did, the pair would swap once the winner's customer arrived, and
     * both would have been created along the way.
     */
    public function test_a_winner_without_a_customer_still_holds_the_email(): void
    {
        $winner = Contact::factory()->create(['number' => 'CT000100', 'email' => 'shared@example.com', 'customer_bc_id' => null]);
        $loser = Contact::factory()->eligible()->create(['number' => 'CT000200', 'email' => 'shared@example.com']);

        $this->assertFalse($this->eligibility()->isEligible($winner));
        $this->assertTrue($this->eligibility()->for($loser)->hasReason(ContactExclusionReason::DuplicateEmail));
    }

    public function test_an_empty_email_is_never_a_duplicate(): void
    {
        $customer = $this->customerWithShipTo();
        Contact::factory()->linkedTo($customer)->withoutEmail()->create(['number' => 'CT000100']);
        $contact = Contact::factory()->linkedTo($customer)->withoutEmail()->create(['number' => 'CT000200']);

        $this->assertFalse($this->eligibility()->for($contact)->hasReason(ContactExclusionReason::DuplicateEmail));
    }

    // ------------------------------------------------- SQL agrees with PHP

    /**
     * The fixture fails every rule at least once and passes at least once.
     * The scopes must select exactly the contacts the per-contact rules do.
     */
    public function test_the_scopes_select_exactly_what_the_rules_decide(): void
    {
        $customer = $this->customerWithShipTo();
        $noShip = Customer::factory()->create(['shipping_addresses' => []]);

        Contact::factory()->linkedTo($customer)->create(['number' => 'A001', 'email' => 'a@x.nz']);
        Contact::factory()->linkedTo($customer)->create(['number' => 'A002', 'email' => 'a@x.nz']);
        Contact::factory()->linkedTo($customer)->privacyBlocked()->create(['number' => 'A000', 'email' => 'a@x.nz']);
        Contact::factory()->linkedTo($customer)->notUserLevel()->create(['number' => 'B001']);
        Contact::factory()->linkedTo($customer)->notCustomerRelation()->create(['number' => 'B002']);
        Contact::factory()->linkedTo($customer)->withoutEmail()->create(['number' => 'B003']);
        Contact::factory()->linkedTo($noShip)->create(['number' => 'B004']);
        Contact::factory()->create(['number' => 'B005', 'customer_bc_id' => null]);
        Contact::factory()->create(['number' => 'B006', 'customer_bc_id' => fake()->uuid()]);
        Contact::factory()->linkedTo($customer)->create(['number' => 'C001']);

        $eligibility = $this->eligibility();
        $all = Contact::query()->orderBy('number')->get();

        $expectedEligible = $all->filter(fn (Contact $c): bool => $eligibility->isEligible($c))->pluck('number')->values()->all();
        $expectedExcluded = $all->reject(fn (Contact $c): bool => $eligibility->isEligible($c))->pluck('number')->values()->all();

        $this->assertSame(['A001', 'C001'], $expectedEligible, 'fixture sanity');

        $this->assertSame($expectedEligible, $eligibility->scopeEligible(Contact::query())->orderBy('number')->pluck('number')->all());
        $this->assertSame($expectedExcluded, $eligibility->scopeExcluded(Contact::query())->orderBy('number')->pluck('number')->all());

        foreach (ContactExclusionReason::cases() as $reason) {
            $expected = $all->filter(fn (Contact $c): bool => $eligibility->for($c)->hasReason($reason))->pluck('number')->values()->all();

            $this->assertNotSame([], $expected, "fixture covers {$reason->value}");
            $this->assertSame(
                $expected,
                $eligibility->scopeWithReason(Contact::query(), $reason)->orderBy('number')->pluck('number')->all(),
                $reason->value,
            );
        }
    }

    // -------------------------------------------------- delivery status

    public function test_a_contact_with_no_ledger_row_is_not_synced_when_eligible(): void
    {
        $contact = Contact::factory()->eligible()->create();

        $this->assertSame(DeliveryStatus::NotSynced, DeliveryStatus::forContact($contact, $this->eligibility(), null));
    }

    public function test_a_contact_with_no_ledger_row_is_not_applicable_when_excluded(): void
    {
        $contact = Contact::factory()->withoutEmail()->create();

        $this->assertSame(DeliveryStatus::NotApplicable, DeliveryStatus::forContact($contact, $this->eligibility(), null));
    }

    /**
     * A delivered contact that has since stopped qualifying is not removed;
     * its ledger row still says what the website holds.
     */
    public function test_a_delivered_contact_that_stops_qualifying_still_reads_from_the_ledger(): void
    {
        $contact = Contact::factory()->eligible()->create();
        $ledger = app(ContactSyncLedger::class);
        $record = $ledger->markPending($contact, []);
        $ledger->markSynced($record);

        $contact->update(['privacy_blocked' => true]);

        $this->assertSame(DeliveryStatus::Synced, DeliveryStatus::forContact($contact->fresh(), $this->eligibility(), $record->fresh()));
    }
}
