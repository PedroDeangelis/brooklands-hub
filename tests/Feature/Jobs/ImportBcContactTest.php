<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\Import\ContactImporter;
use App\Jobs\DeliverContactToWebsite;
use App\Jobs\ImportBcContact;
use App\Models\Contact;
use App\Models\Customer;
use App\Sync\ContactSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing one contact row and opening the delivery it calls for — or not.
 */
class ImportBcContactTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CONTACT = '475df605-1a3a-f111-bec2-00224810e61c';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverContactToWebsite::class]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => self::CONTACT,
            'number' => 'CT003705',
            'type' => 'Person',
            'displayName' => 'Brooklands Staff Sales',
            'contactBusinessRelation' => 'Customer',
            'organisationalLevelCode' => 'USER',
            'email' => 'office@brooklands.co.nz',
            'privacyBlocked' => false,
            'lastModifiedDateTime' => '2026-05-13T04:56:29.260Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row, bool $force = false): void
    {
        (new ImportBcContact($row, $force))->handle(
            app(ContactImporter::class),
            app(ContactSyncLedger::class),
        );
    }

    /**
     * A customer with a ship-to, and the contact already linked to it, so that
     * the contact qualifies as soon as it is imported.
     */
    private function linkToQualifyingCustomer(): void
    {
        $customer = Customer::factory()->withShippingAddresses([['bc_id' => 'a', 'code' => 'MAIN']])->create();
        Contact::factory()->create(['bc_id' => self::CONTACT, 'customer_bc_id' => $customer->bc_id]);
    }

    public function test_a_qualifying_contact_opens_a_delivery(): void
    {
        $this->linkToQualifyingCustomer();

        $this->import($this->row());

        $record = app(ContactSyncLedger::class)->find(Contact::first());

        $this->assertNotNull($record);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        Queue::assertPushed(DeliverContactToWebsite::class, fn (DeliverContactToWebsite $job): bool => $job->bcId === self::CONTACT);
    }

    /**
     * The rule that matters most: a contact that does not qualify is stored,
     * so the dashboard can explain it, but never queued for the website.
     */
    public function test_a_contact_that_does_not_qualify_is_stored_but_never_queued(): void
    {
        $this->import($this->row());

        $this->assertSame(1, Contact::count());
        $this->assertNull(app(ContactSyncLedger::class)->find(Contact::first()));
        Queue::assertNothingPushed();
    }

    public function test_force_cannot_queue_a_contact_that_does_not_qualify(): void
    {
        $this->import($this->row(), force: true);

        Queue::assertNothingPushed();
    }

    public function test_an_unchanged_reimport_dispatches_nothing(): void
    {
        $this->linkToQualifyingCustomer();
        $this->import($this->row());

        $this->import($this->row());

        Queue::assertPushed(DeliverContactToWebsite::class, 1);
    }

    public function test_a_changed_contact_opens_another_delivery(): void
    {
        $this->linkToQualifyingCustomer();
        $this->import($this->row());

        $this->import($this->row(['mobilePhoneNumber' => '021 000 000']));

        Queue::assertPushed(DeliverContactToWebsite::class, 2);
    }

    public function test_force_dispatches_an_unchanged_qualifying_contact(): void
    {
        $this->linkToQualifyingCustomer();
        $this->import($this->row());

        $this->import($this->row(), force: true);

        Queue::assertPushed(DeliverContactToWebsite::class, 2);
    }
}
