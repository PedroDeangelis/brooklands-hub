<?php

namespace App\Sync;

use App\Models\Product;
use App\Models\SyncRecord;
use App\Sync\Payload\DeliveryType;

/**
 * One product, and what the website is owed for it right now.
 */
final readonly class DeliveryCandidate
{
    public function __construct(
        public Product $product,
        public ?SyncRecord $record,
        public DeliveryType $type,
        /** Why it will not be dispatched, or null when it will be. */
        public ?string $blockedReason,
    ) {}

    /**
     * Whether a delivery job should be dispatched for this product.
     */
    public function isDeliverable(): bool
    {
        return $this->blockedReason === null && $this->type !== DeliveryType::None;
    }

    /**
     * How this candidate is counted in a summary.
     *
     * Blocked records are reported under what is holding them rather than what
     * they would send, because that is the thing worth acting on.
     */
    public function bucket(): string
    {
        if ($this->type === DeliveryType::None) {
            return 'none';
        }

        return $this->blockedReason ?? $this->type->value;
    }

    public function sku(): string
    {
        return (string) $this->product->sku;
    }
}
