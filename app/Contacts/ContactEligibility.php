<?php

namespace App\Contacts;

use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Decides whether a contact currently qualifies to become a website user.
 *
 * The legacy sync applied the level and relation rules in its Business Central
 * query, deferred a contact silently when its customer or the customer's
 * addresses had not arrived, and had no rule at all for two contacts sharing
 * an email. Every rule is applied here instead, so every person contact is
 * imported and the dashboard can say exactly why one is not on the website.
 *
 * Every rule is evaluated: a contact may fail several, and reporting only the
 * first would send someone to fix one thing and leave the contact excluded.
 *
 * Each rule exists twice — once per contact, once as SQL — so the dashboard
 * counts and the detail page cannot disagree. A test asserts the two agree.
 */
class ContactEligibility
{
    /**
     * The organisational level a contact must hold.
     */
    public const USER_LEVEL = 'USER';

    /**
     * The business relation a contact must hold.
     */
    public const CUSTOMER_RELATION = 'Customer';

    /**
     * Every reason this contact does not qualify for the website.
     */
    public function for(Contact $contact): ContactEligibilityResult
    {
        $exclusions = [];

        if (! $this->isUserLevel($contact)) {
            $exclusions[] = new ContactExclusion(ContactExclusionReason::NotUserLevel, $contact->organisational_level_code);
        }

        if (! $this->isCustomerRelation($contact)) {
            $exclusions[] = new ContactExclusion(ContactExclusionReason::NotCustomerRelation, $contact->contact_business_relation);
        }

        if ($contact->privacy_blocked) {
            $exclusions[] = new ContactExclusion(ContactExclusionReason::PrivacyBlocked);
        }

        if (! $contact->hasEmail()) {
            $exclusions[] = new ContactExclusion(ContactExclusionReason::NoEmail);
        }

        $winner = $this->emailWinner($contact);

        if ($winner !== null) {
            $exclusions[] = new ContactExclusion(ContactExclusionReason::DuplicateEmail, $winner->number);
        }

        $customer = $this->customerOf($contact);

        if ($customer === null) {
            $exclusions[] = new ContactExclusion(ContactExclusionReason::NoCustomer, $contact->customer_bc_id);
        } elseif ($customer->shippingAddresses() === []) {
            $exclusions[] = new ContactExclusion(ContactExclusionReason::CustomerHasNoShippingAddress, $customer->number);
        }

        return ContactEligibilityResult::excluded($exclusions);
    }

    public function isEligible(Contact $contact): bool
    {
        return $this->for($contact)->eligible;
    }

    /**
     * Constrain a query to contacts that do not qualify.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeExcluded(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach (ContactExclusionReason::cases() as $reason) {
                $query->orWhere(fn (Builder $query): Builder => $this->scopeWithReason($query, $reason));
            }
        });
    }

    /**
     * Constrain a query to contacts that meet every rule.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeEligible(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $query): Builder => $this->scopeExcluded($query));
    }

    /**
     * Constrain a query to contacts excluded for one particular reason.
     *
     * A contact may fail several rules, so this matches every contact whose
     * reasons include this one rather than only those failing it alone.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeWithReason(Builder $query, ContactExclusionReason $reason): Builder
    {
        return match ($reason) {
            ContactExclusionReason::NotUserLevel => $query->whereNot(
                fn (Builder $query) => $this->userLevel($query, 'contacts'),
            ),
            ContactExclusionReason::NotCustomerRelation => $query->whereNot(
                fn (Builder $query) => $this->customerRelation($query, 'contacts'),
            ),
            ContactExclusionReason::PrivacyBlocked => $query->where('contacts.privacy_blocked', true),
            ContactExclusionReason::NoEmail => $query->whereNot(
                fn (Builder $query) => $this->hasEmailColumn($query, 'contacts'),
            ),
            ContactExclusionReason::DuplicateEmail => $query->whereExists(
                fn (QueryBuilder $winners) => $this->winners($winners),
            ),
            ContactExclusionReason::NoCustomer => $query->where(function (Builder $query): void {
                $query->whereNull('contacts.customer_bc_id')
                    ->orWhereNotExists(fn (QueryBuilder $customers) => $customers
                        ->from('customers')
                        ->whereColumn('customers.bc_id', 'contacts.customer_bc_id'));
            }),
            ContactExclusionReason::CustomerHasNoShippingAddress => $query->whereExists(
                fn (QueryBuilder $customers) => $customers
                    ->from('customers')
                    ->whereColumn('customers.bc_id', 'contacts.customer_bc_id')
                    ->where(function (QueryBuilder $customers): void {
                        $customers->whereNull('customers.shipping_addresses')
                            ->orWhereJsonLength('customers.shipping_addresses', '=', 0);
                    }),
            ),
        };
    }

    // ------------------------------------------------------------ per contact

    private function isUserLevel(Contact $contact): bool
    {
        return mb_strtoupper(trim((string) $contact->organisational_level_code)) === self::USER_LEVEL;
    }

    private function isCustomerRelation(Contact $contact): bool
    {
        return mb_strtolower(trim((string) $contact->contact_business_relation)) === mb_strtolower(self::CUSTOMER_RELATION);
    }

    /**
     * Whether this contact passes the rules that depend on nothing but itself.
     *
     * This is what decides who wins an email. The customer-dependent rules are
     * deliberately left out: if they counted, a winner whose customer had not
     * arrived yet would let the loser through, and the pair would swap once the
     * customer landed — creating two users for one email along the way. The
     * intrinsic rules cannot flip from another record changing.
     */
    private function passesIntrinsicRules(Contact $contact): bool
    {
        return $this->isUserLevel($contact)
            && $this->isCustomerRelation($contact)
            && ! $contact->privacy_blocked
            && $contact->hasEmail();
    }

