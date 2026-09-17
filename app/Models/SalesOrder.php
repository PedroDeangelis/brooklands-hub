<?php

namespace App\Models;

use App\SalesOrders\SalesOrderStatus;
use App\Sync\SalesOrderSyncLedger;
use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The latest known Business Central state for a single sales order.
 *
 * Business Central remains the source of truth; this table is a local mirror
 * used for change detection and website delivery. Lines, shipments and
 * invoices live on the order as normalised lists rather than in their own
 * tables, because the website stores them the same way — as repeaters on the
 * order post — and delivering them any other way would mean the website had
 * to join.
 *
 * Only open orders exist in Business Central: a fully invoiced order is
 * deleted there and simply stops arriving here. The mirror keeps its last
 * state, which is what the website shows too.
 */
#[Fillable([
    'bc_id',
    'number',
    'customer_bc_id',
    'customer_name',
    'order_date',
    'bc_status',
    'website_status',
    'external_document_number',
    'total_amount_excluding_tax',
    'total_tax_amount',
    'total_amount_including_tax',
    'fully_shipped',
    'ship_to_address_1',
    'ship_to_address_2',
    'ship_to_city',
    'ship_to_state',
    'ship_to_post_code',
    'work_description',
    'lines',
    'shipments',
    'invoices',
    'bc_created_at',
    'bc_modified_at',
    'bc_payload',
])]
class SalesOrder extends Model
{
    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory;

    /**
     * Written with milliseconds, so bc_modified_at keeps the precision
     * Business Central sent. See SyncCheckpoint for why a cast cannot do this.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * The customer this order belongs to, when it has been imported.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_bc_id', 'bc_id');
    }

    /**
     * The ledger row tracking this order's delivery to the website.
     *
     * @return HasOne<SyncRecord, $this>
     */
    public function websiteSyncRecord(): HasOne
    {
        return $this->hasOne(SyncRecord::class, 'bc_id', 'bc_id')
            ->where('channel', SalesOrderSyncLedger::CHANNEL_WEBSITE)
            ->where('entity', SalesOrderSyncLedger::ENTITY_SALES_ORDER);
    }

    /**
     * The order's title on the website, never empty.
     *
     * The legacy upserter's wording, kept so existing order posts do not
     * change title when the sync moves here.
     */
    public function title(): string
    {
        $number = trim((string) $this->number);
        $customer = trim((string) $this->customer_name);

        return sprintf(
            'Sales Order %s for %s',
            $number !== '' ? $number : (string) $this->bc_id,
            $customer !== '' ? $customer : 'Unknown Customer',
        );
    }

    public function websiteStatus(): SalesOrderStatus
    {
        return SalesOrderStatus::tryFrom((string) $this->website_status) ?? SalesOrderStatus::Processing;
    }

    /**
     * The ship-to address as one line, the way the website's text field holds it.
     */
    public function shipToAddress(): string
    {
        $parts = array_map(
            static fn (mixed $value): string => trim((string) $value),
            [$this->ship_to_city, $this->ship_to_state, $this->ship_to_post_code],
        );

        $locality = implode(' ', array_filter($parts, static fn (string $value): bool => $value !== ''));

        $lines = array_map(
            static fn (mixed $value): string => trim((string) $value),
            [$this->ship_to_address_1, $this->ship_to_address_2, $locality],
        );

        return implode(', ', array_filter($lines, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        return is_array($this->lines) ? array_values($this->lines) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shipments(): array
    {
        return is_array($this->shipments) ? array_values($this->shipments) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoices(): array
    {
        return is_array($this->invoices) ? array_values($this->invoices) : [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'immutable_date',
            'total_amount_excluding_tax' => 'decimal:5',
            'total_tax_amount' => 'decimal:5',
            'total_amount_including_tax' => 'decimal:5',
            'fully_shipped' => 'boolean',
            'lines' => 'array',
            'shipments' => 'array',
            'invoices' => 'array',
            'bc_created_at' => 'immutable_datetime',
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
