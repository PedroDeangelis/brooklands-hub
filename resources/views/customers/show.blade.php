@php
    use App\Customers\CustomerStatus;
    use App\Sync\Payload\DeliveryType;

    $addresses = $customer->shippingAddresses();
@endphp

<x-layout :title="$customer->code">
    <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[13px] text-ink-muted">
        <a href="{{ route('customers.index') }}" class="font-medium text-brand-ink hover:text-brand-deep">Customers</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page" class="font-mono text-ink">{{ $customer->number }}</span>
    </nav>

    <header class="flex items-end justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <span class="font-mono text-sm font-medium text-ink-muted">Number {{ $customer->number }}</span>
            <h1 class="text-[32px] font-extrabold tracking-tight">{{ $customer->title() }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-2 pb-1">
            <x-customer-status-badge :status="$status" class="!px-3 !py-1.5 !text-[13px]" />
            <x-status-badge :status="$deliveryStatus" class="!px-3 !py-1.5 !text-[13px]" />
        </div>
    </header>

    {{-- What the website actually holds, asked of the website itself.
         Placed first and loudest: a promotion that is missing from the site is
         the single most important thing this page can tell anyone, and every
         other panel here describes intent rather than reality. --}}
    @if ($onWebsite === false)
        <section aria-labelledby="missing-h" role="alert"
                 class="flex items-start gap-4 rounded-[14px] border border-[#e6b5b5] bg-[#fdf2f2] px-6 py-5">
            <svg class="mt-0.5 size-6 shrink-0 text-failed" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 9v4"></path><path d="M12 17h.01"></path>
                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"></path>
            </svg>
            <div class="flex flex-col gap-1">
                <h2 id="missing-h" class="text-[15px] font-bold text-failed-ink">Not on the website</h2>
                <p class="text-sm text-failed-ink">
                    The ledger below says this customer was delivered, but the website does not have it.
                    Something removed it after delivery. Re-send it with
                    <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-[12.5px]">php artisan website:deliver-customers --number={{ $customer->number }} --send</code>
                </p>
            </div>
        </section>
    @elseif ($onWebsite === null)
        <section class="flex items-start gap-4 rounded-[14px] border border-line bg-fill px-6 py-4">
            <svg class="mt-0.5 size-5 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle><path d="M12 8v5"></path><path d="M12 16h.01"></path>
            </svg>
            <p class="text-sm text-ink-muted">
                The website could not be reached, so whether it holds this customer is unknown.
            </p>
        </section>
    @endif

    {{-- Lifecycle. The website decides visibility from these values, so they are
         shown together with what they currently add up to. --}}
    <section aria-labelledby="window-h"
             @class([
                 'flex flex-col gap-8 rounded-[14px] border px-7 py-6 lg:flex-row lg:items-stretch',
                 'border-[#cfe9e6] bg-[#f4fbfa]' => ($status === CustomerStatus::Active),
                 'border-[#e0d8f6] bg-[#f9f7fe]' => ! ($status === CustomerStatus::Active),
             ])>
        <div class="flex grow items-center gap-4.5">
            <div @class([
                'flex size-13 shrink-0 items-center justify-center rounded-[13px]',
                'bg-[#d5eeec]' => ($status === CustomerStatus::Active),
                'bg-excluded-soft' => ! ($status === CustomerStatus::Active),
            ])>
                <svg @class(['size-6.5', 'text-eligible' => ($status === CustomerStatus::Active), 'text-excluded' => ! ($status === CustomerStatus::Active)])
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg>
            </div>

            <div class="flex flex-col gap-1">
                <span id="window-h" @class([
                    'text-xs font-bold tracking-[0.06em] uppercase',
                    'text-eligible-ink' => ($status === CustomerStatus::Active),
                    'text-excluded-ink' => ! ($status === CustomerStatus::Active),
                ])>Customer status</span>

                <span class="text-[26px] font-extrabold tracking-tight text-ink">{{ $status->label() }}</span>
                <span class="text-[13px] text-ink-muted">{{ $status->explain() }}</span>
            </div>
        </div>

        <div class="flex min-w-90 flex-col justify-center gap-2.5 rounded-xl border border-line bg-surface px-5 py-4">
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Blocked</span>
                <span @class(['text-sm font-semibold', 'text-eligible-ink' => ! $customer->isBlocked(), 'text-excluded-ink' => $customer->isBlocked()])>{{ $customer->blocked }}</span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Price group</span>
                <span class="text-sm text-ink">{{ $customer->customer_disc_group ?: '—' }} <span class="text-ink-muted">/ {{ $customer->customer_price_group ?: '—' }}</span></span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Shipping location</span>
                <span class="text-sm text-ink">{{ $customer->shipping_location_code ?: '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Ship-to addresses</span>
                <span class="text-sm tabular-nums text-ink">{{ number_format(count($addresses)) }}</span>
            </div>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Business Central identity --}}
        <section aria-labelledby="info-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="info-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Business Central</h2>

            <dl class="flex flex-col gap-3">
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Customer id</dt>
                    <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $customer->bc_id }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Number</dt>
                    <dd class="font-mono text-sm text-ink">{{ $customer->number }}</dd>
                </div>
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Address</dt>
                    <dd class="text-right text-sm text-ink">{{ implode(', ', array_filter([$customer->address_1, $customer->address_2, $customer->city, $customer->postal_code, $customer->country])) ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Email / phone</dt>
                    <dd class="text-right text-sm text-ink">{{ $customer->email ?: '—' }} <span class="text-ink-muted">/ {{ $customer->phone ?: '—' }}</span></dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Salesperson</dt>
                    <dd class="text-sm text-ink">{{ $customer->salesperson_code ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Last modified</dt>
                    <dd class="text-sm tabular-nums text-ink">
                        {{ $customer->bc_modified_at?->format('d M Y H:i:s.v') ?? '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Delivery ledger --}}
        <section aria-labelledby="sync-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="sync-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Delivery</h2>

            {{-- Outside the ledger check on purpose: whether the website holds
                 this customer is a fact about the website, and is worth
                 showing even for a customer that has never been delivered. --}}
            <div class="flex items-center justify-between gap-6 rounded-[10px] bg-fill px-3.5 py-2.5">
                <span class="text-xs font-medium text-ink-muted">On the website now</span>
                <span class="text-sm font-semibold">
                    @if ($onWebsite === false)
                        <span class="text-failed-ink">No</span>
                    @elseif ($onWebsite === null)
                        <span class="text-ink-muted">Unknown</span>
                    @else
                        <span class="text-eligible-ink">Yes</span>
                        <span class="font-normal text-ink-muted">(post {{ $onWebsite['wp_id'] }}, {{ $onWebsite['status'] }})</span>
                    @endif
                </span>
            </div>

            @if ($syncRecord)
                <dl class="flex flex-col gap-3">
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Status</dt>
                        <dd><x-status-badge :status="$deliveryStatus" /></dd>
                    </div>
                    <div class="flex items-start justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Changed fields</dt>
                        <dd class="text-right font-mono text-xs text-ink-soft">
                            @forelse ($syncRecord->changed_fields ?? [] as $field)
                                {{ $field }}@if (! $loop->last), @endif
                            @empty
                                —
                            @endforelse
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Payload hash</dt>
                        <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $syncRecord->payload_hash ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Dispatched</dt>
                        <dd @class([
                            'text-sm tabular-nums',
                            'text-ink' => $syncRecord->dispatched_at,
                            'text-ink-faint' => ! $syncRecord->dispatched_at,
                        ])>{{ $syncRecord->dispatched_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Delivered</dt>
                        <dd @class([
                            'text-sm tabular-nums',
                            'text-ink' => $syncRecord->synced_at,
                            'text-ink-faint' => ! $syncRecord->synced_at,
                        ])>{{ $syncRecord->synced_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Attempts</dt>
                        <dd class="text-sm tabular-nums text-ink">{{ $syncRecord->attempts }}</dd>
                    </div>

                    @if ($syncRecord->last_error)
                        <div class="flex flex-col gap-1.5 rounded-[10px] bg-failed-soft px-3.5 py-3">
                            <dt class="text-xs font-semibold text-failed-ink">Last error</dt>
                            <dd class="font-mono text-xs leading-relaxed break-words text-failed-ink">
                                {{ $syncRecord->last_error }}
                            </dd>
                        </div>
                    @endif
                </dl>
            @else
                <p class="py-6 text-center text-sm text-ink-muted">
                    No ledger row yet. This customer has never been queued for delivery.
                </p>
            @endif
        </section>
    </div>

    {{-- Ship-to addresses, as delivered: the whole list is sent and replaced
         together, so what is shown here is exactly what the website holds. --}}
    <section aria-labelledby="addresses-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
        <div class="flex items-baseline justify-between gap-4">
            <h2 id="addresses-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Ship-to addresses</h2>
            <span class="text-[13px] text-ink-muted">{{ number_format(count($addresses)) }} {{ Str::plural('address', count($addresses)) }}</span>
        </div>

        @if ($addresses === [])
            <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">
                No ship-to addresses have been attached to this customer. Contacts for it cannot be delivered until one arrives.
            </p>
        @else
            <ul class="grid gap-2 sm:grid-cols-2">
                @foreach ($addresses as $address)
                    <li class="flex flex-col gap-0.5 rounded-[8px] bg-fill px-3.5 py-2.5 text-[13px]">
                        <span class="font-mono text-xs font-semibold text-ink">{{ $address['code'] ?? '—' }} <span class="font-sans font-normal text-ink-muted">{{ $address['name'] ?? '' }}</span></span>
                        <span class="text-ink">{{ implode(', ', array_filter([$address['address_1'] ?? null, $address['address_2'] ?? null, $address['city'] ?? null, $address['postal_code'] ?? null])) }}</span>
                        <span class="text-ink-muted">{{ $address['region'] ?? '' }}{{ ! empty($address['is_rural']) ? ' · rural' : '' }}{{ ! empty($address['county']) ? ' · '.$address['county'] : '' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- What would go over the wire right now. --}}
    <section aria-labelledby="preview-h" class="flex flex-col rounded-[14px] border border-line bg-surface">
        <div class="flex flex-col gap-1.5 border-b border-line px-6 py-5">
            <h2 id="preview-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Next delivery</h2>
            <p class="text-[13px] text-ink-muted">
                Rebuilt from the customer as it stands. Customers are always upserted: Laravel mirrors
                Business Central and the website decides what to publish.
            </p>
        </div>

        <dl class="grid gap-5 border-b border-line px-6 py-5 sm:grid-cols-3">
            <div class="flex flex-col gap-1.5">
                <dt class="text-xs font-medium text-ink-muted">Action</dt>
                <dd><x-action-badge :action="$plan->action" /></dd>
            </div>
            <div class="flex flex-col gap-1.5">
                <dt class="text-xs font-medium text-ink-muted">Mode</dt>
                <dd class="flex flex-col gap-0.5">
                    <span class="text-[15px] font-semibold text-ink">{{ $plan->type->label() }}</span>
                    <span class="text-[13px] text-ink-muted">{{ $plan->type->explain() }}</span>
                </dd>
            </div>
            <div class="flex flex-col gap-1.5">
                <dt class="text-xs font-medium text-ink-muted">Full payload hash</dt>
                <dd class="font-mono text-xs leading-relaxed break-all text-ink-soft">{{ $plan->payloadHash }}</dd>
            </div>
        </dl>

        <div class="flex flex-col gap-3 px-6 py-5">
            <span class="flex items-baseline gap-2">
                <span class="text-xs font-semibold tracking-[0.04em] text-ink-muted uppercase">Envelope</span>
                @if ($plan->type !== DeliveryType::None)
                    <span class="text-[13px] text-ink-muted">
                        ({{ $plan->diff->count() }} {{ Str::plural('field', $plan->diff->count()) }})
                    </span>
                @endif
            </span>

            @if ($plan->sendsAnything())
                <pre class="overflow-x-auto rounded-[10px] bg-[#17211d] p-5 font-mono text-[12.5px] leading-relaxed text-[#d8e0db]">{{ json_encode($plan->envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @else
                <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">
                    Nothing to send. The website already holds the desired payload.
                </p>
            @endif
        </div>

        <div class="flex flex-col gap-3 border-t border-line px-6 py-5">
            <span class="text-xs font-semibold tracking-[0.04em] text-ink-muted uppercase">
                Full desired payload
            </span>
            <pre class="overflow-x-auto rounded-[10px] bg-[#17211d] p-5 font-mono text-[12.5px] leading-relaxed text-[#d8e0db]">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </section>
</x-layout>
