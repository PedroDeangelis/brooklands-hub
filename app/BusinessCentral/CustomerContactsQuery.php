<?php

namespace App\BusinessCentral;

/**
 * The Business Central standard API page that links contacts to customers.
 *
 * The contact record itself carries no customer: its companyNumber is a
 * contact-company number, not a customer number. The link lives on the
 * standard API's customerContacts page, where each row's id is the contact's
 * id and customerId is the customer it belongs to.
 *
 * Read as a sweep, never incrementally: the page has no lastModifiedDateTime,
 * so there is nothing to checkpoint against. It is ~1,400 rows — seven
 * requests — and an unchanged link costs nothing beyond the fetch.
 */
final class CustomerContactsQuery
{
    public const ENTITY_SET = 'customerContacts';

    public const SELECT = 'id,customerId,customerName,email';

    /**
     * id alone is a total ordering, which is all a sweep needs.
     */
    public const ORDER_BY = 'id asc';

    /**
     * @return array<string, scalar>
     */
    public static function page(int $pageSize, int $skip = 0): array
    {
        $query = [
            '$select' => self::SELECT,
            '$orderby' => self::ORDER_BY,
            '$top' => $pageSize,
        ];

        if ($skip > 0) {
            $query['$skip'] = $skip;
        }

        return $query;
    }

    /**
     * The link for one contact, by the contact's Business Central id.
     *
     * A GUID literal is unquoted in OData v4.
     *
     * @return array<string, scalar>
     */
    public static function forContactId(string $contactId): array
    {
        return [
            '$select' => self::SELECT,
            '$filter' => sprintf('id eq %s', preg_replace('/[^0-9a-fA-F-]/', '', $contactId)),
        ];
    }
}
