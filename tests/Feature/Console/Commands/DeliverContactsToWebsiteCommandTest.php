<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\DeliverContactToWebsite;
use App\Models\Contact;
use App\Models\SyncRecord;
use App\Sync\ContactSyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Planning and queueing contact deliveries by hand.
 */
class DeliverContactsToWebsiteCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverContactToWebsite::class]);
    }

    private function ledger(): ContactSyncLedger
    {
        return app(ContactSyncLedger::class);
    }

    public function test_it_reports_without_sending_by_default(): void
    {
        Contact::factory()->eligible()->create(['number' => 'CT000001']);

        $this->artisan('website:deliver-contacts')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('1 contact(s) would be delivered')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRecord::count());
    }

    public function test_send_opens_a_pending_full_delivery_for_a_qualifying_contact(): void
    {
        $contact = Contact::factory()->eligible()->create(['number' => 'CT000001']);

        $this->artisan('website:deliver-contacts', ['--send' => true])->assertExitCode(0);

        $record = $this->ledger()->find($contact);

        $this->assertNotNull($record);
        $this->assertSame(SyncStatus::Pending, $record->status);
        Queue::assertPushed(DeliverContactToWebsite::class, fn (DeliverContactToWebsite $job): bool => $job->bcId === $contact->bc_id);
    }

    /**
     * The rule this command exists to uphold.
     */
    public function test_a_contact_that_does_not_qualify_is_reported_with_its_reasons_and_never_queued(): void
    {
        Contact::factory()->withoutEmail()->create(['number' => 'CT000002', 'customer_bc_id' => null]);

        $this->artisan('website:deliver-contacts', ['--send' => true])
            ->expectsOutputToContain('Excluded')
            ->expectsOutputToContain('no email address')
            ->expectsOutputToContain('not linked to a customer')
            ->expectsOutputToContain('1 excluded')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRecord::count());
    }

    public function test_number_limits_the_run_to_those_contacts(): void
    {
        $wanted = Contact::factory()->eligible()->create(['number' => 'CT000001']);
        Contact::factory()->eligible()->create(['number' => 'CT000002']);

        $this->artisan('website:deliver-contacts', ['--send' => true, '--number' => ['CT000001']])->assertExitCode(0);

        Queue::assertPushed(DeliverContactToWebsite::class, 1);
        Queue::assertPushed(DeliverContactToWebsite::class, fn (DeliverContactToWebsite $job): bool => $job->bcId === $wanted->bc_id);
    }

    public function test_limit_caps_the_run(): void
    {
        Contact::factory()->eligible()->count(3)->create();

        $this->artisan('website:deliver-contacts', ['--send' => true, '--limit' => 2])->assertExitCode(0);

        Queue::assertPushed(DeliverContactToWebsite::class, 2);
    }

    public function test_a_non_positive_limit_is_refused(): void
    {
        $this->artisan('website:deliver-contacts', ['--limit' => 0])->assertExitCode(1);
    }

    public function test_a_contact_already_delivered_is_left_alone(): void
    {
        $contact = Contact::factory()->eligible()->create();
        $this->ledger()->markSynced($this->ledger()->markPending($contact, []));

        $this->artisan('website:deliver-contacts', ['--send' => true])
            ->expectsOutputToContain('1 already up to date')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
