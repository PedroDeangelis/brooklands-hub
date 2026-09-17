<?php

namespace App\Website;

use App\Sync\Payload\DeliveryPlan;
use App\Sync\Payload\DeliveryType;
use App\Sync\WebsiteAction;
use InvalidArgumentException;

/**
 * The body of one delivery to the website.
 *
 * The contract is deliberately small and explicit: an action, a mode, the
 * record's identity, and exactly one payload key whose name says how to read it
 * ("payload" for a complete state, "changes" for a partial one). The receiver
 * never has to guess which it was given, and never re-derives anything.
 */
final readonly class WebsiteRequest
{
    public const MODE_FULL = 'full';

    public const MODE_PARTIAL = 'partial';

    public const ENTITY_PRODUCT = 'product';

    public const ENTITY_CAMPAIGN = 'campaign';

    public const ENTITY_CUSTOMER = 'customer';

    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_SALES_ORDER = 'sales_order';

    public const ENTITY_SALES_INVOICE = 'sales_invoice';

    /**
     * @param  array<string, mixed>  $body
     */
    private function __construct(
        public WebsiteAction $action,
        public string $bcId,
        public array $body,
    ) {}

    /**
     * Build the request a plan calls for.
     *
     * A plan with nothing to send has no request: asking for one is a caller
     * mistake rather than an empty delivery.
     */
    public static function fromPlan(DeliveryPlan $plan, string $entity = self::ENTITY_PRODUCT): self
    {
        $bcId = (string) ($plan->fullPayload['bc_id'] ?? '');

        if ($bcId === '') {
            throw new InvalidArgumentException('Delivery payload is missing a bc_id.');
        }

        // The entity leads the body so the receiver can route on it before
        // reading anything else. Without it every delivery looks like a
        // product, and a campaign would be applied by the product applier.
        return match ($plan->type) {
            DeliveryType::Remove => new self($plan->action, $bcId, [
                'entity' => $entity,
                'action' => WebsiteAction::Remove->value,
                'bc_id' => $bcId,
                'reasons' => $plan->fullPayload['reasons'] ?? [],
            ]),
            DeliveryType::Full => new self($plan->action, $bcId, [
                'entity' => $entity,
                'action' => WebsiteAction::Upsert->value,
                'mode' => self::MODE_FULL,
                'bc_id' => $bcId,
                'payload' => $plan->fullPayload,
            ]),
            DeliveryType::Partial => new self($plan->action, $bcId, [
                'entity' => $entity,
                'action' => WebsiteAction::Upsert->value,
                'mode' => self::MODE_PARTIAL,
                'bc_id' => $bcId,
                'changes' => $plan->diff->changes,
            ]),
            DeliveryType::None => throw new InvalidArgumentException(
                'A plan with nothing to send cannot be turned into a request.',
            ),
        };
    }

    /**
     * Which kind of record this delivery carries.
     */
    public function entity(): string
    {
        return (string) ($this->body['entity'] ?? self::ENTITY_PRODUCT);
    }

    public function isRemoval(): bool
    {
        return $this->action === WebsiteAction::Remove;
    }

    public function mode(): string
    {
        return (string) ($this->body['mode'] ?? WebsiteAction::Remove->value);
    }
}
