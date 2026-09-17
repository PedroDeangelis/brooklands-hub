<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Models\SyncRecord;
use App\Sync\Payload\CustomerWebsitePayloadBuilder;
use App\Sync\Payload\DeliveryPlan;

/**
 * Records whether a Business Central customer still needs delivering.
 *
 * The customer counterpart to SyncLedger, and deliberately a sibling rather
 * than a generalisation of it. What the two share — how a delivery attempt
 * progresses through pending, syncing, synced, failed and conflicted — lives in
 * TracksDeliveryState and is used by both. What differs is only how a desired
 * payload is built and what "belongs on the website" means, and folding those
 * into one class would mean every read of it carried an entity check.
 *
 * Identity is (channel, entity, bc_id). Customers and products share the
 * website channel because they share a destination; the entity is what tells
 * them apart.
 *
 * A customer is never removed. Laravel mirrors Business Central's current
 * customer state and WordPress decides what to do with it, so the action is
 * always an upsert — a blocked customer is still a fact the website needs, and
 * whether it may log in or order is the website's rule. This is the deliberate difference from
 * SyncLedger, where an ineligible product genuinely must come off the website.
 */
class CustomerSyncLedger
{
    use TracksDeliveryState;

    /**
     * The one destination. Customers and products both go to the website.
     */
    public const CHANNEL_WEBSITE = 'website';

    public const ENTITY_CUSTOMER = 'customer';

    public function __construct(
        private readonly CustomerWebsitePayloadBuilder $payloads,
    ) {}

    /**
     * Mark a customer as needing delivery.
     *
     * @param  array<int, string>  $changedFields
     */
    public function markPending(
        Customer $customer,
        array $changedFields,
        string $channel = self::CHANNEL_WEBSITE,
    ): SyncRecord {
        $record = SyncRecord::firstOrNew([
            'channel' => $channel,
            'entity' => self::ENTITY_CUSTOMER,
            'bc_id' => $customer->bc_id,
        ]);

        $payload = $this->payloads->build($customer);

        $record->fill([
            'status' => SyncStatus::Pending,
            'action' => $this->desiredAction($customer),
            'changed_fields' => array_values($changedFields),
            'payload' => $payload,
            'payload_hash' => $this->hash($payload),
            'bc_modified_at' => $customer->bc_modified_at,
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
     * Bring the ledger in line with what the customer now needs, if anything.
     *
     * Returns the ledger row when work was opened, and null when the website is
     * already being asked for exactly this. Only the payload is compared,
     * because the action never varies — a block shows up as a changed
     * `blocked` field rather than as a different instruction.
     *
     * $force re-opens the row regardless, for a deliberate resync after the
     * website has drifted from what the ledger believes it holds. It is never
     * set by the scheduled path.
     *
     * @param  array<int, string>  $changedFields
     */
    public function reconcile(
        Customer $customer,
        array $changedFields = [],
        string $channel = self::CHANNEL_WEBSITE,
        bool $force = false,
    ): ?SyncRecord {
        $record = $this->find($customer, $channel);
        $hash = $this->hash($this->payloads->build($customer));

        if (! $force && $record !== null && $record->payload_hash === $hash) {
            // The ledger already wants exactly this. The action is not compared
            // because it cannot move: a customer is always upserted.
            return null;
        }

        return $this->markPending($customer, $changedFields, $channel);
    }

    /**
     * What the website should be asked to do with this customer.
     *
     * Always an upsert. Whether the customer may then log in, order, or see
     * prices is WordPress's decision, made from the state this
     * payload carries.
     */
    public function desiredAction(Customer $customer): WebsiteAction
    {
        return WebsiteAction::Upsert;
    }

    public function find(Customer $customer, string $channel = self::CHANNEL_WEBSITE): ?SyncRecord
    {
        return SyncRecord::query()
            ->forChannel($channel)
            ->where('entity', self::ENTITY_CUSTOMER)
            ->where('bc_id', $customer->bc_id)
            ->first();
    }

    /**
     * What would be sent to the website for this customer right now.
     *
     * A removal never diffs, and a customer with nothing delivered yet gets the
     * full payload; otherwise only the fields that moved are sent.
     */
    public function plan(Customer $customer, string $channel = self::CHANNEL_WEBSITE): DeliveryPlan
    {
        $record = $this->find($customer, $channel);
        $desired = $this->payloads->build($customer);

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
    public function payloadFor(Customer $customer): array
    {
        return $this->payloads->build($customer);
    }

    public function payloadHash(Customer $customer): string
    {
        return $this->hash($this->payloads->build($customer));
    }
}
