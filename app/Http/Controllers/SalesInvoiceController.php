<?php

namespace App\Http\Controllers;

use App\Models\SalesInvoice;
use App\SalesInvoices\SalesInvoiceFilter;
use App\Sync\DeliveryStatus;
use App\Sync\SalesInvoiceSyncLedger;
use App\Website\WebsiteClient;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * The sales invoice list and detail pages.
 *
 * Read-only, like the rest of the dashboard: nothing here delivers, queues or
 * changes anything.
 */
class SalesInvoiceController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly SalesInvoiceSyncLedger $ledger,
        private readonly WebsiteClient $website,
    ) {}

    public function index(Request $request): View
    {
        $filter = SalesInvoiceFilter::fromRequest($request);

        $salesInvoices = $filter->apply(SalesInvoice::query())
            ->with('websiteSyncRecord')
            ->orderByDesc('invoice_date')
            ->orderByDesc('number')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('sales-invoices.index', [
            'salesInvoices' => $salesInvoices,
            'filter' => $filter,
            'onWebsite' => $this->onWebsiteFor($salesInvoices->getCollection()),
        ]);
    }

    /**
     * What the website holds for a page of invoices, in one request.
     *
     * One call for the whole page rather than one per row: the status route is
     * bounded at 200 ids and the list pages at 25, so a page costs a single
     * round trip.
     *
     * Null means the website could not be reached — shown as "unknown" rather
     * than as "missing", because those are very different things.
     *
     * @param  Collection<int, SalesInvoice>  $salesInvoices
     * @return array<string, array{wp_id: int, status: string}|null>|null
     */
    private function onWebsiteFor($salesInvoices): ?array
    {
        if ($salesInvoices->isEmpty()) {
            return [];
        }

        try {
            return $this->website->status(
                SalesInvoiceSyncLedger::ENTITY_SALES_INVOICE,
                $salesInvoices->pluck('bc_id')->all(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the website actually holds for this invoice, right now.
     *
     * The ledger can only say what the website confirmed at the moment of
     * delivery. Asking the website directly is the only answer that cannot go
     * stale. Null when the website could not be reached, which the page shows
     * as "unknown" rather than as "missing".
     *
     * @return array{wp_id: int, status: string}|null|false False when absent.
     */
    private function onWebsite(SalesInvoice $salesInvoice): array|false|null
    {
        try {
            $records = $this->website->status(
                SalesInvoiceSyncLedger::ENTITY_SALES_INVOICE,
                [$salesInvoice->bc_id],
            );
        } catch (Throwable) {
            // Never break the page over this: the rest of it is local data and
            // is still worth showing.
            return null;
        }

        return $records[$salesInvoice->bc_id] ?? false;
    }

    public function show(SalesInvoice $salesInvoice): View
    {
        $syncRecord = $salesInvoice->websiteSyncRecord()->first();

        return view('sales-invoices.show', [
            'salesInvoice' => $salesInvoice,
            'customer' => $salesInvoice->customer,
            'salesOrder' => $salesInvoice->salesOrder,
            'syncRecord' => $syncRecord,
            'deliveryStatus' => DeliveryStatus::forSalesInvoice($syncRecord),
            'payload' => $this->ledger->payloadFor($salesInvoice),
            'plan' => $this->ledger->plan($salesInvoice),
            'onWebsite' => $this->onWebsite($salesInvoice),
        ]);
    }
}
