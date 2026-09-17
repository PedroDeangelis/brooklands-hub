<?php

namespace App\DocumentAttachments;

use App\Models\DocumentAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filters currently applied to the document attachment list.
 *
 * Reading, applying and describing the filters live together so a link, the
 * query that runs and the summary above the table can never disagree about what
 * a parameter means.
 *
 * There is no sync filter here, unlike the other lists. An attachment is not
 * delivered on its own — it travels as a field of its parent's payload — so it
 * has no ledger row of its own to filter on. What it has instead is a parent,
 * and whether that parent has arrived is the question actually worth asking.
 *
 * Purely a read concern: these narrow what is listed and never change anything.
 */
final readonly class DocumentAttachmentFilter
{
    public const PARAM_SEARCH = 'search';

    public const PARAM_PARENT_TYPE = 'parent_type';

    public const PARAM_ORPHANED = 'orphaned';

    private function __construct(
        public string $search,
        public ?AttachmentParentType $parentType,
        public bool $orphaned,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query(self::PARAM_SEARCH, '')),
            parentType: AttachmentParentType::tryFrom((string) $request->query(self::PARAM_PARENT_TYPE, '')),
            orphaned: $request->query(self::PARAM_ORPHANED) === '1',
        );
    }

    public function isActive(): bool
    {
        return $this->search !== ''
            || $this->parentType !== null
            || $this->orphaned;
    }

    /**
     * Narrow an attachment query to the current filters.
     *
     * @param  Builder<DocumentAttachment>  $query
     * @return Builder<DocumentAttachment>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

            $query = $query->where(function (Builder $query) use ($term): void {
                $query->where('file_name', 'like', $term)
                    ->orWhere('bc_id', 'like', $term)
                    ->orWhere('parent_bc_id', 'like', $term);
            });
        }

        if ($this->parentType !== null) {
            $query = $query->where('parent_type', $this->parentType->value);
        }

        if ($this->orphaned) {
            $query = $this->applyOrphaned($query);
        }

        return $query;
    }

    /**
     * Narrow to attachments whose parent has not been imported.
     *
     * These are rows the importer could not place, so nothing stored them —
     * which is exactly why the filter is built from the stored rows' parent
     * ids rather than from a join: an attachment is only ever written once its
     * parent exists. What this catches is the other direction, a parent that
     * has since been deleted locally, leaving files pointing at nothing.
     *
     * @param  Builder<DocumentAttachment>  $query
     * @return Builder<DocumentAttachment>
     */
    private function applyOrphaned(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach (AttachmentParentType::cases() as $type) {
                $query->orWhere(function (Builder $query) use ($type): void {
                    $query->where('parent_type', $type->value)
                        ->whereNotIn(
                            'parent_bc_id',
                            $type->modelClass()::query()->select('bc_id'),
                        );
                });
            }
        });
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

        if ($this->parentType !== null) {
            $chips[] = ['label' => 'Attached to', 'value' => $this->parentType->label(), 'param' => self::PARAM_PARENT_TYPE];
        }

        if ($this->orphaned) {
            $chips[] = ['label' => 'Parent', 'value' => 'Missing', 'param' => self::PARAM_ORPHANED];
        }

        return $chips;
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
            self::PARAM_PARENT_TYPE => $this->parentType?->value,
            self::PARAM_ORPHANED => $this->orphaned ? '1' : '',
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
}
