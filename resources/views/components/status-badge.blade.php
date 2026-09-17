@props(['status' => null])

@php
    use App\Sync\DeliveryStatus;

    $status ??= DeliveryStatus::NotSynced;
    $key = $status->value;

    $classes = match ($key) {
        'synced' => 'bg-synced-soft text-synced-ink',
        'pending' => 'bg-pending-soft text-pending-ink',
        'syncing' => 'bg-pending-soft text-pending-ink',
        'failed' => 'bg-failed-soft text-failed-ink',
        'conflict' => 'bg-conflict-soft text-conflict-ink',
        default => 'bg-fill text-ink-muted',
    };

    $dot = match ($key) {
        'synced' => 'bg-synced',
        'pending' => 'bg-pending',
        'syncing' => 'bg-pending',
        'failed' => 'bg-failed',
        'conflict' => 'bg-conflict',
        default => 'bg-[#b9c0bc]',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {$classes}"]) }}
      title="{{ $status->explain() }}">
    <span class="size-1.5 rounded-full {{ $dot }}"></span>
    {{ $status->label() }}
</span>
