@props(['action'])

@php
    use App\Sync\WebsiteAction;

    $classes = $action === WebsiteAction::Remove
        ? 'bg-excluded-soft text-excluded-ink'
        : 'bg-synced-soft text-synced-ink';

    $dot = $action === WebsiteAction::Remove ? 'bg-excluded' : 'bg-synced';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {$classes}"]) }}
      title="{{ $action->explain() }}">
    <span class="size-1.5 rounded-full {{ $dot }}"></span>
    {{ $action->label() }}
</span>
