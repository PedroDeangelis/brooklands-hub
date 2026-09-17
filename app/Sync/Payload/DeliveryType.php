<?php

namespace App\Sync\Payload;

/**
 * The shape of the next delivery for a product.
 */
enum DeliveryType: string
{
    /** Everything, because the website has never been told anything about it. */
    case Full = 'full';

    /** Only the website fields that moved since the last successful delivery. */
    case Partial = 'partial';

    /** Take it down; the minimal removal payload is sent instead of fields. */
    case Remove = 'remove';

    /** Nothing to send: the website already matches. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Partial => 'Partial',
            self::Remove => 'Remove',
            self::None => 'Nothing to send',
        };
    }

    public function explain(): string
    {
        return match ($this) {
            self::Full => 'Nothing has been delivered yet, so the complete payload is sent.',
            self::Partial => 'Only the website fields that changed since the last delivery are sent.',
            self::Remove => 'The product is taken down; no field values are sent.',
            self::None => 'The website already holds the desired payload.',
        };
    }
}
