<?php

namespace App\Sync\Payload;

use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\SalesOrders\CustomerNote;
use Carbon\CarbonImmutable;

/**
 * Builds the payload the website should be given for a posted sales invoice
 * or credit memo.
 *
 * A translation step and nothing more, in the shape of the order payload:
 * the invoice post shares the order post's field group, so the same names
 * (items, po_number, customer_note, shipping_address, last_updated) apply.
 *
 * The status is the document's kind — "invoice" or "credit_memo", the two
 * choices the website's status field has for this post type — and it is the
 * only field the website routes on to tell the two apart. document_api_id is
 * sent for both kinds, empty for an invoice: the website builds a credit
 * memo's PDF link from it. The order it was posted from is sent
 * two ways: as the order's number, and as that order's Business Central id
 * when the order is still known here. Business Central deletes an order once
 * it is fully invoiced, so the id can legitimately be empty for an invoice
 * that arrives after its order has gone; the number never is.
 */
class SalesInvoiceWebsitePayloadBuilder
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @return array<string, mixed>
     */
    public function build(SalesInvoice $salesInvoice): array
    {
        return [
            'bc_id' => (string) $salesInvoice->bc_id,
            'number' => (string) $salesInvoice->number,
            'document_api_id' => (string) $salesInvoice->document_api_id,
            'title' => $salesInvoice->title(),
            'customer_bc_id' => (string) $salesInvoice->customer_bc_id,
            'customer_name' => (string) $salesInvoice->customer_name,
            'status' => $salesInvoice->kind()->websiteStatus(),
            'order_number' => (string) $salesInvoice->order_number,
            'related_order_bc_id' => $this->relatedOrderBcId($salesInvoice),
            'invoice_date' => $salesInvoice->invoice_date?->toDateString() ?? '',
            'order_date' => $salesInvoice->order_date?->toDateString() ?? '',
            'created_at' => $this->localTime($salesInvoice->bc_created_at),
            'last_updated' => $this->localTime($salesInvoice->bc_modified_at),
            'po_number' => (string) $salesInvoice->external_document_number,
            'shipping_address' => $salesInvoice->shipToAddress(),
            'customer_note' => CustomerNote::fromWorkDescription((string) $salesInvoice->work_description),
            'total_amount_excluding_tax' => (float) $salesInvoice->total_amount_excluding_tax,
            'total_tax_amount' => (float) $salesInvoice->total_tax_amount,
            'total_amount_including_tax' => (float) $salesInvoice->total_amount_including_tax,
            'items' => array_map(fn (array $line): array => $this->item($line), $salesInvoice->lines()),
        ];
    }

    /**
     * The Business Central id of the order this invoice was posted from, when
     * that order is still mirrored here.
     */
    private function relatedOrderBcId(SalesInvoice $salesInvoice): string
    {
        $number = trim((string) $salesInvoice->order_number);

        if ($number === '') {
            return '';
        }

        return (string) (SalesOrder::query()->where('number', $number)->value('bc_id') ?? '');
    }

    /**
     * One row of the website's items repeater. Same shape as an order's, so
     * the website applies both with one writer.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function item(array $line): array
    {
        return [
            'bc_id' => (string) ($line['bc_id'] ?? ''),
            'item_bc_id' => (string) ($line['item_bc_id'] ?? ''),
            'item_number' => (string) ($line['item_number'] ?? ''),
            'name' => (string) ($line['description'] ?? ''),
            'quantity' => $this->number($line['quantity'] ?? null),
            'price' => $this->number($line['unit_price'] ?? null),
            'quantity_to_ship' => $this->number($line['quantity_to_ship'] ?? null),
            'quantity_shipped' => $this->number($line['quantity_shipped'] ?? null),
            'quantity_invoiced' => $this->number($line['quantity_invoiced'] ?? null),
        ];
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function localTime(?CarbonImmutable $moment): string
    {
        if ($moment === null) {
            return '';
        }

        return $moment->setTimezone((string) config('sync.delivery.timezone'))->format(self::DATETIME_FORMAT);
    }
}
