<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\SalesInvoice;
use App\Models\SyncRecord;
use App\Sync\Payload\DeliveryPlan;
use App\Sync\Payload\SalesInvoiceWebsitePayloadBuilder;

/**
 * Records whether a Business Central posted sales invoice or credit memo
 * still needs delivering.
 *
 * A sibling of SalesOrderSyncLedger, sharing TracksDeliveryState with it.
 * What differs is only how a desired payload is built.
 *
 * A document is never removed: Business Central never deletes a posted
 * invoice or credit memo, and the website keeps every one as the customer's
 * history. The action is therefore always an upsert.
 */
class SalesInvoiceSyncLedger
{
    use TracksDeliveryState;

    public const CHANNEL_WEBSITE = 'website';

    public const ENTITY_SALES_INVOICE = 'sales_invoice';

    public function __construct(
        private readonly SalesInvoiceWebsitePayloadBuilder $payloads,
    ) {}

    /**
     * Mark an invoice as needing delivery.
     *
     * @param  array<int, string>  $changedFields
     */
    public function markPending(
        SalesInvoice $salesInvoice,
        array $changedFields,
        string $channel = self::CHANNEL_WEBSITE,
    ): SyncRecord {
        $record = SyncRecord::firstOrNew([
            'channel' => $channel,
            'entity' => self::ENTITY_SALES_INVOICE,
            'bc_id' => $salesInvoice->bc_id,
        ]);

        $payload = $this->payloads->build($salesInvoice);

        $record->fill([
            'status' => SyncStatus::Pending,
            'action' => $this->desiredAction($salesInvoice),
            'changed_fields' => array_values($changedFields),
            'payload' => $payload,
            'payload_hash' => $this->hash($payload),
            'bc_modified_at' => $salesInvoice->bc_modified_at,
            'dispatched_at' => now(),
            'started_at' => null,
            // A new desired state starts its own attempt count: failures against
            // the previous payload say nothing about this one.
            'attempts' => 0,
            'last_error' => null,
            'conflict_details' => null,
            'conflicted_at' => null,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Bring the ledger in line with what the invoice now needs, if anything.
     *
     * Returns the ledger row when work was opened, and null when the website is
     * already being asked for exactly this. $force re-opens the row regardless,
     * for a deliberate resync; it is never set by the scheduled path.
     *
     * @param  array<int, string>  $changedFields
     */
    public function reconcile(
        SalesInvoice $salesInvoice,
        array $changedFields = [],
        string $channel = self::CHANNEL_WEBSITE,
        bool $force = false,
    ): ?SyncRecord {
        $record = $this->find($salesInvoice, $channel);
        $hash = $this->hash($this->payloads->build($salesInvoice));

        if (! $force && $record !== null && $record->payload_hash === $hash) {
            return null;
        }

        return $this->markPending($salesInvoice, $changedFields, $channel);
    }

    /**
     * What the website should be asked to do with this invoice: always an
     * upsert, for the reason given on the class.
     */
    public function desiredAction(SalesInvoice $salesInvoice): WebsiteAction
    {
        return WebsiteAction::Upsert;
    }

    public function find(SalesInvoice $salesInvoice, string $channel = self::CHANNEL_WEBSITE): ?SyncRecord
    {
        return SyncRecord::query()
            ->forChannel($channel)
            ->where('entity', self::ENTITY_SALES_INVOICE)
            ->where('bc_id', $salesInvoice->bc_id)
            ->first();
    }

    /**
     * What would be sent to the website for this invoice right now.
     */
    public function plan(SalesInvoice $salesInvoice, string $channel = self::CHANNEL_WEBSITE): DeliveryPlan
    {
        $record = $this->find($salesInvoice, $channel);
        $desired = $this->payloads->build($salesInvoice);

        return DeliveryPlan::make(
            WebsiteAction::Upsert,
            $desired,
            $record?->delivered_payload,
            $this->hash($desired),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(SalesInvoice $salesInvoice): array
    {
        return $this->payloads->build($salesInvoice);
    }

    public function payloadHash(SalesInvoice $salesInvoice): string
    {
        return $this->hash($this->payloads->build($salesInvoice));
    }
}
