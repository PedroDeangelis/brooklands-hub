<?php

namespace App\Products;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
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

    public const PARAM_ACTION = 'action';

    public const WEBSITE_ELIGIBLE = 'eligible';

    public const WEBSITE_EXCLUDED = 'excluded';

    /**
     * Delivery states that are conclusions rather than ledger statuses.
     *
     * Both describe a product with no ledger row at all, so they are resolved
     * through eligibility instead.
     */
    public const SYNC_NOT_APPLICABLE = 'not_applicable';

    public const SYNC_NOT_SYNCED = 'not_synced';

    private function __construct(
        public string $search,
        public ?string $websiteStatus,
        public ?string $syncStatus,
        public ?ExclusionReason $reason,
        public ?WebsiteAction $action,
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
                SyncStatus::Conflict->value,
                self::SYNC_NOT_APPLICABLE,
                self::SYNC_NOT_SYNCED,
            ]),
            reason: ExclusionReason::tryFrom((string) $request->query(self::PARAM_REASON, '')),
            action: WebsiteAction::tryFrom((string) $request->query(self::PARAM_ACTION, '')),
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
            || $this->reason !== null
            || $this->action !== null;
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

        if ($this->action !== null) {
            $query = $this->applyAction($query, $eligibility);
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
     * Ledger states are read straight from the ledger and are NOT restricted to
     * eligible products: an excluded product that was delivered while it
     * qualified is pending a removal, and filtering it out here would hide
     * exactly the work someone came to this page to find.
     *
     * The two states that have no ledger row of their own are derived instead.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applySyncStatus(Builder $query, WebsiteEligibility $eligibility): Builder
    {
        $withoutLedgerRow = fn (Builder $query): Builder => $query->whereDoesntHave(
            'syncRecords',
            fn (Builder $records): Builder => $records->where('channel', SyncLedger::CHANNEL_ITEMS),
        );

        // Nothing to deliver: never queued, and not wanted on the website.
        if ($this->syncStatus === self::SYNC_NOT_APPLICABLE) {
            return $withoutLedgerRow($eligibility->scopeExcluded($query));
        }

        // Wanted on the website but never queued.
        if ($this->syncStatus === self::SYNC_NOT_SYNCED) {
            return $withoutLedgerRow($eligibility->scopeEligible($query));
        }

        return $query->whereHas('syncRecords', fn (Builder $records): Builder => $records
            ->where('channel', SyncLedger::CHANNEL_ITEMS)
            ->where('status', $this->syncStatus));
    }

    /**
     * Narrow to the website action a product currently wants.
     *
     * The desired action follows from eligibility, so it is resolved from the
     * product rather than from the ledger: a product that has never been
     * queued still wants something.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applyAction(Builder $query, WebsiteEligibility $eligibility): Builder
    {
        return $this->action === WebsiteAction::Remove
            ? $eligibility->scopeExcluded($query)
            : $eligibility->scopeEligible($query);
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

        if ($this->action !== null) {
            $chips[] = ['label' => 'Action', 'value' => $this->action->label(), 'param' => self::PARAM_ACTION];
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
            self::PARAM_ACTION => $this->action?->value,
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
