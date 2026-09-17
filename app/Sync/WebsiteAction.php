<?php

namespace App\Sync;

/**
 * What the website should be asked to do with a product.
 *
 * This is the desired state, not a report of what has happened. It answers
 * "what should the website end up holding?", which is a conclusion drawn purely
 * from Business Central data. Whether the website already matches is a separate
 * question, answered by SyncStatus on the ledger row.
 *
 * Keeping the two apart is what makes removal work: a product that stops
 * qualifying does not become irrelevant, it acquires a new desired state that
 * still has to be delivered.
 */
enum WebsiteAction: string
{
    /** The product should exist on the website, with its current values. */
    case Upsert = 'upsert';

    /** The product should not be on the website, and is removed if present. */
    case Remove = 'remove';

    public function label(): string
    {
        return match ($this) {
            self::Upsert => 'Upsert',
            self::Remove => 'Remove',
        };
    }

    public function explain(): string
    {
        return match ($this) {
            self::Upsert => 'The website should hold this record with its current values.',
            self::Remove => 'The website should not list this record; it is removed if present.',
        };
    }
}
