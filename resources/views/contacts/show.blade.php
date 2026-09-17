@php
    use App\Contacts\ContactExclusionReason;
    use App\Sync\Payload\DeliveryType;

    $customer = $contact->customer;

    $rules = [
        ['code' => 'level = USER', 'reason' => ContactExclusionReason::NotUserLevel],
        ['code' => 'relation = Customer', 'reason' => ContactExclusionReason::NotCustomerRelation],
        ['code' => 'not privacy blocked', 'reason' => ContactExclusionReason::PrivacyBlocked],
        ['code' => 'has email', 'reason' => ContactExclusionReason::NoEmail],
        ['code' => 'email not taken', 'reason' => ContactExclusionReason::DuplicateEmail],
        ['code' => 'customer imported', 'reason' => ContactExclusionReason::NoCustomer],
        ['code' => 'customer has ship-to', 'reason' => ContactExclusionReason::CustomerHasNoShippingAddress],
    ];
@endphp

<x-layout :title="$contact->number">
    <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[13px] text-ink-muted">
        <a href="{{ route('contacts.index') }}" class="font-medium text-brand-ink hover:text-brand-deep">Contacts</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page" class="font-mono text-ink">{{ $contact->number }}</span>
    </nav>

    <header class="flex items-end justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <span class="font-mono text-sm font-medium text-ink-muted">Number {{ $contact->number }}</span>
            <h1 class="text-[32px] font-extrabold tracking-tight">{{ $contact->title() }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-2 pb-1">
            <x-eligibility-badge :eligible="$eligibility->eligible" :label="$eligibility->label()" class="!px-3 !py-1.5 !text-[13px]" />
            <x-status-badge :status="$deliveryStatus" class="!px-3 !py-1.5 !text-[13px]" />
        </div>
    </header>

    {{-- What the website actually holds, asked of the website itself. --}}
    @if ($onWebsite === false && $syncRecord?->isDelivered())
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
                    The ledger below says this contact was delivered, but the website has no user for it.
                    Something removed it after delivery. Re-send it with
                    <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-[12.5px]">php artisan website:deliver-contacts --number={{ $contact->number }} --send</code>
                </p>
            </div>
        </section>
    @elseif ($onWebsite !== null && $onWebsite !== false && ! $eligibility->eligible)
        <section aria-labelledby="stale-h" role="alert"
                 class="flex items-start gap-4 rounded-[14px] border border-[#e0d8f6] bg-[#f9f7fe] px-6 py-5">
            <svg class="mt-0.5 size-6 shrink-0 text-excluded" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle><path d="M12 8v5"></path><path d="M12 16h.01"></path>
            </svg>
            <div class="flex flex-col gap-1">
                <h2 id="stale-h" class="text-[15px] font-bold text-excluded-ink">On the website, but no longer qualifies</h2>
                <p class="text-sm text-excluded-ink">
                    User {{ $onWebsite['wp_id'] }} exists on the website, and this contact now fails a website rule.
                    Users are never removed by the sync because that would destroy order history; whether this account
                    should stay is a decision for a person.
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
                The website could not be reached, so whether it holds this contact is unknown.
            </p>
        </section>
    @endif

    {{-- Eligibility: every rule, and which ones this contact fails. --}}
    <section aria-labelledby="elig-h"
             @class([
                 'flex flex-col gap-8 rounded-[14px] border px-7 py-6 lg:flex-row lg:items-stretch',
                 'border-[#cfe9e6] bg-[#f4fbfa]' => $eligibility->eligible,
                 'border-[#e0d8f6] bg-[#f9f7fe]' => ! $eligibility->eligible,
             ])>
        <div class="flex grow items-start gap-4.5">
            <div @class([
                'flex size-13 shrink-0 items-center justify-center rounded-[13px]',
                'bg-[#d5eeec]' => $eligibility->eligible,
                'bg-excluded-soft' => ! $eligibility->eligible,
            ])>
                <svg @class(['size-6.5', 'text-eligible' => $eligibility->eligible, 'text-excluded' => ! $eligibility->eligible])
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg>
            </div>

            <div class="flex flex-col gap-1.5">
                <span id="elig-h" @class([
                    'text-xs font-bold tracking-[0.06em] uppercase',
                    'text-eligible-ink' => $eligibility->eligible,
                    'text-excluded-ink' => ! $eligibility->eligible,
                ])>Website eligibility</span>

                <span class="text-[26px] font-extrabold tracking-tight text-ink">{{ $eligibility->label() }}</span>

                <x-exclusion-reasons :eligibility="$eligibility" />
            </div>
        </div>

        <div @class([
            'flex min-w-90 flex-col justify-center gap-2.5 rounded-xl border bg-surface px-5 py-4',
            'border-[#cfe9e6]' => $eligibility->eligible,
            'border-[#e0d8f6]' => ! $eligibility->eligible,
        ])>
            <span class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Rules checked</span>

            @foreach ($rules as $rule)
                @php $excludes = $eligibility->hasReason($rule['reason']); @endphp
                <div class="flex items-center justify-between gap-4 text-sm">
                    <span class="font-mono text-[13px] text-ink-soft">{{ $rule['code'] }}</span>
                    <span @class([
                        'rounded-full px-2.5 py-0.75 text-xs font-semibold',
                        'bg-excluded-soft text-excluded-ink' => $excludes,
                        'bg-synced-soft text-synced-ink' => ! $excludes,
                    ])>{{ $excludes ? 'Excludes' : 'Pass' }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Business Central identity --}}
        <section aria-labelledby="info-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="info-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Business Central</h2>

            <dl class="flex flex-col gap-3">
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Contact id</dt>
                    <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $contact->bc_id }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Number</dt>
                    <dd class="font-mono text-sm text-ink">{{ $contact->number }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Email</dt>
                    <dd @class(['text-right text-sm', 'text-ink' => $contact->hasEmail(), 'font-bold text-excluded-ink' => ! $contact->hasEmail()])>{{ $contact->email ?: 'none' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Phone / mobile</dt>
                    <dd class="text-right text-sm text-ink">{{ $contact->phone ?: '—' }} <span class="text-ink-muted">/ {{ $contact->mobile ?: '—' }}</span></dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Organisational level</dt>
                    <dd @class(['text-sm', 'text-ink' => ! $eligibility->hasReason(ContactExclusionReason::NotUserLevel), 'font-bold text-excluded-ink' => $eligibility->hasReason(ContactExclusionReason::NotUserLevel)])>{{ $contact->organisational_level_code ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Business relation</dt>
                    <dd @class(['text-sm', 'text-ink' => ! $eligibility->hasReason(ContactExclusionReason::NotCustomerRelation), 'font-bold text-excluded-ink' => $eligibility->hasReason(ContactExclusionReason::NotCustomerRelation)])>{{ $contact->contact_business_relation ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Privacy blocked</dt>
                    <dd @class(['text-sm', 'text-ink' => ! $contact->privacy_blocked, 'font-bold text-excluded-ink' => $contact->privacy_blocked])>{{ $contact->privacy_blocked ? 'Yes' : 'No' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Contact company</dt>
                    <dd class="text-right text-sm text-ink">{{ $contact->company_name ?: '—' }} <span class="font-mono text-xs text-ink-muted">{{ $contact->company_number }}</span></dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Last modified</dt>
                    <dd class="text-sm tabular-nums text-ink">
                        {{ $contact->bc_modified_at?->format('d M Y H:i:s.v') ?? '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Linked customer --}}
        <section aria-labelledby="cust-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="cust-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Customer</h2>

            @if ($customer)
                <dl class="flex flex-col gap-3">
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Number</dt>
                        <dd class="font-mono text-sm">
                            <a href="{{ route('customers.show', $customer) }}" class="font-medium text-brand-ink hover:text-brand-deep">{{ $customer->number }}</a>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Name</dt>
                        <dd class="text-right text-sm text-ink">{{ $customer->title() }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Blocked</dt>
                        <dd class="text-sm text-ink">{{ $customer->blocked }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Ship-to addresses</dt>
                        <dd @class(['text-sm tabular-nums', 'text-ink' => $customer->shippingAddresses() !== [], 'font-bold text-excluded-ink' => $customer->shippingAddresses() === []])>{{ number_format(count($customer->shippingAddresses())) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Customer delivery</dt>
                        <dd><x-status-badge :status="App\Sync\DeliveryStatus::forCustomer($customer->websiteSyncRecord)" /></dd>
                    </div>
                </dl>
            @elseif ($contact->customer_bc_id)
                <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">
                    Business Central links this contact to customer <span class="font-mono text-xs">{{ $contact->customer_bc_id }}</span>, which has not been imported yet.
                </p>
            @else
                <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">
                    No customer is linked to this contact. The link arrives from the customerContacts sweep
                    (<code class="font-mono text-[12.5px]">bc:import-contact-links</code>), which runs every 15 minutes.
                </p>
            @endif
        </section>
    </div>

    {{-- Delivery ledger --}}
    <section aria-labelledby="sync-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
        <h2 id="sync-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Delivery</h2>

        <div class="flex items-center justify-between gap-6 rounded-[10px] bg-fill px-3.5 py-2.5">
            <span class="text-xs font-medium text-ink-muted">On the website now</span>
            <span class="text-sm font-semibold">
                @if ($onWebsite === false)
                    <span class="{{ $syncRecord?->isDelivered() ? 'text-failed-ink' : 'text-ink-muted' }}">No</span>
                @elseif ($onWebsite === null)
                    <span class="text-ink-muted">Unknown</span>
                @else
                    <span class="text-eligible-ink">Yes</span>
                    <span class="font-normal text-ink-muted">(user {{ $onWebsite['wp_id'] }}, {{ $onWebsite['status'] }})</span>
                @endif
            </span>
        </div>

        @if ($syncRecord)
            <dl class="grid gap-3 sm:grid-cols-2">
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
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Dispatched</dt>
                    <dd class="text-sm tabular-nums text-ink">{{ $syncRecord->dispatched_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Delivered</dt>
                    <dd class="text-sm tabular-nums text-ink">{{ $syncRecord->synced_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Attempts</dt>
                    <dd class="text-sm tabular-nums text-ink">{{ $syncRecord->attempts }}</dd>
                </div>
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Payload hash</dt>
                    <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $syncRecord->payload_hash ?? '—' }}</dd>
                </div>

                @if ($syncRecord->last_error)
                    <div class="flex flex-col gap-1.5 rounded-[10px] bg-failed-soft px-3.5 py-3 sm:col-span-2">
                        <dt class="text-xs font-semibold text-failed-ink">Last error</dt>
                        <dd class="font-mono text-xs leading-relaxed break-words text-failed-ink">{{ $syncRecord->last_error }}</dd>
                    </div>
                @endif
            </dl>
        @else
            <p class="py-6 text-center text-sm text-ink-muted">
                @if ($eligibility->eligible)
                    No ledger row yet. This contact qualifies but has never been queued for delivery.
                @else
                    No ledger row. This contact does not qualify for the website, so it has never been queued — and never will be while it fails a rule.
                @endif
            </p>
        @endif
    </section>

    {{-- What would go over the wire right now. --}}
    <section aria-labelledby="preview-h" class="flex flex-col rounded-[14px] border border-line bg-surface">
        <div class="flex flex-col gap-1.5 border-b border-line px-6 py-5">
            <h2 id="preview-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Next delivery</h2>
            <p class="text-[13px] text-ink-muted">
                Rebuilt from the contact as it stands. Contacts are always upserted and never removed: the
                website turns this into a user account, and no email is sent when it does.
                @if (! $eligibility->eligible)
                    <span class="font-semibold text-excluded-ink">This contact does not qualify, so nothing below will be sent.</span>
                @endif
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
            <span class="text-xs font-semibold tracking-[0.04em] text-ink-muted uppercase">Full desired payload</span>
            <pre class="overflow-x-auto rounded-[10px] bg-[#17211d] p-5 font-mono text-[12.5px] leading-relaxed text-[#d8e0db]">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </section>
</x-layout>
