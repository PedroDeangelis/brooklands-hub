<?php

namespace Tests\Feature\Jobs;

use App\BusinessCentral\Import\CampaignImporter;
use App\Jobs\DeliverCampaignToWebsite;
use App\Jobs\ImportBcCampaign;
use App\Models\Campaign;
use App\Sync\CampaignSyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing one campaign row and opening the delivery it calls for.
 */
class ImportBcCampaignTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverCampaignToWebsite::class]);
    }

    /**
     * @param  array<int, string>  $customers
     * @return array<string, mixed>
     */
    private function row(array $customers = ['cust-a'], bool $activated = true): array
    {
        return [
            'id' => '5a6b7f77-0321-f111-8340-7ced8d3493eb',
            'code' => 'CP0001',
            'description' => 'Ezydog Harness | 25% Off',
            'startingDate' => now()->subWeek()->toDateString(),
            'endingDate' => now()->addMonth()->toDateString(),
            'activated' => $activated,
            'lastModifiedDateTime' => '2026-04-16T04:27:03.077Z',
            'campaignCustomers' => array_map(
                fn (string $id): array => ['id' => "link-{$id}", 'customerId' => $id],
                $customers,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row, bool $force = false): void
    {
        (new ImportBcCampaign($row, $force))->handle(
            app(CampaignImporter::class),
            app(CampaignSyncLedger::class),
        );
    }

    public function test_a_new_campaign_opens_a_delivery(): void
    {
        $this->import($this->row());

        $campaign = Campaign::first();

        $this->assertNotNull(app(CampaignSyncLedger::class)->find($campaign));
        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);
    }

    /**
     * The guard that stops an unchanged re-import queueing a delivery on every
     * pass: the ledger already wants exactly this, so there is no new work.
     */
    public function test_an_unchanged_reimport_dispatches_nothing(): void
    {
        $this->import($this->row());
        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);

        $this->import($this->row());

        // Still 1: the second import opened no new work.
        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);
    }

    public function test_a_reordered_audience_dispatches_nothing(): void
    {
        $this->import($this->row(['cust-a', 'cust-b', 'cust-c']));
        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);

        $this->import($this->row(['cust-c', 'cust-a', 'cust-b']));

        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);
    }

    public function test_a_changed_campaign_opens_another_delivery(): void
    {
        $this->import($this->row());

        $row = $this->row();
        $row['description'] = 'Ezydog Harness | 30% Off';
        $this->import($row);

        Queue::assertPushed(DeliverCampaignToWebsite::class, 2);
    }

    /**
     * Deactivation is a changed field, not a different instruction: Laravel
     * mirrors the state and WordPress decides what to do with it.
     */
    public function test_deactivation_opens_an_upsert_carrying_the_flag(): void
    {
        $this->import($this->row());

        $this->import($this->row(['cust-a'], activated: false));

        $record = app(CampaignSyncLedger::class)->find(Campaign::first());

        $this->assertSame(WebsiteAction::Upsert, $record->action);
        $this->assertSame(['activated'], $record->changed_fields);
        $this->assertFalse($record->payload['activated']);
    }

    /**
     * Expiry is the website's affair, not a removal: a campaign the website
     * already holds keeps being upserted after its deadline passes, and
     * WordPress decides to stop showing it.
     */
    public function test_an_expired_campaign_already_delivered_still_opens_an_upsert(): void
    {
        // Delivered while it was still live.
        $this->import($this->row());
        $campaign = Campaign::first();
        $ledger = app(CampaignSyncLedger::class);
        $ledger->markSynced($ledger->find($campaign));

        // It then ends, and a later correction arrives.
        $row = $this->row();
        $row['endingDate'] = now()->subDay()->toDateString();
        $row['description'] = 'Corrected title';
        $this->import($row);

        $record = $ledger->find(Campaign::first());

        $this->assertSame(WebsiteAction::Upsert, $record->action);
    }

    /**
     * A first import carries years of finished campaigns. One that ended before
     * the website ever held it is not worth publishing, so no delivery is
     * queued for it.
     */
    public function test_an_expired_campaign_never_delivered_queues_nothing(): void
    {
        $row = $this->row();
        $row['endingDate'] = now()->subYear()->toDateString();

        $this->import($row);

        // The campaign itself is still stored: only the delivery is skipped.
        $this->assertSame(1, Campaign::count());
        $this->assertNull(app(CampaignSyncLedger::class)->find(Campaign::first()));
        Queue::assertNothingPushed();
    }

    /**
     * Whatever Business Central says, the import never opens a removal.
     */
    public function test_no_imported_state_ever_opens_a_removal(): void
    {
        $rows = [
            $this->row(),
            $this->row(['cust-a'], activated: false),
            ['endingDate' => now()->subYear()->toDateString()] + $this->row(),
            ['startingDate' => now()->addYear()->toDateString()] + $this->row(),
        ];

        foreach ($rows as $i => $row) {
            $this->import($row);

            // An expired row opens no work at all, which is still not a
            // removal: what matters is that nothing ever asks for one.
            $record = app(CampaignSyncLedger::class)->find(Campaign::first());

            if ($record !== null) {
                $this->assertSame(
                    WebsiteAction::Upsert,
                    $record->action,
                    sprintf('row %d opened a removal', $i),
                );
            }
        }
    }

    // ------------------------------------------------------------------ force

    /**
     * The whole point of --force: a row that has not moved is delivered anyway,
     * so a website that has drifted can be put back in step.
     */
    public function test_force_dispatches_an_unchanged_campaign(): void
    {
        $this->import($this->row());
        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);

        // Normally this second import would open nothing.
        $this->import($this->row(), force: true);

        Queue::assertPushed(DeliverCampaignToWebsite::class, 2);
    }

    /**
     * A forced run overrides the stale skip too: the campaigns most likely to
     * need repairing are exactly the ones that have already ended.
     */
    public function test_force_dispatches_an_expired_campaign_that_was_never_delivered(): void
    {
        $row = $this->row();
        $row['endingDate'] = now()->subYear()->toDateString();

        $this->import($row);
        Queue::assertNothingPushed();

        $this->import($row, force: true);

        Queue::assertPushed(DeliverCampaignToWebsite::class, 1);
        $this->assertNotNull(app(CampaignSyncLedger::class)->find(Campaign::first()));
    }

    public function test_force_still_reports_an_upsert(): void
    {
        $this->import($this->row(), force: true);

        $this->assertSame(
            WebsiteAction::Upsert,
            app(CampaignSyncLedger::class)->find(Campaign::first())->action,
        );
    }
}
