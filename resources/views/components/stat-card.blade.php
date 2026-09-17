@props([
    'label',
    'value',
    'caption',
    'href',
    'tone' => 'neutral',
])

@php
    $tones = [
        'neutral' => ['card' => 'border-line bg-surface', 'tile' => 'bg-brand-soft', 'icon' => 'text-brand'],
        'pending' => ['card' => 'border-[#f3e6cc] bg-[#fffbf4]', 'tile' => 'bg-[#fcebcb]', 'icon' => 'text-[#c07a0e]'],
        'synced' => ['card' => 'border-[#dceee4] bg-[#f6fbf8]', 'tile' => 'bg-[#ddf1e6]', 'icon' => 'text-brand'],
        'failed' => ['card' => 'border-[#f4dad6] bg-[#fff7f6]', 'tile' => 'bg-[#fce1dd]', 'icon' => 'text-[#c8322a]'],
        'excluded' => ['card' => 'border-[#e5e0f5] bg-[#f9f7fe]', 'tile' => 'bg-[#ece6fb]', 'icon' => 'text-[#6d4fd1]'],
    ][$tone];
@endphp

<a href="{{ $href }}"
   class="flex flex-col gap-5 rounded-[14px] border px-5 pt-4.5 pb-6 text-ink transition-shadow hover:shadow-[0_4px_16px_rgba(24,33,29,0.07)] {{ $tones['card'] }}">
    <span class="flex items-center gap-3.5">
        <span class="flex size-11.5 shrink-0 items-center justify-center rounded-[11px] {{ $tones['tile'] }}">
            <svg class="size-5.5 {{ $tones['icon'] }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{{ $icon }}</svg>
        </span>
        <span class="text-sm font-medium">{{ $label }}</span>
    </span>

    <span class="text-[40px] leading-none font-extrabold tracking-tight tabular-nums"
          data-stat="{{ Str::slug($label) }}">{{ number_format($value) }}</span>

    <span class="text-[13px] text-ink-muted">{{ $caption }}</span>
</a>
