@props(['eligibility'])

@if ($eligibility->eligible)
    <p class="text-[15px] text-ink-soft">Passes all website rules and will be included on the website.</p>
@else
    <div class="flex flex-col gap-1.5">
        <span class="text-[13px] font-medium text-ink-soft">
            {{ $eligibility->reasonCount() === 1 ? 'Reason:' : 'Reasons:' }}
        </span>
        <ul class="flex flex-col gap-1">
            @foreach ($eligibility->reasons() as $reason)
                <li class="flex items-start gap-2 text-[15px] text-ink-soft">
                    <span class="mt-2 size-1.5 shrink-0 rounded-full bg-excluded" aria-hidden="true"></span>
                    <span>{{ $reason }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
