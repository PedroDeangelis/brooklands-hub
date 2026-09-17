<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverContactToWebsite;
use App\Models\Contact;
use App\Models\Customer;
use App\Sync\ContactSyncLedger;
use App\Sync\CustomerSyncLedger;
use App\Sync\Payload\DeliveryType;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use App\Website\WebsiteRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Delivering a contact's desired website state.
 *
 * No real request is ever made: the destination is a fake URL and every
 * response is stubbed.
 */
class DeliverContactToWebsiteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = 'https://website.test/sync';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.website', [
            'url' => self::ENDPOINT,
            'secret' => 'shared-secret-for-tests',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        Http::preventStrayRequests();
    }

    private function ledger(): ContactSyncLedger
    {
        return app(ContactSyncLedger::class);
    }

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * A customer with a ship-to that the website already holds.
     */
    private function deliveredCustomer(): Customer
    {
        $customer = Customer::factory()->withShippingAddresses([['bc_id' => 'a', 'code' => 'MAIN']])->create();
        $ledger = app(CustomerSyncLedger::class);
        $ledger->markSynced($ledger->markPending($customer, []));

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(array $attributes = [], ?Customer $customer = null): Contact
    {
        $customer ??= $this->deliveredCustomer();

        return Contact::factory()->linkedTo($customer)->create($attributes + [
            'bc_id' => '475df605-1a3a-f111-bec2-00224810e61c',
            'number' => 'CT003705',
            'display_name' => 'Brooklands Staff Sales',
            'email' => 'office@brooklands.co.nz',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $bodies = [];

        Http::assertSent(function (Request $request) use (&$bodies): bool {
            $bodies[] = json_decode($request->body(), true);

            return true;
        });

        return $bodies[0] ?? [];
    }

    private function deliver(Contact $contact): void
    {
        (new DeliverContactToWebsite($contact->bc_id))->handle(
            $this->ledger(),
            app(WebsiteClient::class),
        );
    }

    // -------------------------------------------------------------- full

    public function test_a_first_delivery_sends_the_full_payload(): void
    {
        $contact = $this->contact();
        $this->ledger()->markPending($contact, []);
        $this->accepted();

        $this->deliver($contact);

        $body = $this->sentBody();

        $this->assertSame('contact', $body['entity']);
        $this->assertSame('upsert', $body['action']);
        $this->assertSame(WebsiteRequest::MODE_FULL, $body['mode']);
        $this->assertSame($contact->bc_id, $body['bc_id']);
        $this->assertSame('CT003705', $body['payload']['number']);
        $this->assertSame('office@brooklands.co.nz', $body['payload']['email']);
        $this->assertSame($contact->customer_bc_id, $body['payload']['customer_bc_id']);
        $this->assertSame('21 McGiven Drive', $body['payload']['billing']['address_1']);
    }

    public function test_a_successful_delivery_moves_the_record_to_synced(): void
    {
        $contact = $this->contact();
        $record = $this->ledger()->markPending($contact, []);
        $this->accepted();

        $this->deliver($contact);

        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
        $this->assertSame(WebsiteAction::Upsert, $record->fresh()->delivered_action);
    }

    // ----------------------------------------------------------- partial

    public function test_a_rename_sends_a_partial_with_only_the_name_fields(): void
    {
        $contact = $this->contact();
        $this->ledger()->markPending($contact, []);
        $this->accepted();
        $this->deliver($contact);

        $contact->update(['display_name' => 'Brooklands Sales']);
        $this->ledger()->reconcile($contact, ['display_name']);
        $this->accepted();
        $this->deliver($contact->fresh());

        $body = $this->sentBody();

        $this->assertSame(WebsiteRequest::MODE_PARTIAL, $body['mode']);
        $this->assertSame(['billing', 'display_name', 'first_name'], collect(array_keys($body['changes']))->sort()->values()->all());
        $this->assertSame('Brooklands Sales', $body['changes']['display_name']);
        $this->assertSame('Brooklands Sales', $body['changes']['billing']['first_name']);
    }

    // -------------------------------------------------- eligibility gate

    /**
     * The job has the last word. A contact that stopped qualifying between
     * being queued and being sent must not become a user.
     */
    public function test_a_contact_that_stopped_qualifying_is_not_sent_and_its_row_is_dropped(): void
    {
        $contact = $this->contact();
        $this->ledger()->markPending($contact, []);
        $contact->update(['privacy_blocked' => true]);
        $this->accepted();

        $this->deliver($contact->fresh());

        Http::assertNothingSent();
        $this->assertNull($this->ledger()->find($contact));
    }

    /**
     * A user that already exists is left alone: no removal, no update.
     */
    public function test_a_delivered_contact_that_stopped_qualifying_is_left_alone(): void
    {
        $contact = $this->contact();
        $record = $this->ledger()->markPending($contact, []);
        $this->accepted();
        $this->deliver($contact);

        $contact->update(['privacy_blocked' => true, 'display_name' => 'Changed']);
        $this->ledger()->markPending($contact->fresh(), ['display_name']);
        // A fresh fake resets what was recorded, so "nothing sent" below is
        // about this second delivery only.
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);

        $this->deliver($contact->fresh());

        Http::assertNothingSent();
        $this->assertNotNull($record->fresh()->delivered_payload);
        $this->assertNotSame(DeliveryType::Remove, $this->ledger()->plan($contact->fresh())->type);
    }

    public function test_no_contact_state_ever_plans_a_removal(): void
    {
        foreach ([[], ['privacy_blocked' => true], ['email' => ''], ['customer_bc_id' => null]] as $i => $state) {
            $contact = Contact::factory()->create($state + ['bc_id' => sprintf('%08d-1a3a-f111-bec2-00224810e61c', $i), 'number' => "N{$i}"]);

            $this->assertSame(WebsiteAction::Upsert, $this->ledger()->desiredAction($contact), "state {$i}");
            $this->assertNotSame(DeliveryType::Remove, $this->ledger()->plan($contact)->type, "state {$i}");
        }
    }

    // ------------------------------------------------- customer dependency

    /**
     * The user must link to the customer post, so the customer has to reach
     * the website first. Not a failure: pending, and the sweep retries.
     */
    public function test_a_contact_whose_customer_is_not_on_the_website_waits(): void
    {
        $customer = Customer::factory()->withShippingAddresses([['bc_id' => 'a', 'code' => 'MAIN']])->create();
        $contact = $this->contact([], $customer);
        $record = $this->ledger()->markPending($contact, []);
        $this->accepted();

        $this->deliver($contact);

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Pending, $record->fresh()->status);
        $this->assertNull($record->fresh()->last_error);
    }

    /**
     * The website's own answer when the customer post is absent: accepted,
     * not applied. Still owed, still pending, no failure recorded.
     */
    public function test_accepted_but_not_applied_leaves_the_contact_pending(): void
    {
        $contact = $this->contact();
        $record = $this->ledger()->markPending($contact, []);
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'accepted' => true, 'applied' => false, 'reason' => 'No customer post yet'], 200)]);

        $this->deliver($contact);

        $this->assertSame(SyncStatus::Pending, $record->fresh()->status);
        $this->assertNull($record->fresh()->delivered_payload);
        $this->assertNull($record->fresh()->last_error);
    }

    // ------------------------------------------------- full sync required

    /**
     * The website has no user for a partial. Forget what was believed
     * delivered, and re-queue so the full payload goes now.
     */
    public function test_full_sync_required_forgets_and_requeues(): void
    {
        Queue::fake([DeliverContactToWebsite::class]);

        $contact = $this->contact();
        $record = $this->ledger()->markPending($contact, []);
        // One fake for both deliveries: a second Http::fake() would not
        // override the first stub, so the answers are given as a sequence.
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['ok' => true, 'applied' => true], 200)
            ->push(['ok' => false, 'applied' => false, 'full_sync_required' => true], 409)]);
        $this->deliver($contact);

        $contact->update(['display_name' => 'Renamed']);
        $this->ledger()->reconcile($contact, ['display_name']);

        $this->deliver($contact->fresh());

        $this->assertSame(SyncStatus::Pending, $record->fresh()->status);
        $this->assertNull($record->fresh()->delivered_payload);
        $this->assertSame(DeliveryType::Full, $this->ledger()->plan($contact->fresh())->type);
        Queue::assertPushed(DeliverContactToWebsite::class, fn (DeliverContactToWebsite $job): bool => $job->bcId === $contact->bc_id);
    }

    // ------------------------------------------------------------ conflict

    public function test_an_email_conflict_is_recorded_and_not_retried(): void
    {
        $contact = $this->contact();
        $record = $this->ledger()->markPending($contact, []);
        Http::fake([self::ENDPOINT => Http::response([
            'ok' => false, 'conflict' => true, 'applied' => false,
            'conflicts' => [['code' => 'email_conflict', 'field' => 'email', 'value' => 'office@brooklands.co.nz', 'existing_wp_id' => 12]],
        ], 409)]);

        $this->deliver($contact);

        $this->assertSame(SyncStatus::Conflict, $record->fresh()->status);
        $this->assertSame('email_conflict', $record->fresh()->conflict_details[0]['code']);
    }

    // ------------------------------------------------------------ nothing

    public function test_a_second_delivery_of_an_unchanged_contact_sends_nothing(): void
    {
        $contact = $this->contact();
        $this->ledger()->markPending($contact, []);
        $this->accepted();
        $this->deliver($contact);

        $record = $this->ledger()->find($contact);

        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
        $this->deliver($contact->fresh());

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
    }

    public function test_an_unchanged_reconcile_opens_no_work(): void
    {
        $contact = $this->contact();
        $this->ledger()->markPending($contact, []);

        $this->assertNull($this->ledger()->reconcile($contact));
    }

    public function test_reconcile_never_opens_work_for_a_contact_that_does_not_qualify(): void
    {
        $contact = $this->contact(['email' => '']);

        $this->assertNull($this->ledger()->reconcile($contact, [], force: true));
        $this->assertNull($this->ledger()->find($contact));
    }

    // ----------------------------------------------------------- identity

    public function test_the_ledger_row_records_the_contact_entity(): void
    {
        $contact = $this->contact();
        $record = $this->ledger()->markPending($contact, []);

        $this->assertSame('contact', $record->entity);
        $this->assertSame('website', $record->channel);
        $this->assertSame($contact->bc_id, $record->bc_id);
    }
}
