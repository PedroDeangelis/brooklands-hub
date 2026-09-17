@php
    use App\Customers\CustomerFilter;
    use App\Customers\CustomerStatus;
    use App\Sync\DeliveryStatus;
@endphp

<x-layout title="Customers">
    <div class="flex flex-col gap-1.5">
        <h1 class="text-[32px] font-extrabold tracking-tight">Customers</h1>
        <p class="text-[15px] text-ink-muted">
            <span class="tabular-nums">{{ number_format($customers->total()) }}</span>
            @if ($filter->isActive())
                matching {{ Str::plural('customer', $customers->total()) }}
            @else
                customers imported from Business Central
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('customers.index') }}" class="flex items-center gap-3">
        <div class="flex h-11.5 grow items-center gap-2.5 rounded-xl border border-line bg-surface px-3.5
                    focus-within:border-brand focus-within:ring-1 focus-within:ring-brand">
            <svg class="size-4.25 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
            </svg>
            <label class="sr-only" for="list-search">Filter by number, name or email</label>
            <input id="list-search" type="search" name="{{ CustomerFilter::PARAM_SEARCH }}" value="{{ $filter->search }}"
                   placeholder="Filter by number, name or email"
                   class="grow bg-transparent text-sm text-ink outline-none placeholder:text-ink-faint">
        </div>

        {{-- Searching must narrow the current view rather than replace it. --}}
        @foreach ($filter->without(CustomerFilter::PARAM_SEARCH) as $param => $value)
            <input type="hidden" name="{{ $param }}" value="{{ $value }}">
        @endforeach

        <button type="submit"
                class="h-11.5 shrink-0 rounded-xl bg-brand px-4.5 text-sm font-semibold text-white hover:bg-brand-deep">
            Search
        </button>

        @if ($filter->isActive())
            <a href="{{ route('customers.index') }}"
               class="flex h-11.5 shrink-0 items-center rounded-xl border border-line bg-surface px-4.5 text-sm font-medium text-ink hover:bg-fill">
                Clear filters
            </a>
        @endif
    </form>

    <div class="flex flex-wrap items-center gap-2" aria-label="Filter by status">
        @foreach (CustomerStatus::cases() as $case)
            <a href="{{ route('customers.index', $filter->without(CustomerFilter::PARAM_STATUS) + [CustomerFilter::PARAM_STATUS => $case->value]) }}"
               @class([
                   'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13px] font-medium',
                   'border-brand bg-brand-soft text-brand-ink' => $filter->status === $case,
                   'border-line bg-surface text-ink hover:bg-fill' => $filter->status !== $case,
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
                    <a href="{{ route('customers.index', $filter->without($chip['param'])) }}"
                       class="flex size-4.5 items-center justify-center rounded-full text-ink-muted hover:bg-fill hover:text-ink"
                       aria-label="Remove {{ $chip['label'] }} filter">
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6 18 18"></path><path d="M18 6 6 18"></path>
                        </svg>
                    </a>
                </span>
            @endforeach

            <a href="{{ route('customers.index') }}" class="text-[13px] font-semibold text-brand-ink hover:text-brand-deep">
                Clear filters
            </a>
        </div>
    @endif

    <section aria-label="Customer table" class="flex flex-col rounded-[14px] border border-line bg-surface p-3">
        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl border-collapse text-sm">
                <thead>
                    <tr class="bg-fill-strong text-left text-[13px] font-medium text-ink-muted">
                        <th class="h-10.5 rounded-l-[9px] px-3.5 font-medium">Number</th>
                        <th class="px-3.5 font-medium">Customer</th>
                        <th class="px-3.5 font-medium">Status</th>
                        <th class="px-3.5 font-medium">Price group</th>
                        <th class="px-3.5 font-medium">Location</th>
                        <th class="px-3.5 text-right font-medium">Ship-tos</th>
                        <th class="px-3.5 font-medium">Delivery</th>
                        <th class="px-3.5 font-medium">On site</th>
                        <th class="rounded-r-[9px] px-3.5 font-medium">BC modified</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($customers as $customer)
                        @php
                            $status = CustomerStatus::for($customer);
                            $delivery = DeliveryStatus::forCustomer($customer->websiteSyncRecord);
                            $shipTos = count($customer->shippingAddresses());
                        @endphp

                        <tr class="border-b border-line-soft hover:bg-[#f7faf8]">
                            <td class="h-13.5 px-3.5 font-mono">
                                <a href="{{ route('customers.show', $customer) }}"
                                   class="font-medium text-brand-ink hover:text-brand-deep">{{ $customer->number }}</a>
                            </td>
                            <td class="max-w-0 truncate px-3.5">{{ $customer->title() }}</td>
                            <td class="px-3.5">
                                <x-customer-status-badge :status="$status" />
                            </td>
                            <td class="px-3.5 text-[13px] text-ink-muted">{{ $customer->customer_disc_group ?: $customer->customer_price_group ?: '—' }}</td>
                            <td class="px-3.5 text-[13px] text-ink-muted">{{ $customer->shipping_location_code ?: '—' }}</td>
                            <td @class([
                                'px-3.5 text-right tabular-nums',
                                'text-ink-faint' => $shipTos === 0,
                            ])>{{ number_format($shipTos) }}</td>
                            <td class="px-3.5">
                                <x-status-badge :status="$delivery" />
                            </td>
                            <td class="px-3.5">
                                @php($present = $onWebsite === null ? null : ($onWebsite[$customer->bc_id] ?? false))
                                @if ($present === null)
                                    <span class="text-[13px] text-ink-faint">unknown</span>
                                @elseif ($present === false)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-failed-soft px-2.5 py-1 text-xs font-semibold text-failed-ink"
                                          title="The ledger says delivered, but the website does not hold this customer.">
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
                                {{ $customer->bc_modified_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-14 text-center">
                                <span class="flex flex-col items-center gap-1.5">
                                    <span class="text-[15px] font-semibold">No customers match</span>
                                    <span class="text-sm text-ink-muted">
                                        @if ($filter->isActive())
                                            Try a different search or clear the filters.
                                        @else
                                            No customers have been imported yet.
                                        @endif
                                    </span>
                                </span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($customers->hasPages())
            <div class="px-3.5 pt-3.5">
                {{ $customers->links() }}
            </div>
        @endif
    </section>
</x-layout>
