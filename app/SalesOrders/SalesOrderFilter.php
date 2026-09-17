<?php

namespace App\SalesOrders;

use App\Enums\SyncStatus;
use App\Models\SalesOrder;
use App\Sync\SalesOrderSyncLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filters currently applied to the sales order list.
 *
 * Reading, applying and describing the filters live together so a link, the
 * query that runs and the summary above the table can never disagree about what
 * a parameter means.
 *
 * Purely a read concern: these narrow what is listed and never change anything.
 */
final readonly class SalesOrderFilter
{
    public const PARAM_SEARCH = 'search';

    public const PARAM_STATUS = 'status';

    public const PARAM_SYNC = 'sync_status';

    /**
     * A delivery state that is a conclusion rather than a ledger status: the
     * order has no ledger row at all.
     */
    public const SYNC_NOT_SYNCED = 'not_synced';

    private function __construct(
        public string $search,
        public ?SalesOrderStatus $status,
        public ?string $syncStatus,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query(self::PARAM_SEARCH, '')),
            status: SalesOrderStatus::tryFrom((string) $request->query(self::PARAM_STATUS, '')),
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
            || $this->status !== null
            || $this->syncStatus !== null;
    }

    /**
     * Narrow a sales order query to the current filters.
     *
     * @param  Builder<SalesOrder>  $query
     * @return Builder<SalesOrder>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->search !== '') {
            $query = $this->applySearch($query);
        }

        if ($this->status !== null) {
            // The stored website status, written by the importer from the
            // same rule the badge shows, so the filter and the badge agree.
            $query = $query->where('website_status', $this->status->value);
        }

        if ($this->syncStatus !== null) {
            $query = $this->applySyncStatus($query);
        }

        return $query;
    }

    /**
     * Match the term against the number, customer name or PO number.
     *
     * @param  Builder<SalesOrder>  $query
     * @return Builder<SalesOrder>
     */
    private function applySearch(Builder $query): Builder
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('number', 'like', $term)
                ->orWhere('customer_name', 'like', $term)
                ->orWhere('external_document_number', 'like', $term);
        });
    }

    /**
     * Narrow to a delivery state.
     *
     * @param  Builder<SalesOrder>  $query
     * @return Builder<SalesOrder>
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

        if ($this->status !== null) {
            $chips[] = ['label' => 'Status', 'value' => $this->status->label(), 'param' => self::PARAM_STATUS];
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
            self::PARAM_STATUS => $this->status?->value,
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
        return SalesOrderSyncLedger::CHANNEL_WEBSITE;
    }
}
