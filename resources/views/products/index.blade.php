@php
    use App\Products\ProductFilter;
@endphp

<x-layout title="Products">
    <div class="flex flex-col gap-1.5">
        <h1 class="text-[32px] font-extrabold tracking-tight">Products</h1>
        <p class="text-[15px] text-ink-muted">
            <span class="tabular-nums">{{ number_format($products->total()) }}</span>
            @if ($filter->isActive())
                matching {{ Str::plural('product', $products->total()) }}
            @else
                products imported from Business Central
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('products.index') }}" class="flex items-center gap-3">
        <div class="flex h-11.5 grow items-center gap-2.5 rounded-xl border border-line bg-surface px-3.5
                    focus-within:border-brand focus-within:ring-1 focus-within:ring-brand">
            <svg class="size-4.25 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
            </svg>
            <label class="sr-only" for="list-search">Filter by SKU or product name</label>
            <input id="list-search" type="search" name="{{ ProductFilter::PARAM_SEARCH }}" value="{{ $filter->search }}"
                   placeholder="Filter by SKU or product name"
                   class="grow bg-transparent text-sm text-ink outline-none placeholder:text-ink-faint">
        </div>

        {{-- Searching must narrow the current view rather than replace it. --}}
        @foreach ($filter->without(ProductFilter::PARAM_SEARCH) as $param => $value)
            <input type="hidden" name="{{ $param }}" value="{{ $value }}">
        @endforeach

        <button type="submit"
                class="h-11.5 shrink-0 rounded-xl bg-brand px-4.5 text-sm font-semibold text-white hover:bg-brand-deep">
            Search
        </button>

        @if ($filter->isActive())
            <a href="{{ route('products.index') }}"
               class="flex h-11.5 shrink-0 items-center rounded-xl border border-line bg-surface px-4.5 text-sm font-medium text-ink hover:bg-fill">
                Clear filters
            </a>
        @endif
    </form>

    @if ($filter->isActive())
        <div class="flex flex-wrap items-center gap-2.5" aria-label="Active filters">
            <span class="text-[13px] font-medium text-ink-muted">Filters</span>

            @foreach ($filter->chips() as $chip)
                <span class="inline-flex items-center gap-2 rounded-full border border-line bg-surface py-1 pr-1.5 pl-3 text-[13px]">
                    <span class="text-ink-muted">{{ $chip['label'] }}:</span>
                    <span class="font-semibold text-ink">{{ $chip['value'] }}</span>
                    <a href="{{ route('products.index', $filter->without($chip['param'])) }}"
                       class="flex size-4.5 items-center justify-center rounded-full text-ink-muted hover:bg-fill hover:text-ink"
                       aria-label="Remove {{ $chip['label'] }} filter">
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6 18 18"></path><path d="M18 6 6 18"></path>
                        </svg>
                    </a>
                </span>
            @endforeach

            <a href="{{ route('products.index') }}" class="text-[13px] font-semibold text-brand-ink hover:text-brand-deep">
                Clear filters
            </a>
        </div>
    @endif

    <section aria-label="Product table" class="flex flex-col rounded-[14px] border border-line bg-surface p-3">
        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl border-collapse text-sm">
                <thead>
                    <tr class="bg-fill-strong text-left text-[13px] font-medium text-ink-muted">
                        <th class="h-10.5 rounded-l-[9px] px-3.5 font-medium">SKU</th>
                        <th class="px-3.5 font-medium">Product name</th>
                        <th class="px-3.5 text-right font-medium">Price</th>
                        <th class="px-3.5 text-right font-medium">Inventory</th>
                        <th class="px-3.5 font-medium">Website</th>
                        <th class="px-3.5 font-medium">Desired</th>
                        <th class="px-3.5 font-medium">Delivery</th>
                        <th class="rounded-r-[9px] px-3.5 font-medium">BC modified</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($products as $product)
                        @php
                            $status = $eligibility->for($product);
                            $delivery = App\Sync\DeliveryStatus::for($product, $eligibility, $product->itemsSyncRecord);
                            $desired = App\Sync\DesiredWebsiteState::from($status);
                        @endphp

                        <tr class="border-b border-line-soft hover:bg-[#f7faf8]">
                            <td class="h-13.5 px-3.5 font-mono">
                                <a href="{{ route('products.show', $product) }}"
                                   class="font-medium text-brand-ink hover:text-brand-deep">{{ $product->sku }}</a>
                            </td>
                            <td class="max-w-0 truncate px-3.5">{{ $product->name }}</td>
                            <td class="px-3.5 text-right tabular-nums">{{ number_format((float) $product->price, 2) }}</td>
                            <td @class([
                                'px-3.5 text-right tabular-nums',
                                'text-ink-faint' => (float) $product->inventory === 0.0,
                            ])>{{ number_format((float) $product->inventory, 2) }}</td>
                            <td class="px-3.5">
                                <span class="flex flex-col items-start gap-1">
                                    <x-eligibility-badge :eligible="$status->eligible" :label="$status->label()" />
                                    @if (! $status->eligible)
                                        <span class="text-[11px] leading-tight text-ink-muted" title="{{ implode('; ', $status->reasons()) }}">
                                            {{ $status->reason() }}@if ($status->reasonCount() > 1) <span class="font-semibold">+{{ $status->reasonCount() - 1 }} more</span>@endif
                                        </span>
                                    @endif
                                </span>
                            </td>
                            <td class="px-3.5">
                                <x-action-badge :action="$desired->action" />
                            </td>
                            <td class="px-3.5">
                                <x-status-badge :status="$delivery" />
                            </td>
                            <td class="px-3.5 text-[13px] text-ink-muted tabular-nums">
                                {{ $product->bc_modified_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-14 text-center">
                                <span class="flex flex-col items-center gap-1.5">
                                    <span class="text-[15px] font-semibold">No products match</span>
                                    <span class="text-sm text-ink-muted">
                                        @if ($filter->isActive())
                                            Try a different search or clear the filters.
                                        @else
                                            No products have been imported yet.
                                        @endif
                                    </span>
                                </span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="px-3.5 pt-3.5">
                {{ $products->links() }}
            </div>
        @endif
    </section>
</x-layout>
