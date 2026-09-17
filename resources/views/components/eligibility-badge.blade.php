@props(['eligible' => false, 'label' => null])

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold '
        .($eligible ? 'bg-eligible-soft text-eligible-ink' : 'bg-excluded-soft text-excluded-ink'),
]) }}>
    <span class="size-1.5 rounded-full {{ $eligible ? 'bg-eligible' : 'bg-excluded' }}"></span>
    {{ $label }}
</span>
