<?php

namespace App\Website;

/**
 * How the website answered a delivery.
 *
 * Four outcomes, because a request can succeed as a request without the website
 * having changed anything: the transport-only endpoint validates and returns
 * without applying, which is neither a delivery nor a failure.
 */
enum WebsiteOutcome: string
{
    /** The website applied the desired state. */
    case Delivered = 'delivered';

    /**
     * The request was understood and accepted, but nothing was applied.
     *
     * Recording this as delivered would claim the website holds a state it was
     * never given, and every later diff would be computed against a fiction.
     */
    case Accepted = 'accepted';

    /**
     * Applying would collide with another product on the website.
     *
     * Deterministic like a permanent failure, but not a failure: the delivery
     * was valid and the product is wanted. Someone has to correct the data.
     */
    case Conflict = 'conflict';

    /**
     * The website has no record of this product, so a partial cannot apply.
     *
     * Recoverable without anyone intervening: Laravel forgets what it believed
     * the website held, which makes the next delivery a full payload.
     */
    case FullSyncRequired = 'full_sync_required';

    /** Something temporary; trying again may work. */
    case Transient = 'transient';

    /** The request itself is wrong; repeating it will not help. */
    case Permanent = 'permanent';
}
