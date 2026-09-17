<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\WebsiteEligibility;
use App\Sync\Payload\DeliveryPlan;
use App\Sync\Payload\ProductWebsitePayloadBuilder;

/**
 * Records whether a Business Central record still needs delivering to a channel.
 *
 * The ledger tracks delivery intent only; it never talks to a destination.
 *
 * Intent is a pair: the action the website should be asked to perform, and the
 * payload it should be given. Either can move on its own — a product losing its
 * eligibility changes the action while every Business Central field stays
 * put — so both are compared against what was last delivered before any work is
 * opened. That is what stops an unchanged product being re-queued forever while
 * still catching a product that has only changed its mind about existing.
 */
class SyncLedger
{
    use TracksDeliveryState;

    /**
     * The product delivery channel. Other channels arrive with their own entities.
     */
    public const CHANNEL_ITEMS = 'items';

    /**
     * Which kind of Business Central record this ledger tracks.
     *
     * Identity is (channel, entity, bc_id): the channel is where a record is
     * going, the entity is what it is. Written explicitly rather than left to
     * the column default, so a row says what it is without the schema having to
     * be consulted.
     */
    public const ENTITY_PRODUCT = 'product';

    public function __construct(
        private readonly WebsiteEligibility $eligibility,
        private readonly ProductWebsitePayloadBuilder $payloads,
    ) {}

    /**
     * Mark a product as needing delivery.
     *
     * @param  array<int, string>  $changedFields
     */
    public function markPending(Product $product, array $changedFields, string $channel = self::CHANNEL_ITEMS): SyncRecord
    {
        $record = SyncRecord::firstOrNew([
            'channel' => $channel,
            'entity' => self::ENTITY_PRODUCT,
            'bc_id' => $product->bc_id,
        ]);

        $payload = $this->payloads->build($product);

        $record->fill([
            'status' => SyncStatus::Pending,
            'action' => $this->desiredAction($product),
            'changed_fields' => array_values($changedFields),
            'payload' => $payload,
            'payload_hash' => $this->hash($payload),
            'bc_modified_at' => $product->bc_modified_at,
            'dispatched_at' => now(),
            'started_at' => null,
            // A new desired state starts its own attempt count: failures against
            // the previous payload say nothing about this one.
            'attempts' => 0,
            'last_error' => null,
            // A new desired state is a new question. Whatever collided before
            // was about the payload that is now superseded, and leaving it would
            // explain a problem that may no longer exist.
            'conflict_details' => null,
            'conflicted_at' => null,
        ]);

        $record->save();

        return $record;
    }

    /**
     * Bring the ledger in line with what the product now needs, if anything.
     *
     * Returns the ledger row when work was opened, and null when the website is
     * already being asked for exactly this. Both halves of the intent are
     * checked, so a product that becomes excluded is queued for removal even
     * though its Business Central fields are untouched, while a product that
     * has genuinely not moved is left alone however often it is re-imported.
     *
     * $force re-opens the row regardless, for a deliberate resync after the
     * website has drifted from what the ledger believes it holds — a restored
     * database, a hand-deleted post, a receiver that stopped applying. It is
     * never set by the scheduled path.
     *
     * @param  array<int, string>  $changedFields
     */
    public function reconcile(
        Product $product,
        array $changedFields = [],
        string $channel = self::CHANNEL_ITEMS,
        bool $force = false,
    ): ?SyncRecord {
        $record = $this->find($product, $channel);
        $action = $this->desiredAction($product);
        $hash = $this->hash($this->payloads->build($product));

        if (! $force && $record !== null && $record->action === $action && $record->payload_hash === $hash) {
            // The ledger already wants exactly this. Whether it has been
            // delivered yet is the queue's business, not ours: re-opening the
            // row here would reset a failure count or overwrite an in-flight
            // attempt with identical intent.
            return null;
        }

        // A removal carries no payload changes worth listing: the fields did not
        // move, the product's right to be on the website did.
        if ($action === WebsiteAction::Remove && $record?->action === WebsiteAction::Upsert) {
            $changedFields = ['website_eligibility'];
        }

        return $this->markPending($product, $changedFields, $channel);
    }

    /**
     * What the website should be asked to do with this product.
     */
    public function desiredAction(Product $product): WebsiteAction
    {
        return $this->desiredState($product)->action;
    }

    /**
     * The desired website state for a product, with the reasons behind it.
     */
    public function desiredState(Product $product): DesiredWebsiteState
    {
        return DesiredWebsiteState::from($this->eligibility->for($product));
    }

    /**
     * The ledger row for a product, if one exists.
     */
    public function find(Product $product, string $channel = self::CHANNEL_ITEMS): ?SyncRecord
    {
        return SyncRecord::query()
            ->forChannel($channel)
            ->where('entity', self::ENTITY_PRODUCT)
            ->where('bc_id', $product->bc_id)
            ->first();
    }

    /**
     * What would be sent to the website for this product right now.
     *
     * A removal never diffs, and a product with nothing delivered yet gets the
     * full payload; otherwise only the website fields that moved are sent.
     */
    public function plan(Product $product, string $channel = self::CHANNEL_ITEMS): DeliveryPlan
    {
        $record = $this->find($product, $channel);
        $payload = $this->payloads->build($product);

        // Only a payload delivered under the same action is a usable baseline.
        // A product coming back from removal has nothing on the website to
        // apply a partial change to, so it starts again from the full payload.
        $delivered = $record?->delivered_action === $this->desiredAction($product)
            ? $record?->delivered_payload
            : null;

        return DeliveryPlan::make(
            $this->desiredAction($product),
            $payload,
            $delivered,
            $this->hash($payload),
        );
    }

    /**
     * The complete website payload currently desired for a product.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(Product $product): array
    {
        return $this->payloads->build($product);
    }

    /**
     * Hash of the payload a delivery would currently send for a product.
     */
    public function payloadHash(Product $product): string
    {
        return $this->hash($this->payloads->build($product));
    }
}
