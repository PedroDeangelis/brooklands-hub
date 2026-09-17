<?php

namespace App\BusinessCentral;

/**
 * Reads the lines, shipments and invoices behind one sales order header.
 *
 * Runs inside the import job rather than the import command, so the three
 * extra requests per order happen on Horizon where a failure is retried and
 * a slow tenant does not hold the scheduler. The header row alone is not
 * enough to import: the website status is derived from how much of each line
 * has shipped and invoiced, and only the lines know that.
 *
 * Any request failing throws, and the order is not imported at all. Importing
 * the header without its lines would derive a status from nothing and
 * deliver an order with no items.
 */
class SalesOrderDetailFetcher
{
    public function __construct(private readonly BusinessCentralClient $client) {}

    /**
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     salesShipments: list<array<string, mixed>>,
     *     salesInvoices: list<array<string, mixed>>
     * }
     *
     * @throws BusinessCentralException when any of the three reads fails.
     */
    public function fetch(string $bcId, string $number): array
    {
        $lines = $this->rows($this->client->getStandard(
            SalesOrderDetailsQuery::linesPath($bcId),
            SalesOrderDetailsQuery::lines(),
        ));

        // Shipments and invoices are found by the order's number. An order
        // with no number has posted nothing, so there is nothing to ask for.
        $shipments = $number === '' ? [] : $this->rows($this->client->getStandard(
            SalesOrderDetailsQuery::SHIPMENTS_ENTITY_SET,
            SalesOrderDetailsQuery::shipmentsFor($number),
        ));

        $invoices = $number === '' ? [] : $this->rows($this->client->getStandard(
            SalesOrderDetailsQuery::INVOICES_ENTITY_SET,
            SalesOrderDetailsQuery::invoicesFor($number),
        ));

        return [
            'lines' => $lines,
            'salesShipments' => $shipments,
            'salesInvoices' => $invoices,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function rows(array $response): array
    {
        $rows = $response['value'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
