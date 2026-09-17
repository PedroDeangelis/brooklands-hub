@php
    use App\DocumentAttachments\AttachmentParentType;
    use App\DocumentAttachments\DocumentAttachmentFilter;
@endphp

<x-layout title="Attachments">
    <div class="flex flex-col gap-1.5">
        <h1 class="text-[32px] font-extrabold tracking-tight">Attachments</h1>
        <p class="text-[15px] text-ink-muted">
            <span class="tabular-nums">{{ number_format($attachments->total()) }}</span>
            @if ($filter->isActive())
                matching {{ Str::plural('file', $attachments->total()) }}
            @else
                {{ Str::plural('file', $attachments->total()) }} attached to products, customers and sales orders in Business Central
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('document-attachments.index') }}" class="flex items-center gap-3">
        <div class="flex h-11.5 grow items-center gap-2.5 rounded-xl border border-line bg-surface px-3.5
                    focus-within:border-brand focus-within:ring-1 focus-within:ring-brand">
            <svg class="size-4.25 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
            </svg>
            <label class="sr-only" for="list-search">Filter by file name or Business Central id</label>
            <input id="list-search" type="search" name="{{ DocumentAttachmentFilter::PARAM_SEARCH }}" value="{{ $filter->search }}"
                   placeholder="Filter by file name or Business Central id"
                   class="grow bg-transparent text-sm text-ink outline-none placeholder:text-ink-faint">
        </div>

        {{-- Searching must narrow the current view rather than replace it. --}}
        @foreach ($filter->without(DocumentAttachmentFilter::PARAM_SEARCH) as $param => $value)
            <input type="hidden" name="{{ $param }}" value="{{ $value }}">
        @endforeach

        <button type="submit"
                class="h-11.5 shrink-0 rounded-xl bg-brand px-4.5 text-sm font-semibold text-white hover:bg-brand-deep">
            Search
        </button>

        @if ($filter->isActive())
            <a href="{{ route('document-attachments.index') }}"
               class="flex h-11.5 shrink-0 items-center rounded-xl border border-line bg-surface px-4.5 text-sm font-medium text-ink hover:bg-fill">
                Clear filters
            </a>
        @endif
    </form>

    <div class="flex flex-wrap items-center gap-2" aria-label="Filter by what the file is attached to">
        @foreach (AttachmentParentType::cases() as $case)
            <a href="{{ route('document-attachments.index', $filter->without(DocumentAttachmentFilter::PARAM_PARENT_TYPE) + [DocumentAttachmentFilter::PARAM_PARENT_TYPE => $case->value]) }}"
               @class([
                   'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13px] font-medium',
                   'border-brand bg-brand-soft text-brand-ink' => $filter->parentType === $case,
                   'border-line bg-surface text-ink hover:bg-fill' => $filter->parentType !== $case,
               ])>
                {{ $case->label() }}
                <span class="tabular-nums text-ink-muted">{{ number_format($counts[$case->value] ?? 0) }}</span>
            </a>
        @endforeach

        {{-- Files whose parent is no longer here. Worth its own filter because
             it is the one state a person has to act on: the file points at a
             record this application cannot show or deliver. --}}
        <a href="{{ route('document-attachments.index', $filter->without(DocumentAttachmentFilter::PARAM_ORPHANED) + [DocumentAttachmentFilter::PARAM_ORPHANED => '1']) }}"
           @class([
               'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13px] font-medium',
               'border-brand bg-brand-soft text-brand-ink' => $filter->orphaned,
               'border-line bg-surface text-ink hover:bg-fill' => ! $filter->orphaned,
           ])>
            Parent missing
        </a>
    </div>

    @if ($filter->isActive())
        <div class="flex flex-wrap items-center gap-2.5" aria-label="Active filters">
            <span class="text-[13px] font-medium text-ink-muted">Filters</span>

            @foreach ($filter->chips() as $chip)
                <span class="inline-flex items-center gap-2 rounded-full border border-line bg-surface py-1 pr-1.5 pl-3 text-[13px]">
                    <span class="text-ink-muted">{{ $chip['label'] }}:</span>
                    <span class="font-semibold text-ink">{{ $chip['value'] }}</span>
                    <a href="{{ route('document-attachments.index', $filter->without($chip['param'])) }}"
                       class="flex size-4.5 items-center justify-center rounded-full text-ink-muted hover:bg-fill hover:text-ink"
                       aria-label="Remove {{ $chip['label'] }} filter">
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6 18 18"></path><path d="M18 6 6 18"></path>
                        </svg>
                    </a>
                </span>
            @endforeach

            <a href="{{ route('document-attachments.index') }}" class="text-[13px] font-semibold text-brand-ink hover:text-brand-deep">
                Clear filters
            </a>
        </div>
    @endif

    <section aria-label="Attachment table" class="flex flex-col rounded-[14px] border border-line bg-surface p-3">
        <div class="overflow-x-auto">
            <table class="w-full min-w-4xl border-collapse text-sm">
                <thead>
                    <tr class="bg-fill-strong text-left text-[13px] font-medium text-ink-muted">
                        <th class="h-10.5 rounded-l-[9px] px-3.5 font-medium">File</th>
                        <th class="px-3.5 font-medium">Type</th>
                        <th class="px-3.5 font-medium">Attached to</th>
                        <th class="px-3.5 font-medium">Record</th>
                        <th class="rounded-r-[9px] px-3.5 font-medium">BC modified</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($attachments as $attachment)
                        @php
                            $parentType = $attachment->parentType();
                            $parent = $parentType === null
                                ? null
                                : ($parents[$parentType->value.':'.$attachment->parent_bc_id] ?? null);
                            $extension = $attachment->extension();
                        @endphp

                        <tr class="border-b border-line-soft hover:bg-[#f7faf8]">
                            <td class="h-13.5 max-w-0 truncate px-3.5">
                                {{-- Links straight at the signed route that streams the
                                     file from Business Central, which is the same thing
                                     the website links its visitors at. --}}
                                <a href="{{ url('/bc-doc/'.$attachment->bc_id) }}" target="_blank" rel="noopener"
                                   class="font-medium text-brand-ink hover:text-brand-deep">{{ $attachment->file_name }}</a>
                            </td>
                            <td class="px-3.5">
                                @if ($extension === '')
                                    <span class="text-[13px] text-ink-faint">—</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-fill px-2.5 py-1 font-mono text-xs font-semibold text-ink-muted uppercase">
                                        {{ $extension }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-3.5 text-[13px] text-ink-muted">{{ $parentType?->label() ?? '—' }}</td>
                            <td class="max-w-0 truncate px-3.5">
                                @if ($parent !== null)
                                    <a href="{{ $parent['url'] }}" class="text-brand-ink hover:text-brand-deep">{{ $parent['label'] }}</a>
                                @else
                                    {{-- The file points at a record this application does
                                         not hold, so there is nothing to link to and
                                         nothing that will deliver it. --}}
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-failed-soft px-2.5 py-1 text-xs font-semibold text-failed-ink"
                                          title="Business Central id {{ $attachment->parent_bc_id }}">
                                        <span class="size-1.5 rounded-full bg-failed"></span>
                                        Parent missing
                                    </span>
                                @endif
                            </td>
                            <td class="px-3.5 text-[13px] text-ink-muted tabular-nums">
                                {{ $attachment->bc_modified_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-14 text-center">
                                <span class="flex flex-col items-center gap-1.5">
                                    <span class="text-[15px] font-semibold">No attachments match</span>
                                    <span class="text-sm text-ink-muted">
                                        @if ($filter->isActive())
                                            Try a different search or clear the filters.
                                        @else
                                            No document attachments have been imported yet.
                                        @endif
                                    </span>
                                </span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($attachments->hasPages())
            <div class="px-3.5 pt-3.5">
                {{ $attachments->links() }}
            </div>
        @endif
    </section>
</x-layout>
