<?php

namespace App\Enums;

/**
 * Delivery state of a single Business Central record on one channel.
 */
enum SyncStatus: string
{
    /** Waiting to be delivered. */
    case Pending = 'pending';

    /** Successfully delivered. */
    case Synced = 'synced';

    /** Delivery failed. */
    case Failed = 'failed';
}
