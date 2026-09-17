<?php

namespace App\Sync\Payload;

use App\DocumentAttachments\AttachmentParentType;
use App\Models\SalesOrder;
use App\SalesOrders\CustomerNote;
use Carbon\CarbonImmutable;

/**
 * Builds the payload the website should be given for a sales order.
 *
 * A translation step and nothing more. Every decision the legacy website
 * upserter made for itself — the post title, the website status, the one-line
 * shipping address, the customer note lifted out of the work description —
 * arrives here already made, so the website only stores what it is told.
 *
 * Field names are the order post's own ACF names where one exists (items,
 * sales_shipments, sales_invoices, po_number, customer_note, shipping_address,
 * last_updated), so the website never has to translate.
 *
 * Deterministic — the same order always produces the same keys in the same
 * shape — so payloads can be hashed and diffed. The three document lists are
 * sent whole and compared whole; PayloadDiff lists them as atomic.
 *
 * The two timestamps are sent in the website's timezone, formatted as its
 * text fields hold them. They are the website's final values, which is what
 * a v2 payload carries; the website has never been asked to convert a clock.
 */
class SalesOrderWebsitePayloadBuilder
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @return array<string, mixed>
     */
    public function build(SalesOrder $salesOrder): array
    {
        return [
            'bc_id' => (string) $salesOrder->bc_id,
            'number' => (string) $salesOrder->number,
            'title' => $salesOrder->title(),
            'customer_bc_id' => (string) $salesOrder->customer_bc_id,
            'customer_name' => (string) $salesOrder->customer_name,
            'status' => $salesOrder->websiteStatus()->value,
            'bc_status' => (string) $salesOrder->bc_status,
            'order_date' => $salesOrder->order_date?->toDateString() ?? '',
            'created_at' => $this->localTime($salesOrder->bc_created_at),
            'last_updated' => $this->localTime($salesOrder->bc_modified_at),
            'po_number' => (string) $salesOrder->external_document_number,
            'shipping_address' => $salesOrder->shipToAddress(),
            'customer_note' => CustomerNote::fromWorkDescription((string) $salesOrder->work_description),
            'total_amount_excluding_tax' => (float) $salesOrder->total_amount_excluding_tax,
            'total_tax_amount' => (float) $salesOrder->total_tax_amount,
            'total_amount_including_tax' => (float) $salesOrder->total_amount_including_tax,
            'items' => array_map(fn (array $line): array => $this->item($line), $salesOrder->lines()),
            'sales_shipments' => array_map(fn (array $shipment): array => $this->shipment($shipment), $salesOrder->shipments()),
            'sales_invoices' => array_map(fn (array $invoice): array => $this->invoice($invoice), $salesOrder->invoices()),
            // Replaced whole, never merged: the sweep that imports attachments
            // is the only thing that knows a file has been deleted, so a
            // partial list here would leave removed files on the website.
            'attachments' => AttachmentsPayload::for(AttachmentParentType::SalesOrder, (string) $salesOrder->bc_id),
        ];
    }

    /**
     * One row of the website's items repeater.
     *
     * The line's own id, the item's id and the item's number are all sent:
     * the website resolves the product post from whichever it indexes.
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

    /**
     * @param  array<string, mixed>  $shipment
     * @return array<string, string>
     */
    private function shipment(array $shipment): array
    {
        return [
            'bc_id' => (string) ($shipment['bc_id'] ?? ''),
            'number' => (string) ($shipment['number'] ?? ''),
            'external_document_number' => (string) ($shipment['external_document_number'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, string>
     */
    private function invoice(array $invoice): array
    {
        return [
            'bc_id' => (string) ($invoice['bc_id'] ?? ''),
            'number' => (string) ($invoice['number'] ?? ''),
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
