@php
    use App\Enums\SyncStatus;
    use App\Products\ProductFilter;
@endphp

<x-layout title="Dashboard">
    <div class="flex items-start justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-[32px] font-extrabold tracking-tight">Dashboard</h1>
            <p class="text-[15px] text-ink-muted">
                Product data imported from Business Central and its current sync state.
            </p>
        </div>

        <a href="{{ route('products.index') }}"
           class="flex h-11 shrink-0 items-center gap-2.5 rounded-[10px] bg-brand-soft px-4.5 text-sm font-semibold text-brand-ink hover:bg-[#dcefe6]">
            Browse all products
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path>
            </svg>
        </a>
    </div>

    <section aria-label="Summary" class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-5">
        <x-stat-card label="Total Products" :value="$totalProducts" tone="neutral"
                     caption="Imported from Business Central" :href="route('products.index')">
            <x-slot:icon>
                <path d="M21 8 12 3 3 8v8l9 5 9-5z"></path><path d="m3 8 9 5 9-5"></path><path d="M12 13v8"></path>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Pending" :value="$pending" tone="pending"
                     caption="Changed, awaiting dispatch"
                     :href="route('products.index', [ProductFilter::PARAM_SYNC => SyncStatus::Pending->value])">
            <x-slot:icon>
                <path d="M6 2h12"></path><path d="M6 22h12"></path>
                <path d="M7 2v4a5 5 0 0 0 10 0V2"></path><path d="M7 22v-4a5 5 0 0 1 10 0v4"></path>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Synced" :value="$synced" tone="synced"
                     caption="Up to date"
                     :href="route('products.index', [ProductFilter::PARAM_SYNC => SyncStatus::Synced->value])">
            <x-slot:icon>
                <circle cx="12" cy="12" r="9"></circle><path d="m8 12.5 2.8 2.8L16.5 9.5"></path>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Failed" :value="$failed" tone="failed"
                     caption="Last attempt errored"
                     :href="route('products.index', [ProductFilter::PARAM_SYNC => SyncStatus::Failed->value])">
            <x-slot:icon>
                <circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5.5"></path><path d="M12 16.5h.01"></path>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Excluded" :value="$excluded" tone="excluded"
                     caption="Imported, but not for the website"
                     :href="route('products.index', [ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED])">
            <x-slot:icon>
                <circle cx="12" cy="12" r="9"></circle><path d="m5.7 5.7 12.6 12.6"></path>
            </x-slot:icon>
        </x-stat-card>
    </section>

    @if ($exclusionBreakdown !== [])
        <section aria-labelledby="breakdown-h" class="flex flex-col rounded-[14px] border border-line bg-surface">
            <div class="flex items-center justify-between gap-4 border-b border-line-soft px-6 py-4.5">
                <span class="flex flex-col gap-0.5">
                    <h2 id="breakdown-h" class="text-[17px] font-bold">Why products are excluded</h2>
                    <span class="text-[13px] text-ink-muted">
                        Every rule a product fails is counted, so a product failing two rules appears twice.
                    </span>
                </span>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ($exclusionBreakdown as $row)
                    <a href="{{ $row['url'] }}"
                       class="flex flex-col gap-1.5 border-b border-line-soft px-6 py-4.5 text-ink hover:bg-[#f7faf8]">
                        <dt class="flex items-center gap-1.5 text-xs font-medium text-ink-muted">
                            {{ $row['label'] }}
                            <svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path>
                            </svg>
                        </dt>
                        <dd class="text-[26px] leading-none font-extrabold tracking-tight tabular-nums"
                            data-exclusion="{{ $row['reason']->value }}">{{ number_format($row['count']) }}</dd>
                    </a>
                @endforeach
            </dl>
        </section>
    @endif

    <p class="text-sm text-ink-muted">
        Pending, Synced and Failed count ledger rows on the
        <code class="rounded bg-fill px-1.5 py-0.5 font-mono text-xs text-ink-soft">items</code> channel.
        Excluded products are imported and searchable; they are simply not delivered to the website,
        which is shown as <span class="font-medium text-ink-soft">Not applicable</span> rather than a failure.
    </p>
</x-layout>
