<?php

namespace App\Jobs;

use RuntimeException;

/**
 * A delivery attempt that did not succeed.
 *
 * Carries the status so Horizon's failed-jobs list is readable without opening
 * the ledger row.
 */
final class WebsiteDeliveryFailed extends RuntimeException {}
