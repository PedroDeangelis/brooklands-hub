<?php

namespace App\Models;

use App\Sync\CampaignSyncLedger;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The latest known Business Central state for a single campaign.
 *
 * Business Central calls these Campaigns; the website calls them Promotions.
 *
 * A campaign is an audience and a date window, not a set of products. Which
 * products a campaign affects is carried by the items sync instead: an item's
 * price list lines arrive with salesType "Campaign" and a salesCode matching
 * this code, and the website joins the two at render time.
 */
#[Fillable([
    'bc_id',
    'code',
    'description',
    'starting_date',
    'ending_date',
    'activated',
    'customers',
    'bc_modified_at',
    'bc_payload',
])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /**
     * Written with milliseconds, so bc_modified_at keeps the precision
     * Business Central sent.
     *
     * Without this the query grammar floors every binding to whole seconds.
     * See SyncCheckpoint for the full explanation; the same reasoning applies
     * to any column the incremental filter is compared against.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * The ledger row tracking this campaign's delivery to the website.
     *
     * Joined on the Business Central id rather than the local key, because the
     * ledger is keyed by BC identity, and scoped by entity so a product sharing
     * an id could never be picked up instead.
     *
     * @return HasOne<SyncRecord, $this>
     */
    public function websiteSyncRecord(): HasOne
    {
        return $this->hasOne(SyncRecord::class, 'bc_id', 'bc_id')
            ->where('channel', CampaignSyncLedger::CHANNEL_WEBSITE)
            ->where('entity', CampaignSyncLedger::ENTITY_CAMPAIGN);
    }

    /**
     * The promotion's title on the website.
     *
     * Business Central does not guarantee a description, so this falls back
     * the way the legacy upserter did: description, then code, then the id.
     * A promotion with no title at all would be unidentifiable in the admin.
     */
    public function title(): string
    {
        foreach ([$this->description, $this->code, $this->bc_id] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Whether this campaign is live on the given day.
     *
     * Both ends are inclusive and an absent date means unbounded, matching how
     * the website reads the promotion's own dates.
     */
    public function isActiveOn(CarbonImmutable $day): bool
    {
        if (! $this->activated) {
            return false;
        }

        if ($this->starting_date !== null && $this->starting_date->greaterThan($day)) {
            return false;
        }

        return $this->ending_date === null || ! $this->ending_date->lessThan($day);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starting_date' => 'immutable_date',
            'ending_date' => 'immutable_date',
            'activated' => 'boolean',
            'customers' => 'array',
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
