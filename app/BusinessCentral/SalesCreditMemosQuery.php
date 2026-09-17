<?php

namespace App\BusinessCentral;

/**
 * The Business Central custom API page and OData query used to read posted
 * sales credit memos, and the standard API path for their lines.
 *
 * A posted credit memo is immutable and is never deleted, like a posted
 * invoice, so the same weekly full re-read applies (see config/sync.php).
 *
 * Unlike the invoice page, the GUID this page exposes as `id` is NOT the one
 * the standard salesCreditMemos entity uses. The page carries the standard
 * GUID separately as `documentApiId`, and that is what the lines are read
 * by; asking by `id` answers 404 for some memos. documentApiId() is the one
 * place that rule lives.
 */
final class SalesCreditMemosQuery
{
    public const PUBLISHER = 'brooklands';

    public const GROUP = 'catalog';

    public const VERSION = 'v1.0';

    public const ENTITY_SET = 'postedSalesCreditMemosExt';

    public const SELECT = 'id,documentApiId,number,returnOrderNumber,creditMemoDate,customerId,customerName,'
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
    public const STANDARD_ENTITY_SET = 'salesCreditMemos';

    public const LINES_PATH = 'salesCreditMemoLines';

    /**
     * The standard API media stream carrying the rendered credit memo PDF.
     */
    public const PDF_PATH = 'pdfDocument';

    /**
     * Build the OData query for one page of posted credit memos.
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
     * Build the OData query for one credit memo, by its Business Central number.
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
     * The GUID the standard API knows this credit memo by.
     *
     * documentApiId when the page sent one; otherwise the page's own id, which
     * is the same GUID for some memos and the only thing left to try for the
     * rest.
     *
     * @param  array<string, mixed>  $row
     */
    public static function documentApiId(array $row): string
    {
        foreach (['documentApiId', 'id'] as $key) {
            $value = $row[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * The standard API path to one credit memo's lines, by its documentApiId.
     * A GUID literal is unquoted in OData v4. No $select, for the reason
     * given on SalesOrderDetailsQuery.
     */
    public static function linesPath(string $documentApiId): string
    {
        return sprintf(
            '%s(%s)/%s',
            self::STANDARD_ENTITY_SET,
            preg_replace('/[^0-9a-fA-F-]/', '', $documentApiId),
            self::LINES_PATH,
        );
    }

    /**
     * The standard API path to one credit memo's PDF document metadata.
     *
     * Addressed by documentApiId, not by the page's own id, for the reason
     * documented on this class: the two differ for some memos and the standard
     * entity only answers to documentApiId.
     */
    public static function pdfMetadataPath(string $documentApiId): string
    {
        return sprintf(
            '%s(%s)/%s',
            self::STANDARD_ENTITY_SET,
            preg_replace('/[^0-9a-fA-F-]/', '', $documentApiId),
            self::PDF_PATH,
        );
    }

    /**
     * The custom API path to one posted credit memo header, by the id the
     * website holds. Read to translate that id into its documentApiId.
     */
    public static function postedHeaderPath(string $creditMemoId): string
    {
        return sprintf(
            '%s(%s)',
            self::ENTITY_SET,
            preg_replace('/[^0-9a-fA-F-]/', '', $creditMemoId),
        );
    }
}
