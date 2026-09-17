<?php

namespace App\Models;

use App\Sync\ContactSyncLedger;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The latest known Business Central state for a single person contact.
 *
 * Business Central remains the source of truth; this table is a local mirror
 * used for change detection and website delivery. A contact becomes a
 * WordPress user, and only when it qualifies (see ContactEligibility): unlike
 * customers and campaigns, most contacts are never delivered at all.
 *
 * The customer link is held here as customer_bc_id, written by the
 * customerContacts sweep rather than the contact import, because Business
 * Central keeps the two on different pages.
 */
#[Fillable([
    'bc_id',
    'number',
    'display_name',
    'type',
    'company_number',
    'company_name',
    'organisational_level_code',
    'contact_business_relation',
    'address_1',
    'address_2',
    'city',
    'state',
    'postal_code',
    'country',
    'phone',
    'mobile',
    'email',
    'privacy_blocked',
    'customer_bc_id',
    'bc_modified_at',
    'bc_payload',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * Written with milliseconds, so bc_modified_at keeps the precision
     * Business Central sent. See SyncCheckpoint for why a cast cannot do this.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * The customer this contact belongs to, when the link has arrived and the
     * customer has been imported.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_bc_id', 'bc_id');
    }

    /**
     * The ledger row tracking this contact's delivery to the website.
     *
     * @return HasOne<SyncRecord, $this>
     */
    public function websiteSyncRecord(): HasOne
    {
        return $this->hasOne(SyncRecord::class, 'bc_id', 'bc_id')
            ->where('channel', ContactSyncLedger::CHANNEL_WEBSITE)
            ->where('entity', ContactSyncLedger::ENTITY_CONTACT);
    }

    /**
     * The contact's name for display, never empty.
     */
    public function title(): string
    {
        foreach ([$this->display_name, $this->number, $this->bc_id] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    public function hasEmail(): bool
    {
        return trim((string) $this->email) !== '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'privacy_blocked' => 'boolean',
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
