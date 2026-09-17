<?php

namespace App\Http\Controllers;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\ExclusionReason;
use App\Products\ProductFilter;
use App\Products\WebsiteEligibility;
use App\Sync\SyncLedger;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly WebsiteEligibility $eligibility,
    ) {}

    public function __invoke(): View
    {
        return view('dashboard', [
            'totalProducts' => Product::query()->count(),
            'pending' => $this->countByStatus(SyncStatus::Pending),
            'synced' => $this->countByStatus(SyncStatus::Synced),
            'failed' => $this->countByStatus(SyncStatus::Failed),
            'conflicts' => $this->countByStatus(SyncStatus::Conflict),
            'excluded' => $this->eligibility->scopeExcluded(Product::query())->count(),
            'exclusionBreakdown' => $this->exclusionBreakdown(),
            'environment' => $this->environment(),
        ]);
    }

    /**
     * Where this instance reads from and writes to.
     *
     * Shown because the two can disagree: a Sandbox catalogue delivered against
     * a Production-derived website produces identity conflicts that look like
     * bugs until you can see which environments are paired. Names and hosts
     * only — never a secret or a credential.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $websiteUrl = (string) config('services.website.url');
        $host = $websiteUrl === '' ? 'not configured' : (parse_url($websiteUrl, PHP_URL_HOST) ?: $websiteUrl);

        return [
            'Business Central' => (string) (config('services.bc.instance') ?: 'not configured'),
            'Website' => $host,
        ];
    }

    /**
     * How many excluded products fail each rule.
     *
     * A product can fail several rules, so these counts overlap and deliberately
     * sum to more than the excluded total: the point is to show which rules are
     * actually keeping products off the website.
     *
     * @return list<array{reason: ExclusionReason, label: string, count: int, url: string}>
     */
    private function exclusionBreakdown(): array
    {
        $counts = [];

        foreach (ExclusionReason::cases() as $reason) {
            $counts[$reason->value] = 0;
        }

        $this->eligibility->scopeExcluded(Product::query())
            ->select(['id', 'blocked', 'item_category_id', 'price', 'type', 'gppg'])
            ->chunkById(500, function ($products) use (&$counts): void {
                foreach ($products as $product) {
                    foreach ($this->eligibility->for($product)->exclusions as $exclusion) {
                        $counts[$exclusion->reason->value]++;
                    }
                }
            });

        $breakdown = [];

        foreach (ExclusionReason::cases() as $reason) {
            if ($counts[$reason->value] > 0) {
                $breakdown[] = [
                    'reason' => $reason,
                    'label' => $reason->label(),
                    'count' => $counts[$reason->value],
                    'url' => route('products.index', [ProductFilter::PARAM_REASON => $reason->value]),
                ];
            }
        }

        usort($breakdown, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $breakdown;
    }

    /**
     * Count ledger rows for products that still qualify for the website.
     *
     * A product can lose its eligibility after being queued. Its ledger row
     * survives, but it is no longer waiting for anything, so counting it here
     * would leave a permanent backlog that nothing can clear. The detail page
     * reports it as not applicable, and this count agrees with it.
     */
    private function countByStatus(SyncStatus $status): int
    {
        return SyncRecord::query()
            ->forChannel(SyncLedger::CHANNEL_ITEMS)
            ->withStatus($status)
            ->whereIn(
                'bc_id',
                $this->eligibility->scopeEligible(Product::query())->select('bc_id'),
            )
            ->count();
    }
}
