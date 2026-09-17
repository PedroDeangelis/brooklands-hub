<?php

namespace Tests\Feature\Website;

use App\Enums\SyncStatus;
use App\Jobs\DeliverCampaignToWebsite;
use App\Models\Campaign;
use App\Sync\CampaignSyncLedger;
use App\Sync\Payload\DeliveryType;
use App\Sync\WebsiteAction;
use App\Website\WebsiteClient;
use App\Website\WebsiteRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivering a campaign's desired website state.
 *
 * No real request is ever made: the destination is a fake URL and every
 * response is stubbed.
 */
class DeliverCampaignToWebsiteTest extends TestCase
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

    private function ledger(): CampaignSyncLedger
    {
        return app(CampaignSyncLedger::class);
    }

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->create($attributes + [
            'bc_id' => '5a6b7f77-0321-f111-8340-7ced8d3493eb',
            'code' => 'CP0001',
            'description' => 'Ezydog Harness | 25% Off',
            'customers' => ['cust-a', 'cust-b'],
        ]);
    }

    /**
     * The body of the one request that was sent.
     *
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

    private function deliver(Campaign $campaign): void
    {
        (new DeliverCampaignToWebsite($campaign->bc_id))->handle(
            $this->ledger(),
            app(WebsiteClient::class),
        );
    }

    // -------------------------------------------------------------- full

    public function test_a_first_delivery_sends_the_full_payload(): void
    {
        $campaign = $this->campaign();
        $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $body = $this->sentBody();

        $this->assertSame('campaign', $body['entity']);
        $this->assertSame('upsert', $body['action']);
        $this->assertSame(WebsiteRequest::MODE_FULL, $body['mode']);
        $this->assertSame($campaign->bc_id, $body['bc_id']);
        $this->assertSame('CP0001', $body['payload']['code']);
        $this->assertSame('Ezydog Harness | 25% Off', $body['payload']['title']);
        $this->assertSame(['cust-a', 'cust-b'], $body['payload']['customers']);
    }

    /**
     * The raw Business Central row carries fields the website has no use for,
     * and any movement inside it would change the hash and resend an otherwise
     * identical payload.
     */
    public function test_the_payload_does_not_carry_the_raw_business_central_row(): void
    {
        $campaign = $this->campaign(['bc_payload' => ['statusCode' => '', 'salespersonCode' => '']]);
        $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $this->assertArrayNotHasKey('bc_payload', $this->sentBody()['payload']);
        $this->assertSame(
            ['activated', 'bc_id', 'code', 'customers', 'deadline', 'start_date', 'title'],
            collect(array_keys($this->sentBody()['payload']))->sort()->values()->all(),
        );
    }

    public function test_a_successful_delivery_moves_the_record_to_synced(): void
    {
        $campaign = $this->campaign();
        $record = $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
        $this->assertSame(WebsiteAction::Upsert, $record->fresh()->delivered_action);
    }

    // ----------------------------------------------------------- partial

    public function test_a_title_change_sends_a_partial_with_only_that_field(): void
    {
        $campaign = $this->campaign();
        $this->ledger()->markPending($campaign, []);
        $this->accepted();
        $this->deliver($campaign);

        $campaign->update(['description' => 'Ezydog Harness | 30% Off']);
        $this->ledger()->reconcile($campaign, ['description']);
        $this->accepted();
        $this->deliver($campaign->fresh());

        $body = $this->sentBody();

        $this->assertSame('campaign', $body['entity']);
        $this->assertSame(WebsiteRequest::MODE_PARTIAL, $body['mode']);
        $this->assertSame(['title'], array_keys($body['changes']));
        $this->assertSame('Ezydog Harness | 30% Off', $body['changes']['title']);
    }

    /**
     * The audience is a set. A customer joining or leaving resends the whole
     * normalised list, because merging into a sorted array positionally would
     * silently reassign entries.
     */
    public function test_an_audience_change_resends_the_complete_customer_array(): void
    {
        $campaign = $this->campaign();
        $this->ledger()->markPending($campaign, []);
        $this->accepted();
        $this->deliver($campaign);

        $campaign->update(['customers' => ['cust-a', 'cust-b', 'cust-c']]);
        $this->ledger()->reconcile($campaign, ['customers']);
        $this->accepted();
        $this->deliver($campaign->fresh());

        $body = $this->sentBody();

        $this->assertSame(['customers'], array_keys($body['changes']));
        $this->assertSame(['cust-a', 'cust-b', 'cust-c'], $body['changes']['customers']);
    }

    public function test_an_emptied_audience_is_sent_as_an_empty_list(): void
    {
        $campaign = $this->campaign();
        $this->ledger()->markPending($campaign, []);
        $this->accepted();
        $this->deliver($campaign);

        $campaign->update(['customers' => []]);
        $this->ledger()->reconcile($campaign, ['customers']);
        $this->accepted();
        $this->deliver($campaign->fresh());

        $this->assertSame([], $this->sentBody()['changes']['customers']);
    }

    // -------------------------------------------- never removed, only mirrored

    /**
     * The rule the whole campaign design rests on: Laravel mirrors Business
     * Central's state and never withdraws a campaign. A deactivated campaign is
     * still a fact the website needs, so it goes over as an upsert carrying
     * activated=false, and WordPress decides what that means.
     */
    public function test_a_deactivated_campaign_is_upserted_with_activated_false(): void
    {
        $campaign = $this->campaign(['activated' => false]);
        $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $body = $this->sentBody();

        $this->assertSame('campaign', $body['entity']);
        $this->assertSame('upsert', $body['action']);
        $this->assertFalse($body['payload']['activated']);
        $this->assertArrayNotHasKey('reasons', $body);
    }

    public function test_an_activated_campaign_is_upserted_with_activated_true(): void
    {
        $campaign = $this->campaign(['activated' => true]);
        $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $this->assertTrue($this->sentBody()['payload']['activated']);
    }

    /**
     * WordPress owns what "expired" means, in the site's timezone.
     */
    public function test_an_expired_campaign_is_upserted(): void
    {
        $campaign = $this->campaign([
            'starting_date' => now()->subMonths(3)->toDateString(),
            'ending_date' => now()->subDay()->toDateString(),
        ]);

        $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $body = $this->sentBody();

        $this->assertSame('upsert', $body['action']);
        $this->assertSame(now()->subDay()->toDateString(), $body['payload']['deadline']);
    }

    public function test_a_future_campaign_is_upserted(): void
    {
        $campaign = $this->campaign([
            'starting_date' => now()->addWeek()->toDateString(),
            'ending_date' => now()->addMonths(2)->toDateString(),
        ]);

        $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $body = $this->sentBody();

        $this->assertSame('upsert', $body['action']);
        $this->assertSame(now()->addWeek()->toDateString(), $body['payload']['start_date']);
    }

    public function test_an_expired_and_deactivated_campaign_is_still_upserted(): void
    {
        $campaign = $this->campaign([
            'ending_date' => now()->subDay()->toDateString(),
            'activated' => false,
        ]);

        $this->ledger()->markPending($campaign, []);
        $this->accepted();

        $this->deliver($campaign);

        $this->assertSame('upsert', $this->sentBody()['action']);
    }

    /**
     * Whatever the campaign's state, the plan is never a removal.
     */
    public function test_no_campaign_state_ever_plans_a_removal(): void
    {
        $states = [
            ['activated' => true],
            ['activated' => false],
            ['ending_date' => now()->subYear()->toDateString()],
            ['starting_date' => now()->addYear()->toDateString()],
            ['activated' => false, 'ending_date' => now()->subYear()->toDateString()],
            ['starting_date' => null, 'ending_date' => null],
        ];

        foreach ($states as $i => $state) {
            $campaign = Campaign::factory()->create($state + [
                'bc_id' => sprintf('%08d-0321-f111-8340-7ced8d3493eb', $i),
                'code' => sprintf('CP%04d', $i),
            ]);

            $this->assertSame(
                WebsiteAction::Upsert,
                $this->ledger()->desiredAction($campaign),
                sprintf('state %d planned a removal', $i),
            );

            $this->assertNotSame(
                DeliveryType::Remove,
                $this->ledger()->plan($campaign)->type,
                sprintf('state %d planned a removal', $i),
            );
        }
    }

    /**
     * Deactivation moves a payload field rather than the instruction, so it is
     * delivered as an ordinary partial.
     */
    public function test_deactivation_is_delivered_as_a_partial_change(): void
    {
        $campaign = $this->campaign(['activated' => true]);
        $this->ledger()->markPending($campaign, []);
        $this->accepted();
        $this->deliver($campaign);

        $campaign->update(['activated' => false]);
        $this->ledger()->reconcile($campaign, ['activated']);
        $this->accepted();
        $this->deliver($campaign->fresh());

        $body = $this->sentBody();

        $this->assertSame('upsert', $body['action']);
        $this->assertSame(WebsiteRequest::MODE_PARTIAL, $body['mode']);
        $this->assertSame(['activated'], array_keys($body['changes']));
        $this->assertFalse($body['changes']['activated']);
    }

    // ------------------------------------------- expired and never delivered

    /**
     * Business Central keeps campaigns long after they end, and publishing one
     * whose deadline has passed creates a promotion the website will never
     * show. A campaign that finished before the website ever held it is
     * therefore skipped rather than delivered.
     */
    public function test_an_expired_campaign_that_was_never_delivered_opens_no_work(): void
    {
        $campaign = $this->campaign([
            'starting_date' => now()->subMonths(3)->toDateString(),
            'ending_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertNull($this->ledger()->reconcile($campaign, ['ending_date']));
        $this->assertNull($this->ledger()->find($campaign));
    }

    /**
     * The half that keeps the skip safe. Once a promotion exists on the website
     * a correction still has to reach it, so an expired campaign already
     * delivered keeps receiving updates rather than freezing at whatever was
     * last sent.
     */
    public function test_an_expired_campaign_already_delivered_still_receives_updates(): void
    {
        $campaign = $this->campaign([
            'starting_date' => now()->subMonths(3)->toDateString(),
            'ending_date' => now()->subDay()->toDateString(),
        ]);

        // Deliver it once while it still counted.
        $this->ledger()->markPending($campaign, []);
        $this->accepted();
        $this->deliver($campaign);

        // A later correction must still be queued.
        $campaign->update(['description' => 'Corrected title']);

        $record = $this->ledger()->reconcile($campaign->fresh(), ['description']);

        $this->assertNotNull($record);
        $this->assertSame(SyncStatus::Pending, $record->status);
    }

    public function test_a_campaign_ending_today_is_still_delivered(): void
    {
        $campaign = $this->campaign([
            'starting_date' => now()->subWeek()->toDateString(),
            'ending_date' => now()->toDateString(),
        ]);

        $this->assertNotNull($this->ledger()->reconcile($campaign, []));
    }

    public function test_a_campaign_with_no_end_date_is_never_stale(): void
    {
        $campaign = $this->campaign([
            'starting_date' => now()->subYear()->toDateString(),
            'ending_date' => null,
        ]);

        $this->assertNotNull($this->ledger()->reconcile($campaign, []));
    }

    public function test_a_future_campaign_is_not_stale(): void
    {
        $campaign = $this->campaign([
            'starting_date' => now()->addWeek()->toDateString(),
            'ending_date' => now()->addMonths(2)->toDateString(),
        ]);

        $this->assertNotNull($this->ledger()->reconcile($campaign, []));
    }

    /**
     * The skip is an efficiency gate, never a removal: it declines to open new
     * work and takes nothing down.
     */
    public function test_skipping_an_expired_campaign_never_produces_a_removal(): void
    {
        $campaign = $this->campaign(['ending_date' => now()->subYear()->toDateString()]);

        $this->ledger()->reconcile($campaign, []);

        $this->assertSame(WebsiteAction::Upsert, $this->ledger()->desiredAction($campaign));
        $this->assertNull($this->ledger()->find($campaign));
    }

    // ------------------------------------------------------------ nothing

    public function test_an_unchanged_campaign_plans_nothing(): void
    {
        $campaign = $this->campaign();
        $this->ledger()->markPending($campaign, []);
        $this->accepted();
        $this->deliver($campaign);

        $this->assertSame(
            DeliveryType::None,
            $this->ledger()->plan($campaign->fresh())->type,
        );
    }

    public function test_a_second_delivery_of_an_unchanged_campaign_sends_nothing(): void
    {
        $campaign = $this->campaign();
        $this->ledger()->markPending($campaign, []);
        $this->accepted();
        $this->deliver($campaign);

        $record = $this->ledger()->find($campaign);

        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'applied' => true], 200)]);
        $this->deliver($campaign->fresh());

        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Synced, $record->fresh()->status);
    }

    public function test_an_unchanged_reconcile_opens_no_work(): void
    {
        $campaign = $this->campaign();
        $this->ledger()->markPending($campaign, []);

        $this->assertNull($this->ledger()->reconcile($campaign));
    }

    // ----------------------------------------------------------- identity

    /**
     * Identity is the triple, so a campaign and a product carrying the same
     * Business Central id cannot be mistaken for one another.
     */
    public function test_the_ledger_row_records_the_campaign_entity(): void
    {
        $campaign = $this->campaign();
        $record = $this->ledger()->markPending($campaign, []);

        $this->assertSame('campaign', $record->entity);
        $this->assertSame('website', $record->channel);
        $this->assertSame($campaign->bc_id, $record->bc_id);
    }
}
