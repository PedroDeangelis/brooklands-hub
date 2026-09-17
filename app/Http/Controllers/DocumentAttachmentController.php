<?php

namespace App\Http\Controllers;

use App\DocumentAttachments\AttachmentParentType;
use App\DocumentAttachments\DocumentAttachmentFilter;
use App\Models\DocumentAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The document attachment list page.
 *
 * Read-only, like the rest of the dashboard: nothing here delivers, queues or
 * changes anything.
 *
 * There is no detail page, and deliberately so. An attachment has almost
 * nothing of its own to show — a filename, an id and a parent — and it is not
 * delivered on its own: it travels as a field of its parent's payload. The row
 * therefore links to the parent, which is where its delivery state actually
 * lives and where a person would go to act on it.
 */
class DocumentAttachmentController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $filter = DocumentAttachmentFilter::fromRequest($request);

        $attachments = $filter->apply(DocumentAttachment::query())
            ->orderBy('parent_type')
            ->orderBy('file_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('document-attachments.index', [
            'attachments' => $attachments,
            'filter' => $filter,
            'parents' => $this->parentsFor($attachments->getCollection()),
            'counts' => $this->countsByParentType(),
        ]);
    }

    /**
     * The local record behind each attachment on this page, in one query per type.
     *
     * Resolved here rather than per row so a page of 25 attachments costs three
     * queries rather than 25. Keyed by "type:bc_id" because a Business Central
     * id is only unique within a parent type.
     *
     * A missing key means the parent is not here, which the page shows as an
     * orphan rather than as a broken link.
     *
     * @param  Collection<int, DocumentAttachment>  $attachments
     * @return array<string, array{label: string, url: string}>
     */
    private function parentsFor(Collection $attachments): array
    {
        $parents = [];

        foreach ($attachments->groupBy('parent_type') as $parentType => $rows) {
            $type = AttachmentParentType::tryFrom((string) $parentType);

            if ($type === null) {
                continue;
            }

            $records = $type->modelClass()::query()
                ->whereIn('bc_id', $rows->pluck('parent_bc_id')->unique()->all())
                ->get();

            foreach ($records as $record) {
                $parents[$type->value.':'.$record->bc_id] = [
                    'label' => $type->labelFor($record),
                    'url' => route($type->routeName(), $record),
                ];
            }
        }

        return $parents;
    }

    /**
     * How many attachments hang off each kind of record.
     *
     * Shown as the filter tabs' counts, so the choice of filter says what it
     * would narrow to before it is clicked.
     *
     * @return array<string, int>
     */
    private function countsByParentType(): array
    {
        return DocumentAttachment::query()
            ->selectRaw('parent_type, count(*) as total')
            ->groupBy('parent_type')
            ->pluck('total', 'parent_type')
            ->all();
    }
}
