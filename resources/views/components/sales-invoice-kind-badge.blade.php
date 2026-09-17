@props(['kind'])

@php
    use App\SalesInvoices\SalesInvoiceKind;

    $classes = $kind === SalesInvoiceKind::CreditMemo ? 'bg-pending-soft text-pending-ink' : 'bg-fill text-ink-muted';
    $dot = $kind === SalesInvoiceKind::CreditMemo ? 'bg-pending' : 'bg-[#b9c0bc]';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {$classes}"]) }}>
    <span class="size-1.5 rounded-full {{ $dot }}"></span>
    {{ $kind->label() }}
</span>
