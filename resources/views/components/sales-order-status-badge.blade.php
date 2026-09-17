@props(['status'])

@php
    use App\SalesOrders\SalesOrderStatus;

    $classes = match ($status) {
        SalesOrderStatus::Completed => 'bg-synced-soft text-synced-ink',
        SalesOrderStatus::FullyShipped => 'bg-eligible-soft text-eligible-ink',
        SalesOrderStatus::PartiallyShipped => 'bg-pending-soft text-pending-ink',
        SalesOrderStatus::OnHold => 'bg-excluded-soft text-excluded-ink',
        default => 'bg-fill text-ink-muted',
    };

    $dot = match ($status) {
        SalesOrderStatus::Completed => 'bg-synced',
        SalesOrderStatus::FullyShipped => 'bg-eligible',
        SalesOrderStatus::PartiallyShipped => 'bg-pending',
        SalesOrderStatus::OnHold => 'bg-excluded',
        default => 'bg-[#b9c0bc]',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {$classes}"]) }}
      title="{{ $status->explain() }}">
    <span class="size-1.5 rounded-full {{ $dot }}"></span>
    {{ $status->label() }}
</span>
