<?php

namespace App\Models;

use App\SalesInvoices\SalesInvoiceKind;
use App\Sync\SalesInvoiceSyncLedger;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The latest known Business Central state for a single posted sales invoice
 * or credit memo.
 *
 * Business Central remains the source of truth; this table is a local mirror
 * used for change detection and website delivery. A posted document is
 * immutable and never deleted, so a row here changes only if the sync itself
 * changes what it stores.
 *
 * Both kinds share the table because the website stores both as one post
 * type; `kind` tells them apart (see SalesInvoiceKind). For a credit memo,
 * invoice_date holds the credit memo date, order_number the return order it
 * was posted from, and document_api_id the GUID the standard API knows it by.
 *
 * An invoice is tied to its order by order_number. The order may already be
 * gone from Business Central — it is deleted once fully invoiced — so the link
 * is carried as a number rather than as a foreign key.
 */
#[Fillable([
    'bc_id',
    'number',
    'kind',
    'document_api_id',
    'order_number',
    'customer_bc_id',
    'customer_name',
    'invoice_date',
    'order_date',
    'external_document_number',
    'total_amount_excluding_tax',
    'total_tax_amount',
    'total_amount_including_tax',
    'ship_to_address_1',
    'ship_to_address_2',
    'ship_to_city',
    'ship_to_state',
    'ship_to_post_code',
    'work_description',
    'lines',
    'bc_created_at',
    'bc_modified_at',
    'bc_payload',
])]
class SalesInvoice extends Model
{
    /** @use HasFactory<SalesInvoiceFactory> */
    use HasFactory;

    /**
     * Written with milliseconds, so bc_modified_at keeps the precision
     * Business Central sent. See SyncCheckpoint for why a cast cannot do this.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * The customer this invoice belongs to, when it has been imported.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_bc_id', 'bc_id');
    }

    /**
     * The order this invoice was posted from, while it still exists here.
     *
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_number', 'number');
    }

    /**
     * The ledger row tracking this invoice's delivery to the website.
     *
     * @return HasOne<SyncRecord, $this>
     */
    public function websiteSyncRecord(): HasOne
    {
        return $this->hasOne(SyncRecord::class, 'bc_id', 'bc_id')
            ->where('channel', SalesInvoiceSyncLedger::CHANNEL_WEBSITE)
            ->where('entity', SalesInvoiceSyncLedger::ENTITY_SALES_INVOICE);
    }

    public function kind(): SalesInvoiceKind
    {
        return SalesInvoiceKind::tryFrom((string) $this->kind) ?? SalesInvoiceKind::Invoice;
    }

    public function isCreditMemo(): bool
    {
        return $this->kind() === SalesInvoiceKind::CreditMemo;
    }

    /**
     * The document's title on the website, never empty.
     *
     * The legacy upserters' wording, kept so existing posts do not change
     * title when the sync moves here.
     */
    public function title(): string
    {
        $number = trim((string) $this->number);
        $customer = trim((string) $this->customer_name);

        return sprintf(
            '%s %s for %s',
            $this->kind()->titlePrefix(),
            $number !== '' ? $number : (string) $this->bc_id,
            $customer !== '' ? $customer : 'Unknown Customer',
        );
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'order_date' => 'immutable_date',
            'total_amount_excluding_tax' => 'decimal:5',
            'total_tax_amount' => 'decimal:5',
            'total_amount_including_tax' => 'decimal:5',
            'lines' => 'array',
            'bc_created_at' => 'immutable_datetime',
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
