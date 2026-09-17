<?php

namespace App\Http\Controllers;

use App\Campaigns\CampaignFilter;
use App\Campaigns\CampaignStatus;
use App\Models\Campaign;
use App\Sync\CampaignSyncLedger;
use App\Sync\DeliveryStatus;
use App\Website\WebsiteClient;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * The promotion list and detail pages.
 *
 * Business Central calls these Campaigns; the website calls them Promotions,
 * and this dashboard uses the website's word because that is what a person
 * looking at it is trying to reason about.
 *
 * Read-only, like the rest of the dashboard: nothing here delivers, queues or
 * changes anything.
 */
class PromotionController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly CampaignSyncLedger $ledger,
        private readonly WebsiteClient $website,
    ) {}

    public function index(Request $request): View
    {
        $filter = CampaignFilter::fromRequest($request);

        $campaigns = $filter->apply(Campaign::query())
            ->with('websiteSyncRecord')
            ->orderBy('code')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('promotions.index', [
            'campaigns' => $campaigns,
            'filter' => $filter,
            'onWebsite' => $this->onWebsiteFor($campaigns->getCollection()),
        ]);
    }

    /**
     * What the website holds for a page of campaigns, in one request.
     *
     * One call for the whole page rather than one per row: the status route is
     * bounded at 200 ids and the list pages at 25, so a page costs a single
     * round trip.
     *
     * Null means the website could not be reached — shown as "unknown" rather
     * than as "missing", because those are very different things.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<string, array{wp_id: int, status: string}|null>|null
     */
    private function onWebsiteFor($campaigns): ?array
    {
        if ($campaigns->isEmpty()) {
            return [];
        }

        try {
            return $this->website->status(
                CampaignSyncLedger::ENTITY_CAMPAIGN,
                $campaigns->pluck('bc_id')->all(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the website actually holds for this campaign, right now.
     *
     * The ledger can only say what the website confirmed at the moment of
     * delivery. Anything that removes the post afterwards leaves that record
     * saying "synced" forever, which is how a promotion can read as fine on
     * this page while being absent from the site. Asking the website directly
     * is the only answer that cannot go stale.
     *
     * Returns null when the website could not be reached, which the page shows
     * as "unknown" rather than as "missing": an unreachable site is not the
     * same as a deleted promotion.
     *
     * @return array{wp_id: int, status: string}|null|false False when absent.
     */
    private function onWebsite(Campaign $campaign): array|false|null
    {
        try {
            $records = $this->website->status(
                CampaignSyncLedger::ENTITY_CAMPAIGN,
                [$campaign->bc_id],
            );
        } catch (Throwable) {
            // Never break the page over this: the rest of it is local data and
            // is still worth showing.
            return null;
        }

        return $records[$campaign->bc_id] ?? false;
    }

    public function show(Campaign $campaign): View
    {
        $syncRecord = $campaign->websiteSyncRecord()->first();

        return view('promotions.show', [
            'campaign' => $campaign,
            'status' => CampaignStatus::for($campaign),
            'syncRecord' => $syncRecord,
            'deliveryStatus' => DeliveryStatus::forCampaign($syncRecord),
            'payload' => $this->ledger->payloadFor($campaign),
            'plan' => $this->ledger->plan($campaign),
            'onWebsite' => $this->onWebsite($campaign),
        ]);
    }
}
