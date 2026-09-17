<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read posted
 * sales invoices, and the standard API path for their lines.
 *
 * A posted invoice is immutable and is never deleted, so the page only ever
 * grows and an incremental fetch sees almost nothing: an invoice is written
 * once, when it is posted. That is why the full re-read is weekly rather
 * than nightly (see config/sync.php).
 *
 * The page carries orderNumber, which is how an invoice is tied back to the
 * order it was posted from. The GUID it exposes is the same one the standard
 * salesInvoices entity uses, so the lines can be read by it directly.
 */
final class SalesInvoicesQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'postedSalesInvoicesExt';

    public const SELECT = 'id,number,orderNumber,invoiceDate,orderDate,customerId,customerName,'
        .'totalAmountExcludingTax,totalTaxAmount,totalAmountIncludingTax,externalDocumentNumber,'
        .'shipToAddressLine1,shipToAddressLine2,shipToCity,shipToState,shipToPostCode,workDescription,'
        .'SystemCreatedAt,lastModifiedDateTime';

    /**
     * Total ordering, so $skip can page without repeating or skipping rows.
     */
    public const ORDER_BY = 'lastModifiedDateTime asc,id asc';

    /**
     * The standard API entity the lines hang off.
     */
    public const STANDARD_ENTITY_SET = 'salesInvoices';

    public const LINES_PATH = 'salesInvoiceLines';

    /**
     * The standard API media stream carrying the rendered invoice PDF.
     */
    public const PDF_PATH = 'pdfDocument';

    /**
     * Build the OData query for one page of posted invoices.
     *
     * $since is strict "gt" for the reason documented on ItemsQuery::page().
     *
     * @return array<string, scalar>
     */
    public static function page(int $pageSize, int $skip = 0, ?string $since = null): array
    {
        $query = [
            '$select' => self::SELECT,
            '$orderby' => self::ORDER_BY,
            '$top' => $pageSize,
        ];

        if ($skip > 0) {
            $query['$skip'] = $skip;
        }

        if ($since !== null) {
            $query['$filter'] = sprintf('lastModifiedDateTime gt %s', $since);
        }

        return $query;
    }

    /**
     * Build the OData query for one invoice, by its Business Central number.
     *
     * @return array<string, scalar>
     */
    public static function forNumber(string $number): array
    {
        return [
            '$select' => self::SELECT,
            '$filter' => sprintf("number eq '%s'", str_replace("'", "''", $number)),
        ];
    }

    /**
     * The standard API path to one invoice's lines. A GUID literal is
     * unquoted in OData v4. No $select, for the reason given on
     * SalesOrderDetailsQuery.
     */
    public static function linesPath(string $invoiceId): string
    {
        return sprintf(
            '%s(%s)/%s',
            self::STANDARD_ENTITY_SET,
            preg_replace('/[^0-9a-fA-F-]/', '', $invoiceId),
            self::LINES_PATH,
        );
    }

    /**
     * The standard API path to one invoice's PDF document metadata.
     *
     * Metadata, not the bytes: the response carries the mediaReadLink the
     * actual PDF is then fetched from, for the reason given on
     * BusinessCentralClient::getMedia().
     */
    public static function pdfMetadataPath(string $invoiceId): string
    {
        return sprintf('%s(%s)/%s', self::STANDARD_ENTITY_SET, self::guid($invoiceId), self::PDF_PATH);
    }

    /**
     * The custom API path to one posted invoice header, by the id the website holds.
     */
    public static function postedHeaderPath(string $invoiceId): string
    {
        return sprintf('%s(%s)', self::ENTITY_SET, self::guid($invoiceId));
    }

    /**
     * The query that finds a standard-API invoice by its number.
     *
     * Only the id is selected: this call exists to translate a number back into
     * the GUID the standard API keys the document by, and nothing else is read.
     *
     * @return array<string, scalar>
     */
    public static function idForNumber(string $number): array
    {
        return [
            '$filter' => sprintf("number eq '%s'", str_replace("'", "''", $number)),
            '$select' => 'id',
        ];
    }

    /**
     * A GUID as an OData literal: unquoted in OData v4, and stripped of
     * anything that is not GUID-shaped so a path parameter cannot inject.
     */
    private static function guid(string $value): string
    {
        return preg_replace('/[^0-9a-fA-F-]/', '', $value);
    }
}
