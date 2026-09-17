@php
    use App\Campaigns\CampaignStatus;
    use App\Sync\Payload\DeliveryType;

    $audience = $campaign->customers ?? [];
@endphp

<x-layout :title="$campaign->code">
    <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[13px] text-ink-muted">
        <a href="{{ route('promotions.index') }}" class="font-medium text-brand-ink hover:text-brand-deep">Promotions</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page" class="font-mono text-ink">{{ $campaign->code }}</span>
    </nav>

    <header class="flex items-end justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <span class="font-mono text-sm font-medium text-ink-muted">Code {{ $campaign->code }}</span>
            <h1 class="text-[32px] font-extrabold tracking-tight">{{ $campaign->title() }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-2 pb-1">
            <x-campaign-status-badge :status="$status" class="!px-3 !py-1.5 !text-[13px]" />
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
                    The ledger below says this promotion was delivered, but the website does not have it.
                    Something removed it after delivery. Re-send it with
                    <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-[12.5px]">php artisan website:deliver-campaigns --code={{ $campaign->code }} --send</code>
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
                The website could not be reached, so whether it holds this promotion is unknown.
            </p>
        </section>
    @endif

    {{-- Lifecycle. The website decides visibility from these values, so they are
         shown together with what they currently add up to. --}}
    <section aria-labelledby="window-h"
             @class([
                 'flex flex-col gap-8 rounded-[14px] border px-7 py-6 lg:flex-row lg:items-stretch',
                 'border-[#cfe9e6] bg-[#f4fbfa]' => $status->isLive(),
                 'border-[#e0d8f6] bg-[#f9f7fe]' => ! $status->isLive(),
             ])>
        <div class="flex grow items-center gap-4.5">
            <div @class([
                'flex size-13 shrink-0 items-center justify-center rounded-[13px]',
                'bg-[#d5eeec]' => $status->isLive(),
                'bg-excluded-soft' => ! $status->isLive(),
            ])>
                <svg @class(['size-6.5', 'text-eligible' => $status->isLive(), 'text-excluded' => ! $status->isLive()])
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>
                </svg>
            </div>

            <div class="flex flex-col gap-1">
                <span id="window-h" @class([
                    'text-xs font-bold tracking-[0.06em] uppercase',
                    'text-eligible-ink' => $status->isLive(),
                    'text-excluded-ink' => ! $status->isLive(),
                ])>Campaign status</span>

                <span class="text-[26px] font-extrabold tracking-tight text-ink">{{ $status->label() }}</span>
                <span class="text-[13px] text-ink-muted">{{ $status->explain() }}</span>
            </div>
        </div>

        <div class="flex min-w-90 flex-col justify-center gap-2.5 rounded-xl border border-line bg-surface px-5 py-4">
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Activated in Business Central</span>
                <span @class([
                    'text-sm font-semibold',
                    'text-eligible-ink' => $campaign->activated,
                    'text-excluded-ink' => ! $campaign->activated,
                ])>{{ $campaign->activated ? 'Yes' : 'No' }}</span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Starts</span>
                <span class="text-sm tabular-nums text-ink">{{ $campaign->starting_date?->format('d M Y') ?? '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Ends</span>
                <span class="text-sm tabular-nums text-ink">{{ $campaign->ending_date?->format('d M Y') ?? '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Audience</span>
                <span class="text-sm tabular-nums text-ink">
                    {{ number_format(count($audience)) }} {{ Str::plural('customer', count($audience)) }}
                </span>
            </div>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Business Central identity --}}
        <section aria-labelledby="info-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="info-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Business Central</h2>

            <dl class="flex flex-col gap-3">
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Campaign id</dt>
                    <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $campaign->bc_id }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Code</dt>
                    <dd class="font-mono text-sm text-ink">{{ $campaign->code }}</dd>
                </div>
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Description</dt>
                    <dd class="text-right text-sm text-ink">{{ $campaign->description ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Last modified</dt>
                    <dd class="text-sm tabular-nums text-ink">
                        {{ $campaign->bc_modified_at?->format('d M Y H:i:s.v') ?? '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Delivery ledger --}}
        <section aria-labelledby="sync-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="sync-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Delivery</h2>

            {{-- Outside the ledger check on purpose: whether the website holds
                 this promotion is a fact about the website, and is worth
                 showing even for a campaign that has never been delivered. --}}
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
                    No ledger row yet. This campaign has never been queued for delivery.
                </p>
            @endif
        </section>
    </div>

    {{-- The audience. This is what the website matches a logged-in customer
         against to decide whether they see the promotion, so the ids are shown
         in full rather than counted. --}}
    <section aria-labelledby="audience-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
        <div class="flex items-baseline justify-between gap-4">
            <h2 id="audience-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">
                Audience
            </h2>
            <span class="text-[13px] text-ink-muted">
                {{ number_format(count($audience)) }} {{ Str::plural('customer', count($audience)) }}
            </span>
        </div>

        <p class="text-[13px] text-ink-muted">
            Business Central customer ids. The website shows this promotion only to a signed-in
            customer whose id appears here, so an empty list means nobody sees it.
        </p>

        @if ($audience === [])
            <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">
                No customers are attached to this campaign.
            </p>
        @else
            <ul class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($audience as $customerId)
                    <li class="rounded-[8px] bg-fill px-3 py-2 font-mono text-xs break-all text-ink-soft">
                        {{ $customerId }}
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
                Rebuilt from the campaign as it stands. Campaigns are always upserted: Laravel mirrors
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
