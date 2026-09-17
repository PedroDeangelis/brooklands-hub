<?php

namespace App\Customers;

use App\Models\Customer;

/**
 * Where a customer stands with Business Central, for display.
 *
 * Read-only and derived; the sync never consults it. Laravel mirrors the
 * customer and the website decides what a block means for logging in and
 * ordering — this exists so a person can see the state at a glance.
 */
enum CustomerStatus: string
{
    case Active = 'active';

    case Blocked = 'blocked';

    public static function for(Customer $customer): self
    {
        return $customer->isBlocked() ? self::Blocked : self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Blocked => 'Blocked',
        };
    }

    public function explain(): string
    {
        return match ($this) {
            self::Active => 'Not blocked in Business Central.',
            self::Blocked => 'Blocked in Business Central; the website decides what that restricts.',
        };
    }
}
