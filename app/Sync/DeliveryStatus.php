<?php

namespace App\Sync;

use App\Contacts\ContactEligibility;
use App\Enums\SyncStatus;
use App\Models\Contact;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\WebsiteEligibility;

/**
 * Whether the website already matches what we want it to hold.
 *
 * This is deliberately separate from the desired website state. The desired
 * state says what the website should end up with; this says how far away it
 * currently is. A product can want removal and still be waiting for it.
 *
 * "Not applicable" therefore means something narrow: there is nothing to do
 * because the website has never held this product and should not hold it. It
 * must never be used for a product that was delivered while it qualified and
 * has since stopped qualifying, because that product still needs taking down.
 */
enum DeliveryStatus: string
{
    /** The desired state has not reached the website yet. */
    case Pending = 'pending';

    /** A delivery job is working on it right now. */
    case Syncing = 'syncing';

    /** The website matches the desired state. */
    case Synced = 'synced';

    /** Delivery was attempted and failed. */
    case Failed = 'failed';

    /**
     * The website cannot take this product as addressed.
     *
     * Three different things, deliberately kept apart: Failed is something going
     * wrong, NotApplicable is Laravel deciding the product does not belong on
     * the website, and Conflict is Laravel wanting it there while the website
     * already has that SKU or GTIN on a different product.
     */
    case Conflict = 'conflict';

    /** Nothing to deliver: never sent, and not wanted on the website. */
    case NotApplicable = 'not_applicable';

    /** Wanted on the website but never yet considered for delivery. */
    case NotSynced = 'not_synced';

    /**
     * Decide how far the website is from the desired state for a product.
     *
     * The ledger row is authoritative when one exists: it records what was
     * actually asked for and what came back. Eligibility only decides the
     * fallback for a product the ledger has never seen.
     */
    public static function for(Product $product, WebsiteEligibility $eligibility, ?SyncRecord $record): self
    {
        if ($record === null) {
            // Never queued. An excluded product that has never been delivered
            // genuinely has nothing to do; an eligible one is simply waiting.
            return $eligibility->isEligible($product) ? self::NotSynced : self::NotApplicable;
        }

        return match ($record->status) {
            SyncStatus::Pending => self::Pending,
            SyncStatus::Syncing => self::Syncing,
            SyncStatus::Failed => self::Failed,
            SyncStatus::Conflict => self::Conflict,
            // A synced row whose intent has since moved on is not up to date.
            // This is the case that must not collapse into "not applicable":
            // a product removed from the catalogue was delivered once and now
            // needs a removal that has not happened yet.
            SyncStatus::Synced => $record->isDelivered() ? self::Synced : self::Pending,
        };
    }

    /**
     * Decide how far the website is from the desired state for a campaign.
     *
     * The same states mean the same things as for a product; only the fallback
     * differs. A campaign with no ledger row has never been considered for
     * delivery, and there is no "not applicable" case because a campaign is
     * always wanted on the website — Laravel mirrors every campaign it knows
     * about and the website decides what to show.
     */
    public static function forCampaign(?SyncRecord $record): self
    {
        return self::forMirrored($record);
    }

    /**
     * Customers are mirrored like campaigns: always wanted, never "not applicable".
     */
    public static function forCustomer(?SyncRecord $record): self
    {
        return self::forMirrored($record);
    }

    /**
     * Sales orders are mirrored like customers: always wanted, never "not
     * applicable". A completed order stays on the website as history.
     */
    public static function forSalesOrder(?SyncRecord $record): self
    {
        return self::forMirrored($record);
    }

    /**
     * Posted invoices are mirrored like orders: always wanted, never removed.
     */
    public static function forSalesInvoice(?SyncRecord $record): self
    {
        return self::forMirrored($record);
    }

    /**
     * Decide how far the website is from the desired state for a contact.
     *
     * Contacts follow the product rule rather than the mirrored one: most are
     * never wanted on the website, so a contact with no ledger row is "not
     * applicable" unless it qualifies. A contact that was delivered and has
     * since stopped qualifying still reads from its ledger row — it is not
     * removed, and the dashboard shows the contradiction instead.
     */
    public static function forContact(Contact $contact, ContactEligibility $eligibility, ?SyncRecord $record): self
    {
        if ($record === null) {
            return $eligibility->isEligible($contact) ? self::NotSynced : self::NotApplicable;
        }

        return self::forMirrored($record);
    }

    /**
     * The shared rule for an entity that is always wanted on the website.
     */
    private static function forMirrored(?SyncRecord $record): self
    {
        if ($record === null) {
            return self::NotSynced;
        }

        return match ($record->status) {
            SyncStatus::Pending => self::Pending,
            SyncStatus::Syncing => self::Syncing,
            SyncStatus::Failed => self::Failed,
            SyncStatus::Conflict => self::Conflict,
            SyncStatus::Synced => $record->isDelivered() ? self::Synced : self::Pending,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Syncing => 'Syncing',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
            self::Conflict => 'Conflict',
            self::NotApplicable => 'Not applicable',
            self::NotSynced => 'Not synced',
        };
    }

    /**
     * Whether this status represents a problem needing attention.
     *
     * Only a genuine delivery failure does. Everything else is either done or
     * on its way.
     */
    public function isProblem(): bool
    {
        return $this === self::Failed || $this === self::Conflict;
    }

    /**
     * Whether the website still has to be told something.
     */
    public function needsDelivery(): bool
    {
        return $this === self::Pending
            || $this === self::Syncing
            || $this === self::Failed
            || $this === self::Conflict
            || $this === self::NotSynced;
    }

    public function explain(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for the desired website state to be delivered.',
            self::Syncing => 'A delivery is in progress.',
            self::Synced => 'The website matches the desired state.',
            self::Failed => 'Delivery to the website failed.',
            self::Conflict => 'The website already uses this identity for a different record.',
            self::NotApplicable => 'Never delivered, and not wanted on the website.',
            self::NotSynced => 'Wanted on the website but not yet queued for delivery.',
        };
    }
}
