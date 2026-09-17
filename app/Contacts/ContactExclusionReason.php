<?php

namespace App\Contacts;

/**
 * A single reason a contact does not qualify to become a website user.
 *
 * Reasons are values rather than free text so the dashboard can group and count
 * them, and so a reason can be recognised again after the wording changes.
 *
 * The first four are intrinsic to the contact. The last three depend on other
 * records — another contact, the customer, the customer's addresses — and can
 * clear without the contact itself changing, which is why the link sweep
 * re-evaluates every contact rather than only the ones that moved.
 */
enum ContactExclusionReason: string
{
    /** The contact's organisational level is not USER. */
    case NotUserLevel = 'not_user_level';

    /** The contact's business relation is not Customer. */
    case NotCustomerRelation = 'not_customer_relation';

    /** Business Central has marked the person privacy blocked. */
    case PrivacyBlocked = 'privacy_blocked';

    /** No email address, and the email is the website login. */
    case NoEmail = 'no_email';

    /** Another qualifying contact with a lower number has the same email. */
    case DuplicateEmail = 'duplicate_email';

    /** No customer is linked, or the linked customer has not been imported. */
    case NoCustomer = 'no_customer';

    /** The linked customer has no ship-to address, so the site cannot ship to it. */
    case CustomerHasNoShippingAddress = 'customer_has_no_shipping_address';

    /**
     * The sentence shown to a person reading the dashboard.
     *
     * The value carries any detail from the contact itself, so "Vendor" and
     * "Bank" read as different reasons rather than one generic message.
     */
    public function describe(?string $value = null): string
    {
        $value = trim((string) $value);

        return match ($this) {
            self::NotUserLevel => $value === ''
                ? 'Contact has no organisational level; only USER contacts become website users'
                : sprintf('Contact organisational level is "%s", not USER', $value),
            self::NotCustomerRelation => $value === ''
                ? 'Contact has no business relation; only Customer contacts become website users'
                : sprintf('Contact business relation is "%s", not Customer', $value),
            self::PrivacyBlocked => 'Contact is privacy blocked in Business Central',
            self::NoEmail => 'Contact has no email address, which the website uses as the login',
            self::DuplicateEmail => $value === ''
                ? 'Another contact with a lower number has the same email address'
                : sprintf('Email address is already taken by contact %s, which has the lower number', $value),
            self::NoCustomer => $value === ''
                ? 'Contact is not linked to a customer'
                : sprintf('Linked customer %s has not been imported', $value),
            self::CustomerHasNoShippingAddress => $value === ''
                ? 'The linked customer has no ship-to address'
                : sprintf('Customer %s has no ship-to address', $value),
        };
    }

    /**
     * A short label for grouping in lists, without the contact-specific detail.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotUserLevel => 'Not USER level',
            self::NotCustomerRelation => 'Not a customer contact',
            self::PrivacyBlocked => 'Privacy blocked',
            self::NoEmail => 'No email',
            self::DuplicateEmail => 'Duplicate email',
            self::NoCustomer => 'No customer',
            self::CustomerHasNoShippingAddress => 'Customer has no ship-to',
        };
    }
}
