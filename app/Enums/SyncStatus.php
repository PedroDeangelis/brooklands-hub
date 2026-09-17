<?php

namespace App\Enums;

/**
 * Delivery state of a single Business Central record on one channel.
 */
enum SyncStatus: string
{
    /** Waiting to be delivered. */
    case Pending = 'pending';

    /** A delivery job currently holds this record. */
    case Syncing = 'syncing';

    /** Successfully delivered. */
    case Synced = 'synced';

    /** Delivery failed. */
    case Failed = 'failed';

    /**
     * Blocked by an identity collision on the website.
     *
     * Distinct from Failed, which is something going wrong, and from exclusion,
     * which is Laravel deciding the product does not belong on the website. Here
     * Laravel wants it there and the website cannot take it as addressed.
     */
    case Conflict = 'conflict';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Syncing => 'Syncing',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
            self::Conflict => 'Conflict',
        };
    }

    /**
     * Whether a delivery is owed for this record.
     *
     * Syncing is excluded: a job already holds it, and re-dispatching would
     * duplicate work rather than advance it.
     */
    public function needsDispatch(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    /**
     * Whether re-sending the same payload could possibly help.
     *
     * A conflict is settled by changing data, not by trying again.
     */
    public function isRetryable(): bool
    {
        return $this !== self::Conflict && $this !== self::Synced;
    }
}
