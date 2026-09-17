<?php

namespace App\Models;

use App\Sync\CustomerSyncLedger;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The latest known Business Central state for a single customer.
 *
 * Business Central remains the source of truth; this table is a local mirror
 * used for change detection and website delivery. Ship-to addresses live on
 * the customer as a normalised list rather than in their own table, because
 * the website stores them the same way — as a repeater on the customer post —
 * and delivering them any other way would mean the website had to join.
 */
#[Fillable([
    'bc_id',
    'number',
    'display_name',
    'type',
    'address_1',
    'address_2',
    'city',
    'state',
    'postal_code',
    'country',
    'phone',
    'email',
    'shipment_method_code',
    'shipping_location_code',
    'blocked',
    'customer_price_group',
    'customer_disc_group',
    'salesperson_code',
    'shipping_addresses',
    'bc_modified_at',
    'bc_payload',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * Written with milliseconds, so bc_modified_at keeps the precision
     * Business Central sent. See SyncCheckpoint for why a cast cannot do this.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * The ledger row tracking this customer's delivery to the website.
     *
     * @return HasOne<SyncRecord, $this>
     */
    public function websiteSyncRecord(): HasOne
    {
        return $this->hasOne(SyncRecord::class, 'bc_id', 'bc_id')
            ->where('channel', CustomerSyncLedger::CHANNEL_WEBSITE)
            ->where('entity', CustomerSyncLedger::ENTITY_CUSTOMER);
    }

    /**
     * The customer's name on the website, never empty.
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

    /**
     * Whether Business Central has blocked this customer in any way.
     */
    public function isBlocked(): bool
    {
        return $this->blocked !== 'none';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function shippingAddresses(): array
    {
        return is_array($this->shipping_addresses) ? $this->shipping_addresses : [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_addresses' => 'array',
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
