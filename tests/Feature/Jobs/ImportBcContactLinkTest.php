<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\Import\ContactLinkImporter;
use App\Enums\SyncStatus;
use App\Jobs\DeliverContactToWebsite;
use App\Jobs\ImportBcContactLink;
use App\Models\Contact;
use App\Models\Customer;
use App\Sync\ContactSyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Applying one customerContacts row, and re-evaluating the contact.
 */
class ImportBcContactLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverContactToWebsite::class]);
    }

    private function link(string $contactBcId, ?string $customerBcId, bool $force = false): void
    {
        (new ImportBcContactLink(['id' => $contactBcId, 'customerId' => $customerBcId], $force))->handle(
            app(ContactLinkImporter::class),
            app(ContactSyncLedger::class),
        );
    }

    private function qualifyingCustomer(): Customer
    {
        return Customer::factory()->withShippingAddresses([['bc_id' => 'a', 'code' => 'MAIN']])->create();
    }

    public function test_linking_to_a_qualifying_customer_opens_a_delivery(): void
    {
        $customer = $this->qualifyingCustomer();
        $contact = Contact::factory()->create();

        $this->link($contact->bc_id, $customer->bc_id);

        $this->assertSame($customer->bc_id, $contact->fresh()->customer_bc_id);
        Queue::assertPushed(DeliverContactToWebsite::class, 1);
    }

    public function test_a_row_for_a_contact_that_has_not_arrived_is_skipped(): void
    {
        $this->link('00000000-0000-0000-0000-000000000000', $this->qualifyingCustomer()->bc_id);

        Queue::assertNothingPushed();
    }

    public function test_linking_to_a_customer_without_a_ship_to_opens_nothing(): void
    {
        $customer = Customer::factory()->create(['shipping_addresses' => []]);
        $contact = Contact::factory()->create();

        $this->link($contact->bc_id, $customer->bc_id);

        Queue::assertNothingPushed();
        $this->assertNull(app(ContactSyncLedger::class)->find($contact->fresh()));
    }

    /**
     * The reason the sweep re-evaluates every contact. Nothing about the
     * contact changed; its customer gained an address since the last sweep.
     */
    public function test_an_unchanged_link_still_opens_a_delivery_once_the_contact_qualifies(): void
    {
        $customer = Customer::factory()->create(['shipping_addresses' => []]);
        $contact = Contact::factory()->linkedTo($customer)->create();

        $this->link($contact->bc_id, $customer->bc_id);
        Queue::assertNothingPushed();

        $customer->update(['shipping_addresses' => [['bc_id' => 'a', 'code' => 'MAIN']]]);

        $this->link($contact->bc_id, $customer->bc_id);
        Queue::assertPushed(DeliverContactToWebsite::class, 1);
    }

    /**
     * A contact held back because its customer had not reached the website is
     * pending with an unchanged payload. The sweep is what tries it again.
     */
    public function test_a_pending_delivery_is_requeued_by_the_sweep(): void
    {
        $customer = $this->qualifyingCustomer();
        $contact = Contact::factory()->linkedTo($customer)->create();
        $ledger = app(ContactSyncLedger::class);
        $ledger->markPending($contact, []);

        $this->link($contact->bc_id, $customer->bc_id);

        Queue::assertPushed(DeliverContactToWebsite::class, 1);
    }

    public function test_a_synced_contact_is_not_requeued(): void
    {
        $customer = $this->qualifyingCustomer();
        $contact = Contact::factory()->linkedTo($customer)->create();
        $ledger = app(ContactSyncLedger::class);
        $ledger->markSynced($ledger->markPending($contact, []));

        $this->link($contact->bc_id, $customer->bc_id);

        Queue::assertNothingPushed();
        $this->assertSame(SyncStatus::Synced, $ledger->find($contact)->status);
    }

    public function test_force_requeues_a_synced_contact(): void
    {
        $customer = $this->qualifyingCustomer();
        $contact = Contact::factory()->linkedTo($customer)->create();
        $ledger = app(ContactSyncLedger::class);
        $ledger->markSynced($ledger->markPending($contact, []));

        $this->link($contact->bc_id, $customer->bc_id, force: true);

        Queue::assertPushed(DeliverContactToWebsite::class, 1);
    }

    public function test_an_empty_customer_unlinks_and_opens_nothing(): void
    {
        $contact = Contact::factory()->linkedTo($this->qualifyingCustomer())->create();

        $this->link($contact->bc_id, '');

        $this->assertNull($contact->fresh()->customer_bc_id);
        Queue::assertNothingPushed();
    }
}
