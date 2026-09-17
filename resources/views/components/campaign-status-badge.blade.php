@props(['status'])

@php
    use App\Campaigns\CampaignStatus;

    $classes = match ($status) {
        CampaignStatus::Active => 'bg-eligible-soft text-eligible-ink',
        CampaignStatus::Scheduled => 'bg-pending-soft text-pending-ink',
        CampaignStatus::Ended => 'bg-fill text-ink-muted',
        CampaignStatus::Deactivated => 'bg-excluded-soft text-excluded-ink',
    };

    $dot = match ($status) {
        CampaignStatus::Active => 'bg-eligible',
        CampaignStatus::Scheduled => 'bg-pending',
        CampaignStatus::Ended => 'bg-[#b9c0bc]',
        CampaignStatus::Deactivated => 'bg-excluded',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {$classes}"]) }}
      title="{{ $status->explain() }}">
    <span class="size-1.5 rounded-full {{ $dot }}"></span>
    {{ $status->label() }}
</span>
