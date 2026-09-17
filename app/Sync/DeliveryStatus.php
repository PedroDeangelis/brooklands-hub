<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\WebsiteEligibility;

/**
 * How a product stands with respect to website delivery.
 *
 * A product that does not qualify for the website has nothing to deliver, so it
 * has no sync status in the usual sense. Reporting it as "pending" would put it
 * in a queue it will never leave, and reporting it as "failed" would suggest
 * something went wrong. It is reported as not applicable instead: being
 * excluded from the website is a valid outcome, not an error.
 */
enum DeliveryStatus: string
{
    /** The product qualifies but has not been delivered yet. */
    case Pending = 'pending';

    /** The product has been delivered to the website. */
    case Synced = 'synced';

    /** Delivery was attempted and failed. */
    case Failed = 'failed';

    /** The product does not qualify for the website, so nothing is delivered. */
    case NotApplicable = 'not_applicable';

    /** The product qualifies but has never been considered for delivery. */
    case NotSynced = 'not_synced';

    /**
     * Decide the delivery status of a product from its eligibility and ledger row.
     *
     * Eligibility is checked first: an excluded product is not applicable even
     * if it carries a ledger row from a time when it still qualified.
     */
    public static function for(Product $product, WebsiteEligibility $eligibility, ?SyncRecord $record): self
    {
        if (! $eligibility->isEligible($product)) {
            return self::NotApplicable;
        }

        return self::fromRecord($record);
    }

    public static function fromRecord(?SyncRecord $record): self
    {
        return match ($record?->status) {
            SyncStatus::Pending => self::Pending,
            SyncStatus::Synced => self::Synced,
            SyncStatus::Failed => self::Failed,
            default => self::NotSynced,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
            self::NotApplicable => 'Not applicable',
            self::NotSynced => 'Not synced',
        };
    }

    /**
     * Whether this status represents a problem needing attention.
     *
     * Only a genuine delivery failure does. An excluded product is a business
     * decision, and an undelivered one is simply waiting.
     */
    public function isProblem(): bool
    {
        return $this === self::Failed;
    }

    public function explain(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to be delivered to the website.',
            self::Synced => 'Delivered to the website.',
            self::Failed => 'Delivery to the website failed.',
            self::NotApplicable => 'Excluded from the website, so there is nothing to deliver.',
            self::NotSynced => 'Qualifies for the website but has not been queued for delivery.',
        };
    }
}
