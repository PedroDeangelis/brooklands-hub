<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\DeliverCampaignToWebsite;
use App\Models\Campaign;
use App\Models\SyncRecord;
use App\Sync\CampaignSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Opening ledger work for campaigns that have none.
 *
 * This is the path that rescues campaigns imported before delivery existed:
 * they have rows in the campaigns table but nothing in the ledger, and nothing
 * in Business Central will change to make them reconcile on their own.
 */
class DeliverCampaignsToWebsiteCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverCampaignToWebsite::class]);
    }

    private function ledger(): CampaignSyncLedger
    {
        return app(CampaignSyncLedger::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->create($attributes);
    }

    // ------------------------------------------------------------ dry run

    /**
     * Delivering a promotion creates or deletes a public post, so sending has
     * to be asked for rather than being what happens by default.
     */
    public function test_it_reports_without_sending_by_default(): void
    {
        $this->campaign(['code' => 'CP0001']);

        $this->artisan('website:deliver-campaigns')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('CP0001')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRecord::query()->where('entity', 'campaign')->count());
    }

    public function test_a_dry_run_opens_no_ledger_rows(): void
    {
        $this->campaign();
        $this->campaign(['code' => 'CP0002', 'bc_id' => fake()->uuid()]);

        $this->artisan('website:deliver-campaigns')->assertExitCode(0);

        $this->assertSame(0, SyncRecord::query()->count());
    }

    // ------------------------------------------------------------- send

    /**
     * The Stage 1 rescue: campaigns already imported must be able to become
     * pending full deliveries without anyone touching Business Central.
     */
    public function test_send_opens_a_pending_full_delivery_for_a_campaign_with_no_ledger_row(): void
    {
        $campaign = $this->campaign(['code' => 'CP0001']);

        $this->assertNull($this->ledger()->find($campaign));

        $this->artisan('website:deliver-campaigns', ['--send' => true])
            ->expectsOutputToContain('Queued 1 campaign(s)')
            ->assertExitCode(0);

        $record = $this->ledger()->find($campaign);

        $this->assertNotNull($record);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame('campaign', $record->entity);
        $this->assertSame('website', $record->channel);

        Queue::assertPushed(
            DeliverCampaignToWebsite::class,
            fn (DeliverCampaignToWebsite $job): bool => $job->bcId === $campaign->bc_id,
        );
    }

    public function test_send_queues_every_campaign_that_needs_one(): void
    {
        foreach (range(1, 3) as $n) {
            $this->campaign(['code' => sprintf('CP%04d', $n), 'bc_id' => fake()->uuid()]);
        }

        $this->artisan('website:deliver-campaigns', ['--send' => true])->assertExitCode(0);

        Queue::assertPushed(DeliverCampaignToWebsite::class, 3);
        $this->assertSame(3, SyncRecord::query()->where('entity', 'campaign')->count());
    }

    /**
     * Every campaign state is an upsert; WordPress decides what to show.
     */
    public function test_a_deactivated_campaign_opens_an_upsert(): void
    {
        $campaign = $this->campaign(['activated' => false]);

        $this->artisan('website:deliver-campaigns', ['--send' => true])->assertExitCode(0);

        $record = $this->ledger()->find($campaign);

        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertFalse($record->payload['activated']);
    }

    /**
     * The command opens work directly rather than through reconcile(), so it
     * needs the same gate: a finished campaign the website never held is
     * reported and skipped rather than queued.
     */
    public function test_an_expired_campaign_never_delivered_is_skipped(): void
    {
        $this->campaign([
            'code' => 'CP-OLD',
            'ending_date' => now()->subYear()->toDateString(),
        ]);

        $this->artisan('website:deliver-campaigns', ['--send' => true])
            ->expectsOutputToContain('CP-OLD')
            ->expectsOutputToContain('ended before the website ever held them')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRecord::query()->where('entity', 'campaign')->count());
    }

    public function test_a_live_campaign_is_still_queued_alongside_an_expired_one(): void
    {
        $this->campaign(['code' => 'CP-OLD', 'ending_date' => now()->subYear()->toDateString()]);
        $this->campaign([
            'code' => 'CP-NOW',
            'bc_id' => fake()->uuid(),
            'ending_date' => now()->addMonth()->toDateString(),
        ]);

        $this->artisan('website:deliver-campaigns', ['--send' => true])->assertExitCode(0);

        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);
        $this->assertSame(1, SyncRecord::query()->where('entity', 'campaign')->count());
    }

    // --------------------------------------------------------- selection

    public function test_code_limits_the_run_to_those_campaigns(): void
    {
        $wanted = $this->campaign(['code' => 'CP0001']);
        $this->campaign(['code' => 'CP0002', 'bc_id' => fake()->uuid()]);

        $this->artisan('website:deliver-campaigns', ['--send' => true, '--code' => ['CP0001']])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);
        $this->assertNotNull($this->ledger()->find($wanted));
        $this->assertSame(1, SyncRecord::query()->where('entity', 'campaign')->count());
    }

    public function test_limit_caps_the_run(): void
    {
        foreach (range(1, 4) as $n) {
            $this->campaign(['code' => sprintf('CP%04d', $n), 'bc_id' => fake()->uuid()]);
        }

        $this->artisan('website:deliver-campaigns', ['--send' => true, '--limit' => 2])
            ->assertExitCode(0);

        Queue::assertPushed(DeliverCampaignToWebsite::class, 2);
    }

    public function test_an_unknown_code_matches_nothing(): void
    {
        $this->campaign(['code' => 'CP0001']);

        $this->artisan('website:deliver-campaigns', ['--send' => true, '--code' => ['NOPE']])
            ->expectsOutputToContain('No campaigns match those codes')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_a_non_positive_limit_is_refused(): void
    {
        $this->artisan('website:deliver-campaigns', ['--limit' => 0])
            ->expectsOutputToContain('--limit must be a positive integer')
            ->assertExitCode(1);
    }

    // ----------------------------------------------------- already synced

    /**
     * A campaign the website already holds must not be re-sent: the plan says
     * there is nothing to do, and the command trusts the plan.
     */
    public function test_a_campaign_already_delivered_is_left_alone(): void
    {
        $campaign = $this->campaign();
        $record = $this->ledger()->markPending($campaign, []);
        $this->ledger()->markSynced($record);

        $this->artisan('website:deliver-campaigns', ['--send' => true])
            ->expectsOutputToContain('1 already up to date')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
