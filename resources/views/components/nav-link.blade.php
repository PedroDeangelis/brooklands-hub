@props(['href', 'current' => false, 'icon' => null])

<a href="{{ $href }}"
   @if ($current) aria-current="page" @endif
   @class([
       'flex h-11.5 items-center gap-3.5 rounded-[10px] px-4 text-[15px]',
       'bg-brand-soft font-semibold text-brand-ink' => $current,
       'font-medium text-ink hover:bg-[#eef3f0]' => ! $current,
   ])>
    <svg class="size-4.75 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{{ $icon }}</svg>
    {{ $slot }}
</a>
