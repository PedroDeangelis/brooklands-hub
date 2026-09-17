<?php

namespace App\Sync\Payload;

use App\Support\Canonical;
use App\Sync\WebsiteAction;

/**
 * What would be sent to the website for one product right now, and why.
 *
 * Read-only: building a plan sends nothing and records nothing. It exists so the
 * decision can be inspected before any delivery code exists, and so the eventual
 * delivery job has one thing to ask rather than rules of its own.
 */
final readonly class DeliveryPlan
{
    /**
     * @param  array<string, mixed>  $fullPayload  The complete desired payload.
     * @param  array<string, mixed>  $envelope  Exactly what would go over the wire.
     */
    private function __construct(
        public WebsiteAction $action,
        public DeliveryType $type,
        public array $fullPayload,
        public string $payloadHash,
        public PayloadDiff $diff,
        public array $envelope,
    ) {}

    /**
     * Plan the next delivery.
     *
     * A removal never diffs: the product is being taken down, so comparing field
     * values would be meaningless, and the minimal removal payload is what goes.
     *
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>|null  $delivered  Null when nothing has ever been delivered.
     */
    public static function make(
        WebsiteAction $action,
        array $desired,
        ?array $delivered,
        string $payloadHash,
    ): self {
        if ($action === WebsiteAction::Remove) {
            // A removal already delivered has nothing left to do. Without this
            // an excluded product would plan a removal on every pass forever,
            // re-sending an instruction the website has already carried out.
            if ($delivered !== null && Canonical::equals($delivered, $desired)) {
                return new self($action, DeliveryType::None, $desired, $payloadHash, PayloadDiff::none(), []);
            }

            // Never diffed: the product is being taken down, so comparing field
            // values would be meaningless and the minimal payload is what goes.
            return new self(
                $action,
                DeliveryType::Remove,
                $desired,
                $payloadHash,
                PayloadDiff::everything($desired),
                ['action' => $action->value] + $desired,
            );
        }

        // Nothing delivered yet, or a previous delivery of a different shape:
        // the website has no baseline to apply a partial change to.
        if ($delivered === null) {
            return new self(
                $action,
                DeliveryType::Full,
                $desired,
                $payloadHash,
                PayloadDiff::everything($desired),
                [
                    'action' => $action->value,
                    'bc_id' => $desired['bc_id'] ?? null,
                    'changes' => $desired,
                ],
            );
        }

        $diff = PayloadDiff::between($desired, $delivered);

        if ($diff->isEmpty()) {
            return new self($action, DeliveryType::None, $desired, $payloadHash, $diff, []);
        }

        return new self(
            $action,
            DeliveryType::Partial,
            $desired,
            $payloadHash,
            $diff,
            [
                'action' => $action->value,
                'bc_id' => $desired['bc_id'] ?? null,
                'changes' => $diff->changes,
            ],
        );
    }

    public function sendsAnything(): bool
    {
        return $this->type !== DeliveryType::None;
    }
}
