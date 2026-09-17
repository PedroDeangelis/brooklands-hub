@php
    use App\SalesInvoices\SalesInvoiceFilter;
    use App\SalesInvoices\SalesInvoiceKind;
    use App\Sync\DeliveryStatus;

    // One list serves both sidebar entries; the kind filter names the page.
    $heading = match ($filter->kind) {
        SalesInvoiceKind::CreditMemo => 'Sales credit memos',
        default => 'Sales invoices',
    };
@endphp

<x-layout :title="$heading">
    <div class="flex flex-col gap-1.5">
        <h1 class="text-[32px] font-extrabold tracking-tight">{{ $heading }}</h1>
        <p class="text-[15px] text-ink-muted">
            <span class="tabular-nums">{{ number_format($salesInvoices->total()) }}</span>
            @if ($filter->kind === SalesInvoiceKind::CreditMemo && $filter->search === '' && $filter->syncStatus === null)
                posted credit memos imported from Business Central
            @elseif ($filter->isActive())
                matching {{ Str::plural('document', $salesInvoices->total()) }}
            @else
                posted invoices and credit memos imported from Business Central
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('sales-invoices.index') }}" class="flex items-center gap-3">
        <div class="flex h-11.5 grow items-center gap-2.5 rounded-xl border border-line bg-surface px-3.5
                    focus-within:border-brand focus-within:ring-1 focus-within:ring-brand">
            <svg class="size-4.25 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
            </svg>
            <label class="sr-only" for="list-search">Filter by invoice or credit memo number, order number, customer or PO number</label>
            <input id="list-search" type="search" name="{{ SalesInvoiceFilter::PARAM_SEARCH }}" value="{{ $filter->search }}"
                   placeholder="Filter by invoice or credit memo number, order number, customer or PO number"
                   class="grow bg-transparent text-sm text-ink outline-none placeholder:text-ink-faint">
        </div>

        {{-- Searching must narrow the current view rather than replace it. --}}
        @foreach ($filter->without(SalesInvoiceFilter::PARAM_SEARCH) as $param => $value)
            <input type="hidden" name="{{ $param }}" value="{{ $value }}">
        @endforeach

        <button type="submit"
                class="h-11.5 shrink-0 rounded-xl bg-brand px-4.5 text-sm font-semibold text-white hover:bg-brand-deep">
            Search
        </button>

        @if ($filter->isActive())
            <a href="{{ route('sales-invoices.index') }}"
               class="flex h-11.5 shrink-0 items-center rounded-xl border border-line bg-surface px-4.5 text-sm font-medium text-ink hover:bg-fill">
                Clear filters
            </a>
        @endif
    </form>

    <div class="flex flex-wrap items-center gap-2" aria-label="Filter by kind">
        @foreach (SalesInvoiceKind::cases() as $case)
            <a href="{{ route('sales-invoices.index', $filter->without(SalesInvoiceFilter::PARAM_KIND) + [SalesInvoiceFilter::PARAM_KIND => $case->value]) }}"
               @class([
                   'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13px] font-medium',
                   'border-brand bg-brand-soft text-brand-ink' => $filter->kind === $case,
                   'border-line bg-surface text-ink hover:bg-fill' => $filter->kind !== $case,
               ])>
                {{ $case->label() }}
            </a>
        @endforeach
    </div>

    @if ($filter->isActive())
        <div class="flex flex-wrap items-center gap-2.5" aria-label="Active filters">
            <span class="text-[13px] font-medium text-ink-muted">Filters</span>

            @foreach ($filter->chips() as $chip)
                <span class="inline-flex items-center gap-2 rounded-full border border-line bg-surface py-1 pr-1.5 pl-3 text-[13px]">
                    <span class="text-ink-muted">{{ $chip['label'] }}:</span>
                    <span class="font-semibold text-ink">{{ $chip['value'] }}</span>
                    <a href="{{ route('sales-invoices.index', $filter->without($chip['param'])) }}"
                       class="flex size-4.5 items-center justify-center rounded-full text-ink-muted hover:bg-fill hover:text-ink"
                       aria-label="Remove {{ $chip['label'] }} filter">
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6 18 18"></path><path d="M18 6 6 18"></path>
                        </svg>
                    </a>
                </span>
            @endforeach

            <a href="{{ route('sales-invoices.index') }}" class="text-[13px] font-semibold text-brand-ink hover:text-brand-deep">
                Clear filters
            </a>
        </div>
    @endif

    <section aria-label="Sales invoice table" class="flex flex-col rounded-[14px] border border-line bg-surface p-3">
        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl border-collapse text-sm">
                <thead>
                    <tr class="bg-fill-strong text-left text-[13px] font-medium text-ink-muted">
                        <th class="h-10.5 rounded-l-[9px] px-3.5 font-medium">Number</th>
                        <th class="px-3.5 font-medium">Kind</th>
                        <th class="px-3.5 font-medium">Order</th>
                        <th class="px-3.5 font-medium">Customer</th>
                        <th class="px-3.5 font-medium">Date</th>
                        <th class="px-3.5 text-right font-medium">Lines</th>
                        <th class="px-3.5 text-right font-medium">Total excl. tax</th>
                        <th class="px-3.5 font-medium">Delivery</th>
                        <th class="px-3.5 font-medium">On site</th>
                        <th class="rounded-r-[9px] px-3.5 font-medium">BC modified</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($salesInvoices as $salesInvoice)
                        @php
                            $delivery = DeliveryStatus::forSalesInvoice($salesInvoice->websiteSyncRecord);
                            $lines = count($salesInvoice->lines());
                        @endphp

                        <tr class="border-b border-line-soft hover:bg-[#f7faf8]">
                            <td class="h-13.5 px-3.5 font-mono">
                                <a href="{{ route('sales-invoices.show', $salesInvoice) }}"
                                   class="font-medium text-brand-ink hover:text-brand-deep">{{ $salesInvoice->number }}</a>
                            </td>
                            <td class="px-3.5">
                                <x-sales-invoice-kind-badge :kind="$salesInvoice->kind()" />
                            </td>
                            <td class="px-3.5 font-mono text-[13px] text-ink-muted">{{ $salesInvoice->order_number ?: '—' }}</td>
                            <td class="max-w-0 truncate px-3.5">{{ $salesInvoice->customer_name ?: '—' }}</td>
                            <td class="px-3.5 text-[13px] text-ink-muted tabular-nums">{{ $salesInvoice->invoice_date?->format('d M Y') ?? '—' }}</td>
                            <td @class([
                                'px-3.5 text-right tabular-nums',
                                'text-ink-faint' => $lines === 0,
                            ])>{{ number_format($lines) }}</td>
                            <td class="px-3.5 text-right tabular-nums">{{ number_format((float) $salesInvoice->total_amount_excluding_tax, 2) }}</td>
                            <td class="px-3.5">
                                <x-status-badge :status="$delivery" />
                            </td>
                            <td class="px-3.5">
                                @php($present = $onWebsite === null ? null : ($onWebsite[$salesInvoice->bc_id] ?? false))
                                @if ($present === null)
                                    <span class="text-[13px] text-ink-faint">unknown</span>
                                @elseif ($present === false)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-failed-soft px-2.5 py-1 text-xs font-semibold text-failed-ink"
                                          title="The ledger says delivered, but the website does not hold this invoice.">
                                        <span class="size-1.5 rounded-full bg-failed"></span>
                                        Missing
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-eligible-soft px-2.5 py-1 text-xs font-semibold text-eligible-ink"
                                          title="Post {{ $present['wp_id'] }} ({{ $present['status'] }})">
                                        <span class="size-1.5 rounded-full bg-eligible"></span>
                                        Present
                                    </span>
                                @endif
                            </td>
                            <td class="px-3.5 text-[13px] text-ink-muted tabular-nums">
                                {{ $salesInvoice->bc_modified_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-6 py-14 text-center">
                                <span class="flex flex-col items-center gap-1.5">
                                    <span class="text-[15px] font-semibold">No {{ mb_strtolower($heading) }} match</span>
                                    <span class="text-sm text-ink-muted">
                                        @if ($filter->kind === SalesInvoiceKind::CreditMemo && $filter->search === '' && $filter->syncStatus === null)
                                            No sales credit memos have been imported yet.
                                        @elseif ($filter->isActive())
                                            Try a different search or clear the filters.
                                        @else
                                            No sales invoices or credit memos have been imported yet.
                                        @endif
                                    </span>
                                </span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($salesInvoices->hasPages())
            <div class="px-3.5 pt-3.5">
                {{ $salesInvoices->links() }}
            </div>
        @endif
    </section>
</x-layout>
