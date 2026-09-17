<?php

namespace App\Contacts;

use App\Enums\SyncStatus;
use App\Models\Contact;
use App\Sync\ContactSyncLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filters currently applied to the contact list.
 *
 * Reading, applying and describing the filters live together so a link, the
 * query that runs and the summary above the table can never disagree about what
 * a parameter means.
 *
 * Purely a read concern: these narrow what is listed and never change anything.
 */
final readonly class ContactFilter
{
    public const PARAM_SEARCH = 'search';

    public const PARAM_WEBSITE = 'website_status';

    public const PARAM_REASON = 'reason';

    public const PARAM_SYNC = 'sync_status';

    public const WEBSITE_ELIGIBLE = 'eligible';

    public const WEBSITE_EXCLUDED = 'excluded';

    /**
     * A delivery state that is a conclusion rather than a ledger status: the
     * contact has no ledger row at all.
     */
    public const SYNC_NOT_SYNCED = 'not_synced';

    private function __construct(
        public string $search,
        public ?string $websiteStatus,
        public ?ContactExclusionReason $reason,
        public ?string $syncStatus,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query(self::PARAM_SEARCH, '')),
            websiteStatus: self::oneOf($request, self::PARAM_WEBSITE, [
                self::WEBSITE_ELIGIBLE,
                self::WEBSITE_EXCLUDED,
            ]),
            reason: ContactExclusionReason::tryFrom((string) $request->query(self::PARAM_REASON, '')),
            syncStatus: self::oneOf($request, self::PARAM_SYNC, [
                SyncStatus::Pending->value,
                SyncStatus::Synced->value,
                SyncStatus::Failed->value,
                SyncStatus::Conflict->value,
                self::SYNC_NOT_SYNCED,
            ]),
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
            || $this->reason !== null
            || $this->syncStatus !== null;
    }

    /**
     * Narrow a contact query to the current filters.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function apply(Builder $query, ContactEligibility $eligibility): Builder
    {
        if ($this->search !== '') {
            $query = $this->applySearch($query);
        }

        if ($this->websiteStatus === self::WEBSITE_ELIGIBLE) {
            $query = $eligibility->scopeEligible($query);
        } elseif ($this->websiteStatus === self::WEBSITE_EXCLUDED) {
            $query = $eligibility->scopeExcluded($query);
        }

        if ($this->reason !== null) {
            $query = $eligibility->scopeWithReason($query, $this->reason);
        }

        if ($this->syncStatus !== null) {
            $query = $this->applySyncStatus($query);
        }

        return $query;
    }

    /**
     * Match the term against the number, name or email.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function applySearch(Builder $query): Builder
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('contacts.number', 'like', $term)
                ->orWhere('contacts.display_name', 'like', $term)
                ->orWhere('contacts.email', 'like', $term);
        });
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function applySyncStatus(Builder $query): Builder
    {
        if ($this->syncStatus === self::SYNC_NOT_SYNCED) {
            return $query->whereDoesntHave('websiteSyncRecord');
        }

        return $query->whereHas(
            'websiteSyncRecord',
            fn (Builder $records): Builder => $records->where('status', $this->syncStatus),
        );
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
            $chips[] = ['label' => 'Website', 'value' => ucfirst($this->websiteStatus), 'param' => self::PARAM_WEBSITE];
        }

        if ($this->reason !== null) {
            $chips[] = ['label' => 'Reason', 'value' => $this->reason->label(), 'param' => self::PARAM_REASON];
        }

        if ($this->syncStatus !== null) {
            $chips[] = ['label' => 'Sync', 'value' => $this->syncStatusLabel(), 'param' => self::PARAM_SYNC];
        }

        return $chips;
    }

    private function syncStatusLabel(): string
    {
        return $this->syncStatus === self::SYNC_NOT_SYNCED
            ? 'Not synced'
            : ucfirst((string) $this->syncStatus);
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
            self::PARAM_REASON => $this->reason?->value,
            self::PARAM_SYNC => $this->syncStatus,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The current filters minus one, for a link that removes a single chip.
     *
     * @return array<string, string>
     */
    public function without(string $param): array
    {
        $query = $this->toQuery();
        unset($query[$param]);

        return $query;
    }

    /**
     * The channel these filters read delivery state from.
     */
    public function channel(): string
    {
        return ContactSyncLedger::CHANNEL_WEBSITE;
    }
}
