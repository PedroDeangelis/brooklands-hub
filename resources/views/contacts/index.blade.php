@php
    use App\Contacts\ContactFilter;
    use App\Sync\DeliveryStatus;
@endphp

<x-layout title="Contacts">
    <div class="flex flex-col gap-1.5">
        <h1 class="text-[32px] font-extrabold tracking-tight">Contacts</h1>
        <p class="text-[15px] text-ink-muted">
            <span class="tabular-nums">{{ number_format($contacts->total()) }}</span>
            @if ($filter->isActive())
                matching {{ Str::plural('contact', $contacts->total()) }}
            @else
                person contacts imported from Business Central
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('contacts.index') }}" class="flex items-center gap-3">
        <div class="flex h-11.5 grow items-center gap-2.5 rounded-xl border border-line bg-surface px-3.5
                    focus-within:border-brand focus-within:ring-1 focus-within:ring-brand">
            <svg class="size-4.25 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
            </svg>
            <label class="sr-only" for="list-search">Filter by number, name or email</label>
            <input id="list-search" type="search" name="{{ ContactFilter::PARAM_SEARCH }}" value="{{ $filter->search }}"
                   placeholder="Filter by number, name or email"
                   class="grow bg-transparent text-sm text-ink outline-none placeholder:text-ink-faint">
        </div>

        {{-- Searching must narrow the current view rather than replace it. --}}
        @foreach ($filter->without(ContactFilter::PARAM_SEARCH) as $param => $value)
            <input type="hidden" name="{{ $param }}" value="{{ $value }}">
        @endforeach

        <button type="submit"
                class="h-11.5 shrink-0 rounded-xl bg-brand px-4.5 text-sm font-semibold text-white hover:bg-brand-deep">
            Search
        </button>

        @if ($filter->isActive())
            <a href="{{ route('contacts.index') }}"
               class="flex h-11.5 shrink-0 items-center rounded-xl border border-line bg-surface px-4.5 text-sm font-medium text-ink hover:bg-fill">
                Clear filters
            </a>
        @endif
    </form>

    <div class="flex flex-wrap items-center gap-2" aria-label="Filter by website eligibility">
        @foreach ([ContactFilter::WEBSITE_ELIGIBLE => 'Eligible', ContactFilter::WEBSITE_EXCLUDED => 'Excluded'] as $value => $label)
            <a href="{{ route('contacts.index', $filter->without(ContactFilter::PARAM_WEBSITE) + [ContactFilter::PARAM_WEBSITE => $value]) }}"
               @class([
                   'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13px] font-medium',
                   'border-brand bg-brand-soft text-brand-ink' => $filter->websiteStatus === $value,
                   'border-line bg-surface text-ink hover:bg-fill' => $filter->websiteStatus !== $value,
               ])>
                {{ $label }}
            </a>
        @endforeach

        <span class="mx-1 h-5 w-px bg-line" aria-hidden="true"></span>

        @foreach ($reasons as $reason)
            <a href="{{ route('contacts.index', $filter->without(ContactFilter::PARAM_REASON) + [ContactFilter::PARAM_REASON => $reason->value]) }}"
               @class([
                   'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13px] font-medium',
                   'border-brand bg-brand-soft text-brand-ink' => $filter->reason === $reason,
                   'border-line bg-surface text-ink hover:bg-fill' => $filter->reason !== $reason,
               ])>
                {{ $reason->label() }}
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
                    <a href="{{ route('contacts.index', $filter->without($chip['param'])) }}"
                       class="flex size-4.5 items-center justify-center rounded-full text-ink-muted hover:bg-fill hover:text-ink"
                       aria-label="Remove {{ $chip['label'] }} filter">
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6 18 18"></path><path d="M18 6 6 18"></path>
                        </svg>
                    </a>
                </span>
            @endforeach

            <a href="{{ route('contacts.index') }}" class="text-[13px] font-semibold text-brand-ink hover:text-brand-deep">
                Clear filters
            </a>
        </div>
    @endif

    <section aria-label="Contact table" class="flex flex-col rounded-[14px] border border-line bg-surface p-3">
        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl border-collapse text-sm">
                <thead>
                    <tr class="bg-fill-strong text-left text-[13px] font-medium text-ink-muted">
                        <th class="h-10.5 rounded-l-[9px] px-3.5 font-medium">Number</th>
                        <th class="px-3.5 font-medium">Contact</th>
                        <th class="px-3.5 font-medium">Email</th>
                        <th class="px-3.5 font-medium">Website</th>
                        <th class="px-3.5 font-medium">Customer</th>
                        <th class="px-3.5 font-medium">Delivery</th>
                        <th class="px-3.5 font-medium">On site</th>
                        <th class="rounded-r-[9px] px-3.5 font-medium">BC modified</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($contacts as $contact)
                        @php
                            $status = $eligibility->for($contact);
                            $delivery = DeliveryStatus::forContact($contact, $eligibility, $contact->websiteSyncRecord);
                        @endphp

                        <tr class="border-b border-line-soft hover:bg-[#f7faf8]">
                            <td class="h-13.5 px-3.5 font-mono">
                                <a href="{{ route('contacts.show', $contact) }}"
                                   class="font-medium text-brand-ink hover:text-brand-deep">{{ $contact->number }}</a>
                            </td>
                            <td class="max-w-0 truncate px-3.5">{{ $contact->title() }}</td>
                            <td class="max-w-0 truncate px-3.5 text-[13px] text-ink-muted">{{ $contact->email ?: '—' }}</td>
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
                            <td class="px-3.5 font-mono text-[13px]">
                                @if ($contact->customer)
                                    <a href="{{ route('customers.show', $contact->customer) }}"
                                       class="text-brand-ink hover:text-brand-deep">{{ $contact->customer->number }}</a>
                                @elseif ($contact->customer_bc_id)
                                    <span class="text-ink-faint" title="{{ $contact->customer_bc_id }}">not imported</span>
                                @else
                                    <span class="text-ink-faint">—</span>
                                @endif
                            </td>
                            <td class="px-3.5">
                                <x-status-badge :status="$delivery" />
                            </td>
                            <td class="px-3.5">
                                @php($present = $onWebsite === null ? null : ($onWebsite[$contact->bc_id] ?? false))
                                @if ($present === null)
                                    <span class="text-[13px] text-ink-faint">unknown</span>
                                @elseif ($present === false)
                                    @if ($contact->websiteSyncRecord?->isDelivered())
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-failed-soft px-2.5 py-1 text-xs font-semibold text-failed-ink"
                                              title="The ledger says delivered, but the website has no user for this contact.">
                                            <span class="size-1.5 rounded-full bg-failed"></span>
                                            Missing
                                        </span>
                                    @else
                                        <span class="text-[13px] text-ink-faint">absent</span>
                                    @endif
                                @else
                                    <span @class([
                                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                                        'bg-eligible-soft text-eligible-ink' => $status->eligible,
                                        'bg-excluded-soft text-excluded-ink' => ! $status->eligible,
                                    ]) title="User {{ $present['wp_id'] }} ({{ $present['status'] }}){{ $status->eligible ? '' : ' — still on the site although the contact no longer qualifies' }}">
                                        <span class="size-1.5 rounded-full {{ $status->eligible ? 'bg-eligible' : 'bg-excluded' }}"></span>
                                        Present
                                    </span>
                                @endif
                            </td>
                            <td class="px-3.5 text-[13px] text-ink-muted tabular-nums">
                                {{ $contact->bc_modified_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-14 text-center">
                                <span class="flex flex-col items-center gap-1.5">
                                    <span class="text-[15px] font-semibold">No contacts match</span>
                                    <span class="text-sm text-ink-muted">
                                        @if ($filter->isActive())
                                            Try a different search or clear the filters.
                                        @else
                                            No contacts have been imported yet.
                                        @endif
                                    </span>
                                </span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($contacts->hasPages())
            <div class="px-3.5 pt-3.5">
                {{ $contacts->links() }}
            </div>
        @endif
    </section>
</x-layout>
