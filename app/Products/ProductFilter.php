<?php

namespace App\Products;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Sync\SyncLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filters currently applied to the product list.
 *
 * Reading, applying and describing the filters live together so a link built on
 * the dashboard, the query that runs, and the summary shown above the table can
 * never disagree about what a parameter means.
 *
 * Purely a read concern: these narrow what is listed and never change anything.
 */
final readonly class ProductFilter
{
    public const PARAM_SEARCH = 'search';

    public const PARAM_WEBSITE = 'website_status';

    public const PARAM_SYNC = 'sync_status';

    public const PARAM_REASON = 'reason';

    public const WEBSITE_ELIGIBLE = 'eligible';

    public const WEBSITE_EXCLUDED = 'excluded';

    /**
     * Delivery states that can be filtered on.
     *
     * "not_applicable" is not a ledger status but a conclusion about excluded
     * products, so it is resolved through eligibility rather than the ledger.
     */
    public const SYNC_NOT_APPLICABLE = 'not_applicable';

    public const SYNC_NOT_SYNCED = 'not_synced';

    private function __construct(
        public string $search,
        public ?string $websiteStatus,
        public ?string $syncStatus,
        public ?ExclusionReason $reason,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query(self::PARAM_SEARCH, '')),
            websiteStatus: self::oneOf($request, self::PARAM_WEBSITE, [
                self::WEBSITE_ELIGIBLE,
                self::WEBSITE_EXCLUDED,
            ]),
            syncStatus: self::oneOf($request, self::PARAM_SYNC, [
                SyncStatus::Pending->value,
                SyncStatus::Synced->value,
                SyncStatus::Failed->value,
                self::SYNC_NOT_APPLICABLE,
                self::SYNC_NOT_SYNCED,
            ]),
            reason: ExclusionReason::tryFrom((string) $request->query(self::PARAM_REASON, '')),
        );
    }

    /**
     * Read a parameter only when it is one of the values we understand.
     *
     * An unrecognised value is dropped rather than rejected: a stale or
     * hand-edited link should show the full list, not an error page.
     *
     * @param  array<int, string>  $allowed
     */
    private static function oneOf(Request $request, string $key, array $allowed): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return in_array($value, $allowed, true) ? $value : null;
    }

    public function isActive(): bool
    {
        return $this->search !== ''
            || $this->websiteStatus !== null
            || $this->syncStatus !== null
            || $this->reason !== null;
    }

    /**
     * Narrow a product query to the current filters.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function apply(Builder $query, WebsiteEligibility $eligibility): Builder
    {
        if ($this->search !== '') {
            $query = $this->applySearch($query);
        }

        if ($this->websiteStatus === self::WEBSITE_EXCLUDED) {
            $query = $eligibility->scopeExcluded($query);
        }

        if ($this->websiteStatus === self::WEBSITE_ELIGIBLE) {
            $query = $eligibility->scopeEligible($query);
        }

        if ($this->reason !== null) {
            $query = $eligibility->scopeWithReason($query, $this->reason);
        }

        if ($this->syncStatus !== null) {
            $query = $this->applySyncStatus($query, $eligibility);
        }

        return $query;
    }

    /**
     * Match the term against the SKU or the product name.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applySearch(Builder $query): Builder
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('sku', 'like', $term)
                ->orWhere('name', 'like', $term);
        });
    }

    /**
     * Narrow to a delivery state.
     *
     * Delivery is only meaningful for a product that qualifies for the website,
     * so every ledger state is restricted to eligible products. That keeps the
     * list agreeing with the status shown on each product's detail page.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applySyncStatus(Builder $query, WebsiteEligibility $eligibility): Builder
    {
        if ($this->syncStatus === self::SYNC_NOT_APPLICABLE) {
            return $eligibility->scopeExcluded($query);
        }

        $query = $eligibility->scopeEligible($query);

        if ($this->syncStatus === self::SYNC_NOT_SYNCED) {
            return $query->whereDoesntHave(
                'syncRecords',
                fn (Builder $records): Builder => $records->where('channel', SyncLedger::CHANNEL_ITEMS),
            );
        }

        return $query->whereHas('syncRecords', fn (Builder $records): Builder => $records
            ->where('channel', SyncLedger::CHANNEL_ITEMS)
            ->where('status', $this->syncStatus));
    }

    /**
     * The active filters as chips to show above the table.
     *
     * @return list<array{label: string, value: string, param: string}>
     */
    public function chips(): array
    {
        $chips = [];

        if ($this->search !== '') {
            $chips[] = ['label' => 'Search', 'value' => $this->search, 'param' => self::PARAM_SEARCH];
        }

        if ($this->websiteStatus !== null) {
            $chips[] = [
                'label' => 'Website',
                'value' => $this->websiteStatus === self::WEBSITE_EXCLUDED ? 'Excluded' : 'Eligible',
                'param' => self::PARAM_WEBSITE,
            ];
        }

        if ($this->syncStatus !== null) {
            $chips[] = [
                'label' => 'Sync',
                'value' => $this->syncStatusLabel(),
                'param' => self::PARAM_SYNC,
            ];
        }

        if ($this->reason !== null) {
            $chips[] = ['label' => 'Reason', 'value' => $this->reason->label(), 'param' => self::PARAM_REASON];
        }

        return $chips;
    }

    private function syncStatusLabel(): string
    {
        return match ($this->syncStatus) {
            self::SYNC_NOT_APPLICABLE => 'Not applicable',
            self::SYNC_NOT_SYNCED => 'Not synced',
            default => ucfirst((string) $this->syncStatus),
        };
    }

    /**
     * The current filters as query parameters, for links that must keep them.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            self::PARAM_SEARCH => $this->search,
            self::PARAM_WEBSITE => $this->websiteStatus,
            self::PARAM_SYNC => $this->syncStatus,
            self::PARAM_REASON => $this->reason?->value,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The current filters with one removed, for a "remove this chip" link.
     *
     * @return array<string, string>
     */
    public function without(string $param): array
    {
        $query = $this->toQuery();
        unset($query[$param]);

        return $query;
    }
}
