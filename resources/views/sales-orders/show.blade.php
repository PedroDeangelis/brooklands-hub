@php
    use App\SalesOrders\SalesOrderStatus;
    use App\Sync\Payload\DeliveryType;

    $lines = $salesOrder->lines();
    $shipments = $salesOrder->shipments();
    $invoices = $salesOrder->invoices();
    $isFinal = $status->isFinal();
@endphp

<x-layout :title="$salesOrder->number">
    <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[13px] text-ink-muted">
        <a href="{{ route('sales-orders.index') }}" class="font-medium text-brand-ink hover:text-brand-deep">Sales orders</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page" class="font-mono text-ink">{{ $salesOrder->number }}</span>
    </nav>

    <header class="flex items-end justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <span class="font-mono text-sm font-medium text-ink-muted">Number {{ $salesOrder->number }}</span>
            <h1 class="text-[32px] font-extrabold tracking-tight">{{ $salesOrder->title() }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-2 pb-1">
            <x-sales-order-status-badge :status="$status" class="!px-3 !py-1.5 !text-[13px]" />
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
                    The ledger below says this order was delivered, but the website does not have it.
                    Something removed it after delivery. Re-send it with
                    <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-[12.5px]">php artisan website:deliver-sales-orders --number={{ $salesOrder->number }} --send</code>
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
                The website could not be reached, so whether it holds this order is unknown.
            </p>
        </section>
    @endif

    {{-- An order whose customer has not reached the website cannot be delivered
         yet: the order post links to the customer post. --}}
    @if ($customer === null)
        <section class="flex items-start gap-4 rounded-[14px] border border-[#e0d8f6] bg-[#f9f7fe] px-6 py-4">
            <svg class="mt-0.5 size-5 shrink-0 text-excluded" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle><path d="M12 8v5"></path><path d="M12 16h.01"></path>
            </svg>
            <p class="text-sm text-excluded-ink">
                The customer for this order has not been imported yet. Delivery waits until the customer
                reaches the website, because the order post links to the customer post.
            </p>
        </section>
    @endif

    {{-- Progress. The website status is decided here from the lines, so it is
         shown together with what it was derived from. --}}
    <section aria-labelledby="window-h"
             @class([
                 'flex flex-col gap-8 rounded-[14px] border px-7 py-6 lg:flex-row lg:items-stretch',
                 'border-[#cfe9e6] bg-[#f4fbfa]' => $isFinal,
                 'border-line bg-fill' => ! $isFinal,
             ])>
        <div class="flex grow items-center gap-4.5">
            <div @class([
                'flex size-13 shrink-0 items-center justify-center rounded-[13px]',
                'bg-[#d5eeec]' => $isFinal,
                'bg-fill-strong' => ! $isFinal,
            ])>
                <svg @class(['size-6.5', 'text-eligible' => $isFinal, 'text-ink-muted' => ! $isFinal])
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
                    <path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>

            <div class="flex flex-col gap-1">
                <span id="window-h" @class([
                    'text-xs font-bold tracking-[0.06em] uppercase',
                    'text-eligible-ink' => $isFinal,
                    'text-ink-muted' => ! $isFinal,
                ])>Website status</span>

                <span class="text-[26px] font-extrabold tracking-tight text-ink">{{ $status->label() }}</span>
                <span class="text-[13px] text-ink-muted">{{ $status->explain() }}</span>
            </div>
        </div>

        <div class="flex min-w-90 flex-col justify-center gap-2.5 rounded-xl border border-line bg-surface px-5 py-4">
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Business Central status</span>
                <span class="text-sm font-semibold text-ink">{{ $salesOrder->bc_status ?: '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Order date</span>
                <span class="text-sm tabular-nums text-ink">{{ $salesOrder->order_date?->format('d M Y') ?? '—' }}</span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Total excl. / incl. tax</span>
                <span class="text-sm tabular-nums text-ink">{{ number_format((float) $salesOrder->total_amount_excluding_tax, 2) }} <span class="text-ink-muted">/ {{ number_format((float) $salesOrder->total_amount_including_tax, 2) }}</span></span>
            </div>
            <div class="flex items-center justify-between gap-6">
                <span class="text-xs font-medium text-ink-muted">Lines / shipments / invoices</span>
                <span class="text-sm tabular-nums text-ink">{{ count($lines) }} <span class="text-ink-muted">/ {{ count($shipments) }} / {{ count($invoices) }}</span></span>
            </div>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Business Central identity --}}
        <section aria-labelledby="info-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <h2 id="info-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Business Central</h2>

            <dl class="flex flex-col gap-3">
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Order id</dt>
                    <dd class="text-right font-mono text-xs break-all text-ink-soft">{{ $salesOrder->bc_id }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Number</dt>
                    <dd class="font-mono text-sm text-ink">{{ $salesOrder->number }}</dd>
                </div>
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Customer</dt>
                    <dd class="text-right text-sm text-ink">
                        @if ($customer)
                            <a href="{{ route('customers.show', $customer) }}" class="font-medium text-brand-ink hover:text-brand-deep">{{ $customer->number }}</a>
                            <span class="text-ink-muted">{{ $customer->title() }}</span>
                        @else
                            {{ $salesOrder->customer_name ?: '—' }}
                            <span class="block font-mono text-xs text-ink-faint">{{ $salesOrder->customer_bc_id ?: 'no customer id' }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">PO number</dt>
                    <dd class="text-sm text-ink">{{ $salesOrder->external_document_number ?: '—' }}</dd>
                </div>
                <div class="flex items-start justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Ship to</dt>
                    <dd class="text-right text-sm text-ink">{{ $salesOrder->shipToAddress() ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Created</dt>
                    <dd class="text-sm tabular-nums text-ink">{{ $salesOrder->bc_created_at?->format('d M Y H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-6">
                    <dt class="text-xs font-medium text-ink-muted">Last modified</dt>
                    <dd class="text-sm tabular-nums text-ink">
                        {{ $salesOrder->bc_modified_at?->format('d M Y H:i:s.v') ?? '—' }}
                    </dd>
                </div>
                @if ($salesOrder->work_description)
                    <div class="flex flex-col gap-1.5 rounded-[10px] bg-fill px-3.5 py-3">
                        <dt class="text-xs font-semibold text-ink-muted">Work description</dt>
                        <dd class="text-sm leading-relaxed whitespace-pre-line text-ink">{{ $salesOrder->work_description }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- Delivery ledger --}}
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
                    No ledger row yet. This order has never been queued for delivery.
                </p>
            @endif
        </section>
    </div>

    {{-- Lines, as delivered: the whole list is sent and replaced together. --}}
    <section aria-labelledby="lines-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
        <div class="flex items-baseline justify-between gap-4">
            <h2 id="lines-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Lines</h2>
            <span class="text-[13px] text-ink-muted">{{ number_format(count($lines)) }} {{ Str::plural('line', count($lines)) }}</span>
        </div>

        @if ($lines === [])
            <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">
                No lines have been imported for this order.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-3xl border-collapse text-sm">
                    <thead>
                        <tr class="bg-fill-strong text-left text-[13px] font-medium text-ink-muted">
                            <th class="h-10 rounded-l-[9px] px-3.5 font-medium">Item</th>
                            <th class="px-3.5 font-medium">Description</th>
                            <th class="px-3.5 text-right font-medium">Qty</th>
                            <th class="px-3.5 text-right font-medium">Unit price</th>
                            <th class="px-3.5 text-right font-medium">To ship</th>
                            <th class="px-3.5 text-right font-medium">Shipped</th>
                            <th class="rounded-r-[9px] px-3.5 text-right font-medium">Invoiced</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr class="border-b border-line-soft">
                                <td class="h-12 px-3.5 font-mono text-[13px] text-ink">{{ $line['item_number'] ?? '—' }}</td>
                                <td class="px-3.5">{{ $line['description'] ?? '' }}</td>
                                <td class="px-3.5 text-right tabular-nums">{{ number_format((float) ($line['quantity'] ?? 0), 2) }}</td>
                                <td class="px-3.5 text-right tabular-nums">{{ number_format((float) ($line['unit_price'] ?? 0), 2) }}</td>
                                <td class="px-3.5 text-right tabular-nums text-ink-muted">{{ number_format((float) ($line['quantity_to_ship'] ?? 0), 2) }}</td>
                                <td class="px-3.5 text-right tabular-nums">{{ number_format((float) ($line['quantity_shipped'] ?? 0), 2) }}</td>
                                <td class="px-3.5 text-right tabular-nums">{{ number_format((float) ($line['quantity_invoiced'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section aria-labelledby="shipments-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <div class="flex items-baseline justify-between gap-4">
                <h2 id="shipments-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Shipments</h2>
                <span class="text-[13px] text-ink-muted">{{ number_format(count($shipments)) }}</span>
            </div>

            @if ($shipments === [])
                <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">Nothing has shipped from this order yet.</p>
            @else
                <ul class="flex flex-col gap-2">
                    @foreach ($shipments as $shipment)
                        <li class="flex items-center justify-between gap-4 rounded-[8px] bg-fill px-3.5 py-2.5 text-[13px]">
                            <span class="font-mono font-semibold text-ink">{{ $shipment['number'] ?? '—' }}</span>
                            <span class="text-ink-muted">{{ $shipment['external_document_number'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="invoices-h" class="flex flex-col gap-4 rounded-[14px] border border-line bg-surface px-6 py-5">
            <div class="flex items-baseline justify-between gap-4">
                <h2 id="invoices-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Invoices</h2>
                <span class="text-[13px] text-ink-muted">{{ number_format(count($invoices)) }}</span>
            </div>

            @if ($invoices === [])
                <p class="rounded-[10px] bg-fill px-4 py-3.5 text-sm text-ink-muted">Nothing has been invoiced from this order yet.</p>
            @else
                <ul class="flex flex-col gap-2">
                    @foreach ($invoices as $invoice)
                        <li class="flex items-center justify-between gap-4 rounded-[8px] bg-fill px-3.5 py-2.5 text-[13px]">
                            <span class="font-mono font-semibold text-ink">{{ $invoice['number'] ?? '—' }}</span>
                            <span class="font-mono text-xs text-ink-faint">{{ $invoice['bc_id'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- What would go over the wire right now. --}}
    <section aria-labelledby="preview-h" class="flex flex-col rounded-[14px] border border-line bg-surface">
        <div class="flex flex-col gap-1.5 border-b border-line px-6 py-5">
            <h2 id="preview-h" class="text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">Next delivery</h2>
            <p class="text-[13px] text-ink-muted">
                Rebuilt from the order as it stands. Orders are always upserted: Laravel mirrors
                Business Central and the website keeps the last state as the customer's history.
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
