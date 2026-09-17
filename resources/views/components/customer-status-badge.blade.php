@props(['status'])

@php
    use App\Customers\CustomerStatus;

    $classes = $status === CustomerStatus::Blocked ? 'bg-excluded-soft text-excluded-ink' : 'bg-eligible-soft text-eligible-ink';
    $dot = $status === CustomerStatus::Blocked ? 'bg-excluded' : 'bg-eligible';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {$classes}"]) }}
      title="{{ $status->explain() }}">
    <span class="size-1.5 rounded-full {{ $dot }}"></span>
    {{ $status->label() }}
</span>