    /**
     * The contact that holds this email ahead of this one, if any.
     *
     * Lowest number wins, with the id as a tie-break so the answer is total.
     * Only a contact passing the intrinsic rules can win; see above.
     */
    private function emailWinner(Contact $contact): ?Contact
    {
        if (! $contact->hasEmail()) {
            return null;
        }

        return Contact::query()
            ->where('email', mb_strtolower(trim((string) $contact->email)))
            ->whereKeyNot($contact->getKey())
            ->where(fn (Builder $query) => $this->userLevel($query, 'contacts'))
            ->where(fn (Builder $query) => $this->customerRelation($query, 'contacts'))
            ->where('privacy_blocked', false)
            ->where(function (Builder $query) use ($contact): void {
                $query->where('number', '<', (string) $contact->number)
                    ->orWhere(function (Builder $query) use ($contact): void {
                        $query->where('number', (string) $contact->number)
                            ->where('bc_id', '<', (string) $contact->bc_id);
                    });
            })
            ->orderBy('number')
            ->orderBy('bc_id')
            ->first();
    }

    private function customerOf(Contact $contact): ?Customer
    {
        if (trim((string) $contact->customer_bc_id) === '') {
            return null;
        }

        if ($contact->relationLoaded('customer')) {
            return $contact->customer;
        }

        return Customer::query()->where('bc_id', $contact->customer_bc_id)->first();
    }

    // ------------------------------------------------------------------- SQL

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function userLevel(Builder $query, string $table): Builder
    {
        return $query->whereRaw("UPPER(TRIM(COALESCE({$table}.organisational_level_code, ''))) = ?", [self::USER_LEVEL]);
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function customerRelation(Builder $query, string $table): Builder
    {
        return $query->whereRaw("LOWER(TRIM(COALESCE({$table}.contact_business_relation, ''))) = ?", [mb_strtolower(self::CUSTOMER_RELATION)]);
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function hasEmailColumn(Builder $query, string $table): Builder
    {
        return $query->whereRaw("TRIM(COALESCE({$table}.email, '')) <> ''");
    }

    /**
     * The SQL twin of emailWinner(): another contact, same email, passing the
     * intrinsic rules, ordered ahead of this one.
     */
    private function winners(QueryBuilder $winners): QueryBuilder
    {
        return $winners
            ->from('contacts as winners')
            ->whereColumn('winners.email', 'contacts.email')
            ->whereColumn('winners.id', '<>', 'contacts.id')
            ->whereRaw("TRIM(COALESCE(winners.email, '')) <> ''")
            ->whereRaw("UPPER(TRIM(COALESCE(winners.organisational_level_code, ''))) = ?", [self::USER_LEVEL])
            ->whereRaw("LOWER(TRIM(COALESCE(winners.contact_business_relation, ''))) = ?", [mb_strtolower(self::CUSTOMER_RELATION)])
            ->where('winners.privacy_blocked', false)
            ->where(function (QueryBuilder $winners): void {
                $winners->whereColumn('winners.number', '<', 'contacts.number')
                    ->orWhere(function (QueryBuilder $winners): void {
                        $winners->whereColumn('winners.number', 'contacts.number')
                            ->whereColumn('winners.bc_id', '<', 'contacts.bc_id');
                    });
            });
    }
}
