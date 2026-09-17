<?php

namespace App\BusinessCentral;

/**
 * Reads the lines behind one posted sales credit memo header.
 *
 * The credit memo counterpart to SalesInvoiceDetailFetcher, with one
 * difference that is the whole reason it exists: the lines are addressed by
 * the memo's documentApiId, not by the custom page's id, because the two are
 * different GUIDs for some memos (see SalesCreditMemosQuery).
 *
 * A failed read throws, and the memo is not imported at all.
 */
class SalesCreditMemoDetailFetcher
{
    public function __construct(private readonly BusinessCentralClient $client) {}

    /**
     * @param  string  $documentApiId  As resolved by SalesCreditMemosQuery::documentApiId().
     * @return array{lines: list<array<string, mixed>>}
     *
     * @throws BusinessCentralException when the read fails.
     */
    public function fetch(string $documentApiId): array
    {
        $response = $this->client->getStandard(SalesCreditMemosQuery::linesPath($documentApiId));

        $rows = $response['value'] ?? [];

        return [
            'lines' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
        ];
    }
}
