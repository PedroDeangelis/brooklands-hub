<?php

namespace App\BusinessCentral;

/**
 * Reads the lines behind one posted sales invoice header.
 *
 * Runs inside the import job rather than the import command, for the reason
 * given on SalesOrderDetailFetcher: one extra request per invoice belongs on
 * Horizon, where a failure is retried and a slow tenant holds nothing else up.
 *
 * A failed read throws, and the invoice is not imported at all: an invoice
 * with no items is not something the website should show.
 */
class SalesInvoiceDetailFetcher
{
    public function __construct(private readonly BusinessCentralClient $client) {}

    /**
     * @return array{lines: list<array<string, mixed>>}
     *
     * @throws BusinessCentralException when the read fails.
     */
    public function fetch(string $bcId): array
    {
        $response = $this->client->getStandard(SalesInvoicesQuery::linesPath($bcId));

        $rows = $response['value'] ?? [];

        return [
            'lines' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
        ];
    }
}
