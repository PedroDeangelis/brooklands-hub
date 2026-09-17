@php
    use App\Products\ExclusionReason;

    // Every rule WebsiteEligibility checks, read back off the result so the panel
    // can never claim a verdict the class did not reach.
    $rules = [
        ['code' => 'blocked = '.($product->blocked ? 'true' : 'false'), 'reason' => ExclusionReason::Blocked],
        ['code' => 'item_category_id = '.($product->item_category_id ?: '—'), 'reason' => ExclusionReason::Retired],
        ['code' => 'unit_price = '.number_format((float) $product->price, 2), 'reason' => ExclusionReason::NoPrice],
        ['code' => 'type = '.($product->type ?: '—'), 'reason' => ExclusionReason::UnsupportedType],
        ['code' => 'gppg = '.($product->gppg ?: '—'), 'reason' => ExclusionReason::NotFinishedGoods],
    ];

    $retired = $eligibility->hasReason(ExclusionReason::Retired);
@endphp

<x-layout :title="$product->sku">
    <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[13px] text-ink-muted">
        <a href="{{ route('products.index') }}" class="font-medium text-brand-ink hover:text-brand-deep">Products</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page" class="font-mono text-ink">{{ $product->sku }}</span>
    </nav>

    <header class="flex items-end justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <span class="font-mono text-sm font-medium text-ink-muted">SKU {{ $product->sku }}</span>
            <h1 class="text-[32px] font-extrabold tracking-tight">{{ $product->name }}</h1>
            @if ($product->name_2)
                <span class="text-[15px] text-ink-muted">{{ $product->name_2 }}</span>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-2 pb-1">
            <x-eligibility-badge :eligible="$eligibility->eligible" :label="$eligibility->label()"
                                 class="!px-3 !py-1.5 !text-[13px]" />
            <x-status-badge :status="$deliveryStatus" class="!px-3 !py-1.5 !text-[13px]" />
        </div>
    </header>

    <section aria-labelledby="elig-h"
             @class([
                 'flex flex-col gap-8 rounded-[14px] border px-7 py-6 lg:flex-row lg:items-stretch',
                 'border-[#cfe9e6] bg-[#f4fbfa]' => $eligibility->eligible,
                 'border-[#e0d8f6] bg-[#f9f7fe]' => ! $eligibility->eligible,
             ])>
        <div class="flex grow items-center gap-4.5">
            <div @class([
                'flex size-13 shrink-0 items-center justify-center rounded-[13px]',
                'bg-[#d5eeec]' => $eligibility->eligible,
                'bg-excluded-soft' => ! $eligibility->eligible,
            ])>
                @if ($eligibility->eligible)
                    <svg class="size-6.5 text-eligible" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"></circle><path d="m8 12.5 2.8 2.8L16.5 9.5"></path>
                    </svg>
                @else
                    <svg class="size-6.5 text-excluded" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"></circle><path d="m5.7 5.7 12.6 12.6"></path>
                    </svg>
                @endif
            </div>

            <div class="flex flex-col gap-1">
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

    <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
        <section aria-labelledby="info-h"
                 class="flex flex-col rounded-[14px] border border-line bg-surface xl:col-span-7">
            <div class="flex items-center gap-3 border-b border-line-soft px-6 py-4.5">
                <span class="flex size-8.5 items-center justify-center rounded-[9px] bg-brand-soft">
                    <svg class="size-4.5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 8 12 3 3 8v8l9 5 9-5z"></path><path d="m3 8 9 5 9-5"></path>
                        <path d="M12 13v8"></path>
                    </svg>
                </span>
                <h2 id="info-h" class="text-[17px] font-bold">Product information</h2>
            </div>

            <dl class="grid grid-cols-2 sm:grid-cols-3">
                @foreach ([
                    ['SKU', $product->sku, false],
                    ['Name', $product->name, false],
                    ['Name 2', $product->name_2 ?: '—', false],
                    ['Type', $product->type ?: '—', false],
                    ['Price', number_format((float) $product->price, 2), false],
                    ['Inventory', number_format((float) $product->inventory, 2), false],
                    ['Weight', number_format((float) $product->weight, 2), false],
                    ['Blocked', $product->blocked ? 'Yes' : 'No', (bool) $product->blocked],
                    ['Sales blocked', $product->sales_blocked ? 'Yes' : 'No', (bool) $product->sales_blocked],
                    ['GTIN', $product->gtin ?: '—', false],
                    ['Category', $product->item_category_id ?: '—', $retired],
                    ['Product group', $product->gppg ?: '—', $eligibility->hasReason(ExclusionReason::NotFinishedGoods)],
                    ['BC modified', $product->bc_modified_at?->format('d M Y H:i:s') ?? '—', false],
                ] as [$label, $value, $flagged])
                    <div class="flex flex-col gap-1.5 border-b border-line-soft px-6 py-4">
                        <dt class="text-xs font-medium text-ink-muted">{{ $label }}</dt>
                        <dd @class([
                            'text-[15px] break-words tabular-nums',
                            'font-bold text-excluded-ink' => $flagged,
                            'font-medium text-ink' => ! $flagged,
                        ])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section aria-labelledby="sync-h"
                 class="flex flex-col rounded-[14px] border border-line bg-surface xl:col-span-5">
            <div class="flex items-center justify-between gap-4 border-b border-line-soft px-6 py-4.5">
                <span class="flex items-center gap-3">
                    <span class="flex size-8.5 items-center justify-center rounded-[9px] bg-brand-soft">
                        <svg class="size-4.5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 12a9 9 0 0 1-15.5 6.2"></path><path d="M3 12A9 9 0 0 1 18.5 5.8"></path>
                            <path d="M18 2v4h-4"></path><path d="M6 22v-4h4"></path>
                        </svg>
                    </span>
                    <h2 id="sync-h" class="text-[17px] font-bold">Sync information</h2>
                </span>
                <span class="font-mono text-xs text-ink-muted">sync_records</span>
            </div>

            <dl class="flex flex-col px-6 pt-1.5 pb-5">
                <div class="flex items-center justify-between gap-4 border-b border-line-soft py-3">
                    <dt class="text-[13px] text-ink-muted">Sync status</dt>
                    <dd><x-status-badge :status="$deliveryStatus" /></dd>
                </div>

                <div class="flex flex-col gap-1.5 border-b border-line-soft py-3">
                    <dt class="text-[13px] text-ink-muted">What that means</dt>
                    <dd class="text-sm text-ink-soft">{{ $deliveryStatus->explain() }}</dd>
                </div>
            </dl>

            @if ($syncRecord)
                <dl class="flex flex-col px-6 pb-5">

                    <div class="flex flex-col gap-2 border-b border-line-soft py-3">
                        <dt class="text-[13px] text-ink-muted">Changed fields</dt>
                        <dd class="flex flex-wrap gap-1.5">
                            @forelse ($syncRecord->changed_fields ?? [] as $field)
                                <span class="rounded-md bg-fill px-2 py-0.75 font-mono text-xs text-ink-soft">{{ $field }}</span>
                            @empty
                                <span class="text-sm text-ink-faint">None</span>
                            @endforelse
                        </dd>
                    </div>

                    <div class="flex flex-col gap-1.5 border-b border-line-soft py-3">
                        <dt class="text-[13px] text-ink-muted">Payload hash</dt>
                        <dd class="font-mono text-xs leading-relaxed break-all text-ink-soft">
                            {{ $syncRecord->payload_hash ?? '—' }}
                        </dd>
                    </div>

                    <div class="flex items-center justify-between gap-4 border-b border-line-soft py-3">
                        <dt class="text-[13px] text-ink-muted">Dispatched at</dt>
                        <dd @class([
                            'text-sm tabular-nums',
                            'text-ink' => $syncRecord->dispatched_at,
                            'text-ink-faint' => ! $syncRecord->dispatched_at,
                        ])>{{ $syncRecord->dispatched_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                    </div>

                    <div class="flex items-center justify-between gap-4 border-b border-line-soft py-3">
                        <dt class="text-[13px] text-ink-muted">Synced at</dt>
                        <dd @class([
                            'text-sm tabular-nums',
                            'text-ink' => $syncRecord->synced_at,
                            'text-ink-faint' => ! $syncRecord->synced_at,
                        ])>{{ $syncRecord->synced_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                    </div>

                    <div class="flex flex-col gap-2 pt-3">
                        <dt class="text-[13px] text-ink-muted">Last error</dt>
                        @if ($syncRecord->last_error)
                            <dd class="rounded-[9px] border border-[#f4dad6] bg-[#fff7f6] px-3 py-2.5 font-mono text-xs leading-relaxed text-failed-ink">
                                {{ $syncRecord->last_error }}
                            </dd>
                        @else
                            <dd class="text-sm text-ink-faint">None</dd>
                        @endif
                    </div>
                </dl>
            @else
                <p class="px-6 pb-6 text-sm text-ink-muted">
                    No sync record exists for this product on the items channel.
                </p>
            @endif
        </section>
    </div>

    <section aria-labelledby="website-state-h"
             class="flex flex-col rounded-[14px] border border-line bg-surface">
        <div class="flex items-center justify-between gap-4 border-b border-line-soft px-6 py-4.5">
            <span class="flex items-center gap-3">
                <span class="flex size-8.5 items-center justify-center rounded-[9px] bg-brand-soft">
                    <svg class="size-4.5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"></circle><path d="M3.6 9h16.8M3.6 15h16.8"></path>
                        <path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18z"></path>
                    </svg>
                </span>
                <span class="flex flex-col">
                    <h2 id="website-state-h" class="text-[17px] font-bold">Website state</h2>
                    <span class="text-[13px] text-ink-muted">
                        How this product should appear, calculated from the Business Central data above
                    </span>
                </span>
            </span>
            <span class="font-mono text-xs text-ink-muted max-sm:hidden">read-only</span>
        </div>

        <div class="border-b border-line-soft bg-[#f8faf9] px-6 py-5">
            <span class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Final website decision</span>

            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3.5 sm:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    ['Exists on website', $websiteState->existsOnWebsite() ? 'Yes' : 'No', ! $websiteState->existsOnWebsite()],
                    ['Purchasable', $websiteState->isPurchasable() ? 'Yes' : 'No', ! $websiteState->isPurchasable()],
                    ['Manage stock', $websiteState->managesStock() ? 'Yes' : 'No', false],
                    ['Website quantity', $websiteState->decision->quantityLabel(), false],
                    ['Final stock status', $websiteState->stockStatus()->label(), ! $websiteState->stockStatus()->isInStock()],
                    ['Reason if unavailable', $websiteState->unavailableReason() ?? '—', $websiteState->unavailableReason() !== null],
                ] as [$label, $value, $flagged])
                    <div class="flex flex-col gap-1">
                        <dt class="text-xs font-medium text-ink-muted">{{ $label }}</dt>
                        <dd @class([
                            'text-[15px] break-words',
                            'font-bold text-excluded-ink' => $flagged,
                            'font-medium text-ink' => ! $flagged,
                        ])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <dl class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4">
            @foreach ([
                ['Eligibility', $websiteState->eligibility->label(), ! $websiteState->isEligible()],
                ['Exclusion reasons', $websiteState->isEligible() ? '—' : implode('; ', $websiteState->eligibility->reasons()), ! $websiteState->isEligible()],
                ['SKU', $websiteState->sku, false],
                ['Name', $websiteState->name, false],
                ['Price', $websiteState->price !== null ? number_format($websiteState->price, 2) : 'Not published', $websiteState->price === null],
                ['Inventory type', $websiteState->inventoryType, false],
                ['Sales blocked', $websiteState->salesBlocked ? 'Yes' : 'No', $websiteState->salesBlocked],
                ['Barcode', $websiteState->barcode ?? '—', false],
                ['Location', $websiteState->location->code ?? 'Not resolved', ! $websiteState->location->isResolved()],
                ['Location inventory', $websiteState->location->isResolved() ? number_format($websiteState->location->inventory) : '—', false],
                ['Brand', $websiteState->brand?->label() ?? '—', false],
                ['Shipping class', $websiteState->shippingClass, false],
                ['Department', $websiteState->department?->label() ?? '—', false],
                ['Category', $websiteState->category?->label() ?? '—', false],
                ['Subcategory', $websiteState->subcategory?->label() ?? '—', false],
                ['RRP', $websiteState->rrp !== null ? number_format($websiteState->rrp, 2) : '—', false],
                ['Available to buy', $websiteState->isAvailableToBuy() ? 'Yes' : 'No', ! $websiteState->isAvailableToBuy()],
            ] as [$label, $value, $flagged])
                <div class="flex flex-col gap-1.5 border-b border-line-soft px-6 py-4">
                    <dt class="text-xs font-medium text-ink-muted">{{ $label }}</dt>
                    <dd @class([
                        'text-[15px] break-words tabular-nums',
                        'font-bold text-excluded-ink' => $flagged,
                        'font-medium text-ink' => ! $flagged,
                    ])>{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="border-t border-line-soft px-6 py-5">
            <span class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">
                Stock position
                <span class="ml-1 font-mono text-[11px] normal-case">product_quantities</span>
            </span>

            @if ($websiteState->stock->isKnown())
                <dl class="mt-2.5 grid grid-cols-2 gap-x-6 gap-y-2.5 sm:grid-cols-3 xl:grid-cols-4">
                    @foreach ([
                        ['Inventory (all locations)', number_format($websiteState->stock->inventory, 2)],
                        ['Inventory order', number_format($websiteState->stock->inventoryOrder, 2)],
                        ['Sellable stock', number_format($websiteState->stock->sellableStock, 2)],
                        ['Location capped', $websiteState->stock->locationCapped ? 'Yes' : 'No'],
                        ['On purchase order', number_format($websiteState->stock->onPurchaseOrder, 2)],
                        ['On sales order', number_format($websiteState->stock->onSalesOrder, 2)],
                        ['On transfer order', number_format($websiteState->stock->onTransferOrder, 2)],
                        ['Next purchase receipt', $websiteState->stock->nextPurchaseReceiptDate ?? '—'],
                        ['Next transfer receipt', $websiteState->stock->nextTransferReceiptDate ?? '—'],
                    ] as [$label, $value])
                        <div class="flex flex-col gap-1">
                            <dt class="text-xs font-medium text-ink-muted">{{ $label }}</dt>
                            <dd class="text-[15px] font-medium tabular-nums text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="mt-2 text-sm text-ink-faint">
                    No quantity data imported yet. Run <span class="font-mono text-[13px]">bc:import-item-quantities</span>.
                </p>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-6 border-t border-line-soft px-6 py-5 xl:grid-cols-2">
            <div class="flex flex-col gap-2.5">
                <span class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">
                    Group pricing rules
                    <span class="ml-1 font-mono text-[11px] normal-case">({{ count($websiteState->pricingRules) }})</span>
                </span>

                @if ($websiteState->pricingRules)
                    <div class="overflow-x-auto rounded-[10px] border border-line-soft">
                        <table class="w-full text-left text-[13px]">
                            <thead class="bg-fill text-xs text-ink-muted">
                                <tr>
                                    <th scope="col" class="px-3 py-2 font-medium">Sales type</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Code</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Min qty</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Effect</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($websiteState->pricingRules as $rule)
                                    <tr class="border-t border-line-soft">
                                        <td class="px-3 py-2 text-ink-soft">{{ $rule->salesTypeLabel() ?: '—' }}</td>
                                        <td class="px-3 py-2 font-medium text-ink">{{ $rule->salesCode }}</td>
                                        <td class="px-3 py-2 tabular-nums text-ink-soft">{{ $rule->minimumQuantity }}</td>
                                        <td class="px-3 py-2 tabular-nums font-medium text-ink">{{ $rule->label() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <span class="text-sm text-ink-faint">No pricing rules</span>
                @endif
            </div>

            <div class="flex flex-col gap-2.5">
                <span class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">
                    Attributes
                    <span class="ml-1 font-mono text-[11px] normal-case">({{ count($websiteState->attributes) }})</span>
                </span>

                @if ($websiteState->attributes)
                    <dl class="flex flex-col rounded-[10px] border border-line-soft">
                        @foreach ($websiteState->attributes as $attribute)
                            <div @class([
                                'flex items-center justify-between gap-4 px-3 py-2 text-[13px]',
                                'border-t border-line-soft' => ! $loop->first,
                            ])>
                                <dt class="text-ink-muted">{{ $attribute->name ?: '—' }}</dt>
                                <dd class="font-medium text-ink">{{ $attribute->value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <span class="text-sm text-ink-faint">No attributes</span>
                @endif
            </div>
        </div>
    </section>

    <details class="group flex flex-col overflow-hidden rounded-[14px] border border-line bg-surface" open>
        <summary class="flex min-h-16.5 cursor-pointer list-none items-center justify-between gap-4 px-6">
            <span class="flex items-center gap-3">
                <span class="flex size-8.5 shrink-0 items-center justify-center rounded-[9px] bg-fill">
                    <svg class="size-4.25 text-ink-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m8 6-6 6 6 6"></path><path d="m16 6 6 6-6 6"></path>
                    </svg>
                </span>
                <span class="text-[17px] font-bold">Raw Business Central data</span>
                <span class="text-[13px] text-ink-muted max-sm:hidden">
                    Stored payload as received from Business Central
                </span>
            </span>

            <span class="flex h-9 shrink-0 items-center gap-2 rounded-[9px] border border-line px-3 text-[13px] font-semibold">
                <span class="group-open:hidden">Show</span>
                <span class="hidden group-open:inline">Hide</span>
                <svg class="size-3.75 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <path d="m6 9 6 6 6-6"></path>
                </svg>
            </span>
        </summary>

        <div class="mx-3 mb-3 overflow-hidden rounded-[10px] bg-[#17211d]">
            <div class="flex justify-between border-b border-[#28332e] px-5 py-2.5 font-mono text-xs text-[#a7b0ab]">
                <span>application/json</span>
                <span>{{ $product->bc_modified_at?->format('d M Y H:i:s') ?? 'unknown' }}</span>
            </div>
            <pre class="overflow-x-auto p-5 font-mono text-[12.5px] leading-relaxed text-[#d8e0db]">{{ json_encode($product->bc_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </details>
</x-layout>
