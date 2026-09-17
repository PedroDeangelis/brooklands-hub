<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read contacts.
 *
 * One categorical filter is applied in Business Central: only contacts of type
 * Person are fetched, because a company contact can never become a website
 * user. Every other rule the legacy sync applied in its query — USER level,
 * Customer relation, and so on — is evaluated locally by ContactEligibility,
 * so a contact failing one is imported and the dashboard can say why it is
 * not on the website.
 */
final class ContactsQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'contactsExt';

    public const SELECT = 'id,number,type,displayName,companyNumber,companyName,organisationalLevelCode,'
        .'contactBusinessRelation,addressLine1,addressLine2,city,state,postalCode,country,phoneNumber,'
        .'mobilePhoneNumber,email,privacyBlocked,lastModifiedDateTime';

    /**
     * The one rule that stays in Business Central.
     */
    public const FILTER_PERSON = "type eq 'Person'";

    /**
     * Total ordering, so $skip can page without repeating or skipping rows.
     */
    public const ORDER_BY = 'lastModifiedDateTime asc,id asc';

    /**
     * Build the OData query for one page of contacts.
     *
     * $since is strict "gt" for the reason documented on ItemsQuery::page(): the
     * checkpoint only moves once a complete result set has been fetched, so
     * records sharing its timestamp were all dispatched before it got there.
     *
     * @return array<string, scalar>
     */
    public static function page(int $pageSize, int $skip = 0, ?string $since = null): array
    {
        $query = [
            '$select' => self::SELECT,
            '$orderby' => self::ORDER_BY,
            '$top' => $pageSize,
            '$filter' => self::FILTER_PERSON,
        ];

        if ($skip > 0) {
            $query['$skip'] = $skip;
        }

        if ($since !== null) {
            $query['$filter'] = sprintf('%s and lastModifiedDateTime gt %s', self::FILTER_PERSON, $since);
        }

        return $query;
    }

    /**
     * Build the OData query for one contact, by its Business Central number.
     *
     * @return array<string, scalar>
     */
    public static function forNumber(string $number): array
    {
        return [
            '$select' => self::SELECT,
            '$filter' => sprintf("%s and number eq '%s'", self::FILTER_PERSON, str_replace("'", "''", $number)),
        ];
    }
}
