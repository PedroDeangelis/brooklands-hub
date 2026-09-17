@php
    use App\SalesInvoices\SalesInvoiceFilter;
    use App\SalesInvoices\SalesInvoiceKind;
    use App\Sync\Payload\DeliveryType;

    $lines = $salesInvoice->lines();
    $kind = $salesInvoice->kind();

    // Back to the list this document belongs to in the sidebar.
    $listUrl = $kind === SalesInvoiceKind::CreditMemo
        ? route('sales-invoices.index', [SalesInvoiceFilter::PARAM_KIND => $kind->value])
        : route('sales-invoices.index');
    $listLabel = $kind === SalesInvoiceKind::CreditMemo ? 'Sales credit memos' : 'Sales invoices';
@endphp

<x-layout :title="$salesInvoice->number">
    <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[13px] text-ink-muted">
        <a href="{{ $listUrl }}" class="font-medium text-brand-ink hover:text-brand-deep">{{ $listLabel }}</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page" class="font-mono text-ink">{{ $salesInvoice->number }}</span>
    </nav>

    <header class="flex items-end justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <span class="font-mono text-sm font-medium text-ink-muted">Number {{ $salesInvoice->number }}</span>
            <h1 class="text-[32px] font-extrabold tracking-tight">{{ $salesInvoice->title() }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-2 pb-1">
            <x-sales-invoice-kind-badge :kind="$kind" class="!px-3 !py-1.5 !text-[13px]" />
            <x-status-badge :status="$deliveryStatus" class="!px-3 !py-1.5 !text-[13px]" />
        </div>
    </header>

    {{-- What the website actually holds, asked of the website itself. --}}
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
                    The ledger below says this document was delivered, but the website does not have it.
                    Something removed it after delivery. Re-send it with
                    <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-[12.5px]">php artisan website:deliver-sales-invoices --number={{ $salesInvoice->number }} --send</code>
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
                The website could not be reached, so whether it holds this document is unknown.
            </p>
        </section>
    @endif

    @if ($customer === null)
        <section class="flex items-start gap-4 rounded-[14px] border border-[#e0d8f6] bg-[#f9f7fe] px-6 py-4">
            <svg class="mt-0.5 size-5 shrink-0 text-excluded" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle><path d="M12 8v5"></path><path d="M12 16h.01"></path>
            </svg>
            <p class="text-sm text-excluded-ink">
                The customer for this document has not been imported yet. Delivery waits until the customer
                reaches the website, because the post links to the customer post.
            </p>
        </section>
    @endif

    <section aria-labelledby="summary-h" class="flex flex-col gap-8 rounded-[14px] border border-[#cfe9e6] bg-[#f4fbfa] px-7 py-6 lg:flex-row lg:items-stretch">
        <div class="flex grow items-center gap-4.5">
            <div class="flex size-13 shrink-0 items-center justify-center rounded-[13px] bg-[#d5eeec]">
                <svg class="size-6.5 text-eligible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path>
                    <path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h8"></path>
                </svg>
            </div>

            <div class="flex flex-col gap-1">
                <span id="summary-h" class="text-xs font-bold tracking-[0.06em] text-eligible-ink uppercase">Posted {{ mb_strtolower($kind->label()) }}</span>
                <span class="text-[26px] font-extrabold tracking-tight text-ink">{{ number_format((float) $salesInvoice->total_amount_including_tax, 2) }}</span>
                <span class="text-[13px] text-ink-muted">Including tax. Posted documents are immutable; the website keeps every one as the customer's history.</span>
            </div>
        </div>

        <div class="flex min-w-90 flex-col justify-center gap-2.5 rounded-xl border border-line bg-surface px-5 py-4">
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">{{ $kind->dateLabel() }}</span>
                <span class="text-sm tabular-nums text-ink">{{ $salesInvoice->invoice_date?->format('d M Y') ?? '—' }}</span>
            </div>
            @unless ($salesInvoice->isCreditMemo())
                <div class="flex items-center justify-between gap-6">
                    <span class="text-xs font-medium text-ink-muted">Order date</span>
                    <span class="text-sm tabular-nums text-ink">{{ $salesInvoice->order_date?->format('d M Y') ?? '—' }}</span>
                </div>
            @endunless
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Total excl. tax / tax</span>
                <span class="text-sm tabular-nums text-ink">{{ number_format((float) $salesInvoice->total_amount_excluding_tax, 2) }} <span class="text-ink-muted">/ {{ number_format((float) $salesInvoice->total_tax_amount, 2) }}</span></span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">{{ $kind->orderLabel() }}</span>
                <span class="text-sm text-ink">
                    @if ($salesOrder)
                        <a href="{{ route('sales-orders.show', $salesOrder) }}" class="font-mono font-medium text-brand-ink hover:text-brand-deep">{{ $salesOrder->number }}</a>
                    @elseif ($salesInvoice->order_number)
                        <span class="font-mono">{{ $salesInvoice->order_number }}</span>
                        <span class="text-ink-muted">(no longer in Business Central)</span>
                    @else
                        —
                    @endif
                </span>
            </div>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section aria-labelledby="info-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="info-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Business Central</h2>

            <dl class="flex flex-col gap-3">
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Document id</dt>
                    <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $salesInvoice->bc_id }}</dd>
                </div>
                @if ($salesInvoice->isCreditMemo())
                    <div class="flex items-start justify-between gap-6">
                        <dt class="text-xs font-medium text-ink-muted">Document API id</dt>
                        <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $salesInvoice->document_api_id ?: '—' }}</dd>
                    </div>
                @endif
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Number</dt>
                    <dd class="font-mono text-sm text-ink">{{ $salesInvoice->number }}</dd>
                </div>
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Customer</dt>
                    <dd class="text-right text-sm text-ink">
                        @if ($customer)
                            <a href="{{ route('customers.show', $customer) }}" class="font-medium text-brand-ink hover:text-brand-deep">{{ $customer->number }}</a>
                            <span class="text-ink-muted">{{ $customer->title() }}</span>
                        @else
                            {{ $salesInvoice->customer_name ?: '—' }}
                            <span class="block font-mono text-xs text-ink-faint">{{ $salesInvoice->customer_bc_id ?: 'no customer id' }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">PO number</dt>
                    <dd class="text-sm text-ink">{{ $salesInvoice->external_document_number ?: '—' }}</dd>
                </div>
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Ship to</dt>
                    <dd class="text-right text-sm text-ink">{{ $salesInvoice->shipToAddress() ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Posted</dt>
                    <dd class="text-sm tabular-nums text-ink">{{ $salesInvoice->bc_created_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Last modified</dt>
                    <dd class="text-sm tabular-nums text-ink">
                        {{ $salesInvoice->bc_modified_at?->format('d M Y H:i:s.v') ?? '—' }}
                    </dd>
                </div>
                @if ($salesInvoice->work_description)
                    <div class="flex flex-col gap-1.5 rounded-[10px] bg-fill px-3.5 py-3">
                        <dt class="text-xs font-semibold text-ink-muted">Work description</dt>
                        <dd class="text-sm leading-relaxed whitespace-pre-line text-ink">{{ $salesInvoice->work_description }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section aria-labelledby="sync-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="sync-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Delivery</h2>

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
                    No ledger row yet. This document has never been queued for delivery.
                </p>
            @endif
        </section>
    </div>

    <section aria-labelledby="lines-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
        <div class="flex items-baseline justify-between gap-4">
            <h2 id="lines-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Lines</h2>
            <span class="text-[13px] text-ink-muted">{{ number_format(count($lines)) }} {{ Str::plural('line', count($lines)) }}</span>
        </div>

        @if ($lines === [])
            <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">
                No lines have been imported for this document.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-3xl border-collapse text-sm">
                    <thead>
                        <tr class="bg-fill-strong text-left text-[13px] font-medium text-ink-muted">
                            <th class="h-10 rounded-l-[9px] px-3.5 font-medium">Item</th>
                            <th class="px-3.5 font-medium">Description</th>
                            <th class="px-3.5 text-right font-medium">Qty</th>
                            <th class="rounded-r-[9px] px-3.5 text-right font-medium">Unit price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr class="border-b border-line-soft">
                                <td class="h-12 px-3.5 font-mono text-[13px] text-ink">{{ $line['item_number'] ?? '—' }}</td>
                                <td class="px-3.5">{{ $line['description'] ?? '' }}</td>
                                <td class="px-3.5 text-right tabular-nums">{{ number_format((float) ($line['quantity'] ?? 0), 2) }}</td>
                                <td class="px-3.5 text-right tabular-nums">{{ number_format((float) ($line['unit_price'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="preview-h" class="flex flex-col rounded-[14px] border border-line bg-surface">
        <div class="flex flex-col gap-1.5 border-b border-line px-6 py-5">
            <h2 id="preview-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Next delivery</h2>
            <p class="text-[13px] text-ink-muted">
                Rebuilt from the document as it stands. Invoices and credit memos are always upserted: Laravel
                mirrors Business Central and the website keeps every posted document as the customer's history.
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
