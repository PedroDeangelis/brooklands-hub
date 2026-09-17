<?php

namespace App\Sync;

use App\Contacts\ContactEligibility;
use App\Enums\SyncStatus;
use App\Models\Contact;
use App\Models\SyncRecord;
use App\Sync\Payload\ContactWebsitePayloadBuilder;
use App\Sync\Payload\DeliveryPlan;

/**
 * Records whether a Business Central contact still needs delivering.
 *
 * A sibling of the other ledgers, sharing TracksDeliveryState with them. What
 * is different here is the gate: a contact is only ever opened for delivery
 * while it qualifies (ContactEligibility), because delivering one creates a
 * website user and there is no undoing that quietly.
 *
 * A contact is never removed. Deleting a WordPress user destroys its order
 * history, so an ineligible contact is simply never created, and a user that
 * was created before its contact stopped qualifying is left alone. The
 * dashboard shows that as "Excluded" beside "On site: Present" — a visible
 * contradiction for a person to act on, not a silent deletion. This is the one
 * deliberate divergence from products.
 */
class ContactSyncLedger
{
    use TracksDeliveryState;

    public const CHANNEL_WEBSITE = 'website';

    public const ENTITY_CONTACT = 'contact';

    public function __construct(
        private readonly ContactWebsitePayloadBuilder $payloads,
        private readonly ContactEligibility $eligibility,
    ) {}

    /**
     * Mark a contact as needing delivery.
     *
     * Unconditional: the caller has decided. reconcile() is the path that
     * applies the eligibility gate.
     *
     * @param  array<int, string>  $changedFields
     */
    public function markPending(
        Contact $contact,
        array $changedFields,
        string $channel = self::CHANNEL_WEBSITE,
    ): SyncRecord {
        $record = SyncRecord::firstOrNew([
            'channel' => $channel,
            'entity' => self::ENTITY_CONTACT,
            'bc_id' => $contact->bc_id,
        ]);

        $payload = $this->payloads->build($contact);

        $record->fill([
            'status' => SyncStatus::Pending,
            'action' => WebsiteAction::Upsert,
            'changed_fields' => array_values($changedFields),
            'payload' => $payload,
            'payload_hash' => $this->hash($payload),
            'bc_modified_at' => $contact->bc_modified_at,
            'dispatched_at' => now(),
            'started_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'conflict_details' => null,
            'conflicted_at' => null,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Bring the ledger in line with what the contact now needs, if anything.
     *
     * Returns the ledger row when work was opened, and null when there is
     * nothing to open: the contact does not qualify, or the website is
     * already being asked for exactly this.
     *
     * An ineligible contact opens nothing even under $force. Force exists to
     * re-send what the website should hold; it must never create a user the
     * rules say should not exist.
     *
     * @param  array<int, string>  $changedFields
     */
    public function reconcile(
        Contact $contact,
        array $changedFields = [],
        string $channel = self::CHANNEL_WEBSITE,
        bool $force = false,
    ): ?SyncRecord {
        if (! $this->eligibility->isEligible($contact)) {
            return null;
        }

        $record = $this->find($contact, $channel);
        $hash = $this->hash($this->payloads->build($contact));

        if (! $force && $record !== null && $record->payload_hash === $hash) {
            return null;
        }

        return $this->markPending($contact, $changedFields, $channel);
    }

    /**
     * What the website should be asked to do with this contact: always an
     * upsert, for the reason given on the class.
     */
    public function desiredAction(Contact $contact): WebsiteAction
    {
        return WebsiteAction::Upsert;
    }

    public function find(Contact $contact, string $channel = self::CHANNEL_WEBSITE): ?SyncRecord
    {
        return SyncRecord::query()
            ->forChannel($channel)
            ->where('entity', self::ENTITY_CONTACT)
            ->where('bc_id', $contact->bc_id)
            ->first();
    }

    /**
     * What would be sent to the website for this contact right now.
     *
     * The plan does not consult eligibility: it answers "what would go" so the
     * dashboard can show it for any contact. Whether it should go is the
     * ledger's and the job's decision.
     */
    public function plan(Contact $contact, string $channel = self::CHANNEL_WEBSITE): DeliveryPlan
    {
        $record = $this->find($contact, $channel);
        $desired = $this->payloads->build($contact);

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
    public function payloadFor(Contact $contact): array
    {
        return $this->payloads->build($contact);
    }

    public function payloadHash(Contact $contact): string
    {
        return $this->hash($this->payloads->build($contact));
    }

    public function isEligible(Contact $contact): bool
    {
        return $this->eligibility->isEligible($contact);
    }
}
