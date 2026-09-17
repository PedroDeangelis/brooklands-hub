<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\SalesOrder;
use App\Models\SyncRecord;
use App\Sync\Payload\DeliveryPlan;
use App\Sync\Payload\SalesOrderWebsitePayloadBuilder;

/**
 * Records whether a Business Central sales order still needs delivering.
 *
 * A sibling of CustomerSyncLedger, sharing TracksDeliveryState with it. What
 * differs is only how a desired payload is built.
 *
 * Identity is (channel, entity, bc_id). Sales orders share the website
 * channel with every other entity because they share a destination; the
 * entity is what tells them apart.
 *
 * A sales order is never removed. Business Central deletes an order once it
 * is fully invoiced, and the website keeps the last state it was given —
 * "completed" — as the customer's order history. The action is therefore
 * always an upsert.
 */
class SalesOrderSyncLedger
{
    use TracksDeliveryState;

    public const CHANNEL_WEBSITE = 'website';

    public const ENTITY_SALES_ORDER = 'sales_order';

    public function __construct(
        private readonly SalesOrderWebsitePayloadBuilder $payloads,
    ) {}

    /**
     * Mark a sales order as needing delivery.
     *
     * @param  array<int, string>  $changedFields
     */
    public function markPending(
        SalesOrder $salesOrder,
        array $changedFields,
        string $channel = self::CHANNEL_WEBSITE,
    ): SyncRecord {
        $record = SyncRecord::firstOrNew([
            'channel' => $channel,
            'entity' => self::ENTITY_SALES_ORDER,
            'bc_id' => $salesOrder->bc_id,
        ]);

        $payload = $this->payloads->build($salesOrder);

        $record->fill([
            'status' => SyncStatus::Pending,
            'action' => $this->desiredAction($salesOrder),
            'changed_fields' => array_values($changedFields),
            'payload' => $payload,
            'payload_hash' => $this->hash($payload),
            'bc_modified_at' => $salesOrder->bc_modified_at,
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
     * Bring the ledger in line with what the order now needs, if anything.
     *
     * Returns the ledger row when work was opened, and null when the website is
     * already being asked for exactly this. Only the payload is compared,
     * because the action never varies.
     *
     * $force re-opens the row regardless, for a deliberate resync after the
     * website has drifted from what the ledger believes it holds. It is never
     * set by the scheduled path.
     *
     * @param  array<int, string>  $changedFields
     */
    public function reconcile(
        SalesOrder $salesOrder,
        array $changedFields = [],
        string $channel = self::CHANNEL_WEBSITE,
        bool $force = false,
    ): ?SyncRecord {
        $record = $this->find($salesOrder, $channel);
        $hash = $this->hash($this->payloads->build($salesOrder));

        if (! $force && $record !== null && $record->payload_hash === $hash) {
            return null;
        }

        return $this->markPending($salesOrder, $changedFields, $channel);
    }

    /**
     * What the website should be asked to do with this order: always an
     * upsert, for the reason given on the class.
     */
    public function desiredAction(SalesOrder $salesOrder): WebsiteAction
    {
        return WebsiteAction::Upsert;
    }

    public function find(SalesOrder $salesOrder, string $channel = self::CHANNEL_WEBSITE): ?SyncRecord
    {
        return SyncRecord::query()
            ->forChannel($channel)
            ->where('entity', self::ENTITY_SALES_ORDER)
            ->where('bc_id', $salesOrder->bc_id)
            ->first();
    }

    /**
     * What would be sent to the website for this order right now.
     *
     * An order with nothing delivered yet gets the full payload; otherwise
     * only the fields that moved are sent.
     */
    public function plan(SalesOrder $salesOrder, string $channel = self::CHANNEL_WEBSITE): DeliveryPlan
    {
        $record = $this->find($salesOrder, $channel);
        $desired = $this->payloads->build($salesOrder);

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
    public function payloadFor(SalesOrder $salesOrder): array
    {
        return $this->payloads->build($salesOrder);
    }

    public function payloadHash(SalesOrder $salesOrder): string
    {
        return $this->hash($this->payloads->build($salesOrder));
    }
}
