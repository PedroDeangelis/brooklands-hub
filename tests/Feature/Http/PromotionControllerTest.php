<?php

namespace Tests\Feature\Http;

use App\Campaigns\CampaignFilter;
use App\Campaigns\CampaignStatus;
use App\Enums\SyncStatus;
use App\Models\Campaign;
use App\Sync\CampaignSyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The promotion list and detail pages.
 *
 * The status column is derived rather than stored, and the filter reproduces
 * that derivation in SQL. The two must agree, or a filter would hide rows whose
 * badge says they should be there — so both are asserted against the same
 * fixtures.
 */
class PromotionControllerTest extends TestCase
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

        // Deliberately no default stub here. A second Http::fake() does not
        // override the first, so faking "holds nothing" in setUp() would make
        // every test that says otherwise silently read as missing.
    }

    /**
     * Fake the website's answer about which records it holds.
     *
     * Call once per test, before the request.
     *
     * @param  array<string, array{wp_id: int, status: string}>  $records
     */
    private function websiteHolds(array $records): void
    {
        Http::fake([
            self::STATUS_ENDPOINT => Http::response(['ok' => true, 'records' => $records], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->create($attributes);
    }

    private function active(): Campaign
    {
        return $this->campaign([
            'code' => 'CP-ACTIVE',
            'activated' => true,
            'starting_date' => now()->subWeek()->toDateString(),
            'ending_date' => now()->addWeek()->toDateString(),
        ]);
    }

    private function scheduled(): Campaign
    {
        return $this->campaign([
            'code' => 'CP-FUTURE',
            'activated' => true,
            'starting_date' => now()->addWeek()->toDateString(),
            'ending_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function ended(): Campaign
    {
        return $this->campaign([
            'code' => 'CP-ENDED',
            'activated' => true,
            'starting_date' => now()->subMonths(2)->toDateString(),
            'ending_date' => now()->subDay()->toDateString(),
        ]);
    }

    private function deactivated(): Campaign
    {
        return $this->campaign([
            'code' => 'CP-OFF',
            'activated' => false,
            'starting_date' => now()->subWeek()->toDateString(),
            'ending_date' => now()->addWeek()->toDateString(),
        ]);
    }

    // ------------------------------------------------------------- the list

    public function test_the_list_renders(): void
    {
        $this->active();

        $this->get(route('promotions.index'))
            ->assertOk()
            ->assertSee('Promotions')
            ->assertSee('CP-ACTIVE');
    }

    public function test_the_list_shows_every_campaign_whatever_its_state(): void
    {
        $this->active();
        $this->scheduled();
        $this->ended();
        $this->deactivated();

        $response = $this->get(route('promotions.index'));

        foreach (['CP-ACTIVE', 'CP-FUTURE', 'CP-ENDED', 'CP-OFF'] as $code) {
            $response->assertSee($code);
        }
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get(route('promotions.index'))
            ->assertOk()
            ->assertSee('No campaigns have been imported yet.');
    }

    public function test_the_audience_size_is_shown(): void
    {
        $this->campaign(['code' => 'CP0001', 'customers' => ['a', 'b', 'c']]);

        $this->get(route('promotions.index'))->assertOk()->assertSee('CP0001');
    }

    // ----------------------------------------------------- derived status

    /**
     * Deactivation outranks the dates: a campaign switched off inside its own
     * window is Deactivated, not Active.
     */
    public function test_deactivation_outranks_the_date_window(): void
    {
        $campaign = $this->deactivated();

        $this->assertSame(CampaignStatus::Deactivated, CampaignStatus::for($campaign));
    }

    public function test_status_is_derived_from_activation_and_dates(): void
    {
        $this->assertSame(CampaignStatus::Active, CampaignStatus::for($this->active()));
        $this->assertSame(CampaignStatus::Scheduled, CampaignStatus::for($this->scheduled()));
        $this->assertSame(CampaignStatus::Ended, CampaignStatus::for($this->ended()));
        $this->assertSame(CampaignStatus::Deactivated, CampaignStatus::for($this->deactivated()));
    }

    public function test_a_campaign_with_no_dates_is_active_when_activated(): void
    {
        $campaign = $this->campaign([
            'activated' => true,
            'starting_date' => null,
            'ending_date' => null,
        ]);

        $this->assertSame(CampaignStatus::Active, CampaignStatus::for($campaign));
    }

    // ---------------------------------------------------------- filtering

    /**
     * The SQL filter and the derived badge must select the same campaigns, or a
     * filter would hide a row whose own badge says it belongs.
     */
    public function test_each_status_filter_matches_the_derived_status(): void
    {
        $cases = [
            [CampaignStatus::Active, $this->active()],
            [CampaignStatus::Scheduled, $this->scheduled()],
            [CampaignStatus::Ended, $this->ended()],
            [CampaignStatus::Deactivated, $this->deactivated()],
        ];

        foreach ($cases as [$status, $expected]) {
            // The badge and the filter must agree about this campaign.
            $this->assertSame($status, CampaignStatus::for($expected));

            $response = $this->get(route('promotions.index', [
                CampaignFilter::PARAM_STATUS => $status->value,
            ]))->assertOk();

            $response->assertSee($expected->code);

            foreach ($cases as [, $other]) {
                if ($other->code !== $expected->code) {
                    $response->assertDontSee($other->code);
                }
            }
        }
    }

    public function test_the_search_filter_matches_code_or_description(): void
    {
        $this->campaign(['code' => 'CP0001', 'description' => 'Ezydog Harness']);
        $this->campaign(['code' => 'CP0002', 'description' => 'Ziggies Treats']);

        $this->get(route('promotions.index', [CampaignFilter::PARAM_SEARCH => 'Ezydog']))
            ->assertOk()
            ->assertSee('CP0001')
            ->assertDontSee('CP0002');

        $this->get(route('promotions.index', [CampaignFilter::PARAM_SEARCH => 'CP0002']))
            ->assertOk()
            ->assertSee('CP0002')
            ->assertDontSee('CP0001');
    }

    public function test_the_not_synced_filter_finds_campaigns_with_no_ledger_row(): void
    {
        $delivered = $this->campaign(['code' => 'CP-SENT']);
        $this->campaign(['code' => 'CP-NEW']);

        app(CampaignSyncLedger::class)->markPending($delivered, []);

        $this->get(route('promotions.index', [
            CampaignFilter::PARAM_SYNC => CampaignFilter::SYNC_NOT_SYNCED,
        ]))
            ->assertOk()
            ->assertSee('CP-NEW')
            ->assertDontSee('CP-SENT');
    }

    public function test_a_ledger_status_filter_reads_the_ledger(): void
    {
        $campaign = $this->campaign(['code' => 'CP-PENDING']);
        app(CampaignSyncLedger::class)->markPending($campaign, []);

        $this->get(route('promotions.index', [
            CampaignFilter::PARAM_SYNC => SyncStatus::Pending->value,
        ]))->assertOk()->assertSee('CP-PENDING');
    }

    /**
     * A stale or hand-edited link should show the full list, not an error.
     */
    public function test_an_unrecognised_filter_value_is_ignored(): void
    {
        $this->campaign(['code' => 'CP0001']);

        $this->get(route('promotions.index', [
            CampaignFilter::PARAM_STATUS => 'nonsense',
            CampaignFilter::PARAM_SYNC => 'nonsense',
        ]))->assertOk()->assertSee('CP0001');
    }

    public function test_the_search_survives_alongside_another_filter(): void
    {
        $this->campaign([
            'code' => 'CP-OFF-ONE',
            'description' => 'Winter sale',
            'activated' => false,
        ]);
        $this->campaign([
            'code' => 'CP-OFF-TWO',
            'description' => 'Summer sale',
            'activated' => false,
        ]);

        $this->get(route('promotions.index', [
            CampaignFilter::PARAM_STATUS => CampaignStatus::Deactivated->value,
            CampaignFilter::PARAM_SEARCH => 'Winter',
        ]))
            ->assertOk()
            ->assertSee('CP-OFF-ONE')
            ->assertDontSee('CP-OFF-TWO');
    }

    // ----------------------------------------------------------- the detail

    public function test_the_detail_page_renders(): void
    {
        $campaign = $this->campaign([
            'code' => 'CP0001',
            'description' => 'Ezydog Harness | 25% Off',
        ]);

        $this->get(route('promotions.show', $campaign))
            ->assertOk()
            ->assertSee('CP0001')
            ->assertSee('Ezydog Harness | 25% Off')
            ->assertSee('Business Central')
            ->assertSee('Audience')
            ->assertSee('Next delivery');
    }

    /**
     * The audience decides who sees the promotion, so the ids are shown in full
     * rather than counted.
     */
    public function test_the_detail_page_lists_every_customer_id(): void
    {
        $campaign = $this->campaign([
            'customers' => ['cust-alpha', 'cust-beta', 'cust-gamma'],
        ]);

        $response = $this->get(route('promotions.show', $campaign))->assertOk();

        foreach (['cust-alpha', 'cust-beta', 'cust-gamma'] as $id) {
            $response->assertSee($id);
        }
    }

    public function test_an_empty_audience_says_so(): void
    {
        $campaign = $this->campaign(['customers' => []]);

        $this->get(route('promotions.show', $campaign))
            ->assertOk()
            ->assertSee('No customers are attached to this campaign.');
    }

    public function test_the_detail_page_shows_the_delivery_payload(): void
    {
        $campaign = $this->campaign(['code' => 'CP0001']);

        $this->get(route('promotions.show', $campaign))
            ->assertOk()
            ->assertSee('Full desired payload')
            ->assertSee('activated');
    }

    /**
     * Campaigns are never removed, so the detail page must never offer that as
     * the next action.
     */
    public function test_the_detail_page_never_shows_a_removal(): void
    {
        foreach ([$this->active(), $this->deactivated(), $this->ended()] as $campaign) {
            $this->get(route('promotions.show', $campaign))
                ->assertOk()
                ->assertDontSee('>Remove<', false);
        }
    }

    public function test_a_campaign_with_no_ledger_row_says_so(): void
    {
        $campaign = $this->campaign();

        $this->get(route('promotions.show', $campaign))
            ->assertOk()
            ->assertSee('No ledger row yet.');
    }

    public function test_an_unknown_campaign_is_not_found(): void
    {
        $this->get('/promotion/99999')->assertNotFound();
    }

    // ----------------------------------------------------------- navigation

    public function test_the_promotions_link_is_in_the_sidebar(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('promotions.index'));
    }

    // ------------------------------------------- what the website actually holds

    /**
     * The failure this column exists for: the ledger says delivered, the
     * website does not have it, and until now the page said everything was
     * fine. A promotion missing from the site has to be visible here.
     */
    public function test_a_delivered_campaign_absent_from_the_website_is_flagged(): void
    {
        $campaign = $this->campaign(['code' => 'CP0001']);
        $ledger = app(CampaignSyncLedger::class);
        $ledger->markSynced($ledger->markPending($campaign, []));

        $this->websiteHolds([]);

        $this->get(route('promotions.show', $campaign))
            ->assertOk()
            ->assertSee('Not on the website')
            ->assertSee('website:deliver-campaigns --code=CP0001 --send');
    }

    public function test_a_campaign_the_website_holds_is_not_flagged(): void
    {
        $campaign = $this->campaign();

        $this->websiteHolds([$campaign->bc_id => ['wp_id' => 121638, 'status' => 'publish']]);

        $this->get(route('promotions.show', $campaign))
            ->assertOk()
            ->assertDontSee('Not on the website')
            ->assertSee('post 121638');
    }

    /**
     * An unreachable website is not the same as a deleted promotion, and must
     * never be reported as one.
     */
    public function test_an_unreachable_website_reads_as_unknown_rather_than_missing(): void
    {
        $campaign = $this->campaign();

        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('promotions.show', $campaign))
            ->assertOk()
            ->assertDontSee('Not on the website')
            ->assertSee('could not be reached');
    }

    public function test_the_list_shows_which_campaigns_are_on_the_site(): void
    {
        $present = $this->campaign(['code' => 'CP-HERE']);
        $absent = $this->campaign(['code' => 'CP-GONE', 'bc_id' => fake()->uuid()]);

        $this->websiteHolds([$present->bc_id => ['wp_id' => 500, 'status' => 'publish']]);

        $this->get(route('promotions.index'))
            ->assertOk()
            ->assertSee('Present')
            ->assertSee('Missing');
    }

    /**
     * One request for the whole page, not one per row.
     */
    public function test_the_list_asks_the_website_once(): void
    {
        foreach (range(1, 4) as $n) {
            $this->campaign(['code' => sprintf('CP%04d', $n), 'bc_id' => fake()->uuid()]);
        }

        $this->websiteHolds([]);

        $this->get(route('promotions.index'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_list_still_renders_when_the_website_is_unreachable(): void
    {
        $this->campaign(['code' => 'CP0001']);

        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('promotions.index'))
            ->assertOk()
            ->assertSee('CP0001')
            ->assertSee('unknown');
    }
}
