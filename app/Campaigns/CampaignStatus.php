<?php

namespace App\Campaigns;

use App\Models\Campaign;
use Carbon\CarbonImmutable;

/**
 * Where a campaign sits in its own lifecycle, for display.
 *
 * Read-only and derived: this decides nothing and is never consulted by the
 * sync. Laravel mirrors Business Central's campaign state and the website
 * decides what to show, so this exists purely so a person can see at a glance
 * why a promotion is or is not live without reading three columns and doing the
 * date arithmetic themselves.
 *
 * Deactivated outranks the dates deliberately: a campaign Business Central has
 * switched off is off whatever its window says, and showing it as "Ended" would
 * suggest the dates were the reason.
 */
enum CampaignStatus: string
{
    /** Activated, started, and not yet finished. */
    case Active = 'active';

    /** Activated, but its start date has not arrived. */
    case Scheduled = 'scheduled';

    /** Activated, but its end date has passed. */
    case Ended = 'ended';

    /** Switched off in Business Central, whatever the dates say. */
    case Deactivated = 'deactivated';

    /**
     * Read a campaign's current lifecycle position.
     *
     * The comparison is made in the application timezone rather than UTC,
     * because these are calendar dates a person set in Business Central, not
     * instants.
     */
    public static function for(Campaign $campaign, ?CarbonImmutable $today = null): self
    {
        if (! $campaign->activated) {
            return self::Deactivated;
        }

        $today ??= CarbonImmutable::now()->startOfDay();

        if ($campaign->starting_date !== null && $campaign->starting_date->greaterThan($today)) {
            return self::Scheduled;
        }

        if ($campaign->ending_date !== null && $campaign->ending_date->lessThan($today)) {
            return self::Ended;
        }

        return self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Scheduled => 'Scheduled',
            self::Ended => 'Ended',
            self::Deactivated => 'Deactivated',
        };
    }

    /**
     * What this status means, for a tooltip.
     */
    public function explain(): string
    {
        return match ($this) {
            self::Active => 'Activated in Business Central and inside its date window.',
            self::Scheduled => 'Activated, but its start date has not arrived yet.',
            self::Ended => 'Activated, but its end date has passed.',
            self::Deactivated => 'Switched off in Business Central, whatever its dates say.',
        };
    }

    /**
     * Whether the website would currently show this promotion.
     */
    public function isLive(): bool
    {
        return $this === self::Active;
    }
}
