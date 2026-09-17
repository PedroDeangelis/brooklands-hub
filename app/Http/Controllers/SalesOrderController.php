<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\SalesOrders\SalesOrderFilter;
use App\Sync\DeliveryStatus;
use App\Sync\SalesOrderSyncLedger;
use App\Website\WebsiteClient;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * The sales order list and detail pages.
 *
 * Read-only, like the rest of the dashboard: nothing here delivers, queues or
 * changes anything.
 */
class SalesOrderController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly SalesOrderSyncLedger $ledger,
        private readonly WebsiteClient $website,
    ) {}

    public function index(Request $request): View
    {
        $filter = SalesOrderFilter::fromRequest($request);

        $salesOrders = $filter->apply(SalesOrder::query())
            ->with('websiteSyncRecord')
            ->orderByDesc('order_date')
            ->orderByDesc('number')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('sales-orders.index', [
            'salesOrders' => $salesOrders,
            'filter' => $filter,
            'onWebsite' => $this->onWebsiteFor($salesOrders->getCollection()),
        ]);
    }

    /**
     * What the website holds for a page of orders, in one request.
     *
     * One call for the whole page rather than one per row: the status route is
     * bounded at 200 ids and the list pages at 25, so a page costs a single
     * round trip.
     *
     * Null means the website could not be reached — shown as "unknown" rather
     * than as "missing", because those are very different things.
     *
     * @param  Collection<int, SalesOrder>  $salesOrders
     * @return array<string, array{wp_id: int, status: string}|null>|null
     */
    private function onWebsiteFor($salesOrders): ?array
    {
        if ($salesOrders->isEmpty()) {
            return [];
        }

        try {
            return $this->website->status(
                SalesOrderSyncLedger::ENTITY_SALES_ORDER,
                $salesOrders->pluck('bc_id')->all(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the website actually holds for this order, right now.
     *
     * The ledger can only say what the website confirmed at the moment of
     * delivery. Asking the website directly is the only answer that cannot go
     * stale. Null when the website could not be reached, which the page shows
     * as "unknown" rather than as "missing".
     *
     * @return array{wp_id: int, status: string}|null|false False when absent.
     */
    private function onWebsite(SalesOrder $salesOrder): array|false|null
    {
        try {
            $records = $this->website->status(
                SalesOrderSyncLedger::ENTITY_SALES_ORDER,
                [$salesOrder->bc_id],
            );
        } catch (Throwable) {
            // Never break the page over this: the rest of it is local data and
            // is still worth showing.
            return null;
        }

        return $records[$salesOrder->bc_id] ?? false;
    }

    public function show(SalesOrder $salesOrder): View
    {
        $syncRecord = $salesOrder->websiteSyncRecord()->first();

        return view('sales-orders.show', [
            'salesOrder' => $salesOrder,
            'customer' => $salesOrder->customer,
            'status' => $salesOrder->websiteStatus(),
            'syncRecord' => $syncRecord,
            'deliveryStatus' => DeliveryStatus::forSalesOrder($syncRecord),
            'payload' => $this->ledger->payloadFor($salesOrder),
            'plan' => $this->ledger->plan($salesOrder),
            'onWebsite' => $this->onWebsite($salesOrder),
        ]);
    }
}
