<?php

namespace App\Http\Controllers;

use App\Customers\CustomerFilter;
use App\Customers\CustomerStatus;
use App\Models\Customer;
use App\Sync\CustomerSyncLedger;
use App\Sync\DeliveryStatus;
use App\Website\WebsiteClient;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * The customer list and detail pages.
 *
 * Business Central calls these Customers; the website calls them Customers,
 * and this dashboard uses the website's word because that is what a person
 * looking at it is trying to reason about.
 *
 * Read-only, like the rest of the dashboard: nothing here delivers, queues or
 * changes anything.
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly CustomerSyncLedger $ledger,
        private readonly WebsiteClient $website,
    ) {}

    public function index(Request $request): View
    {
        $filter = CustomerFilter::fromRequest($request);

        $customers = $filter->apply(Customer::query())
            ->with('websiteSyncRecord')
            ->orderBy('number')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'filter' => $filter,
            'onWebsite' => $this->onWebsiteFor($customers->getCollection()),
        ]);
    }

    /**
     * What the website holds for a page of customers, in one request.
     *
     * One call for the whole page rather than one per row: the status route is
     * bounded at 200 ids and the list pages at 25, so a page costs a single
     * round trip.
     *
     * Null means the website could not be reached — shown as "unknown" rather
     * than as "missing", because those are very different things.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<string, array{wp_id: int, status: string}|null>|null
     */
    private function onWebsiteFor($customers): ?array
    {
        if ($customers->isEmpty()) {
            return [];
        }

        try {
            return $this->website->status(
                CustomerSyncLedger::ENTITY_CUSTOMER,
                $customers->pluck('bc_id')->all(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the website actually holds for this customer, right now.
     *
     * The ledger can only say what the website confirmed at the moment of
     * delivery. Anything that removes the post afterwards leaves that record
     * saying "synced" forever, which is how a customer can read as fine on
     * this page while being absent from the site. Asking the website directly
     * is the only answer that cannot go stale.
     *
     * Returns null when the website could not be reached, which the page shows
     * as "unknown" rather than as "missing": an unreachable site is not the
     * same as a deleted customer.
     *
     * @return array{wp_id: int, status: string}|null|false False when absent.
     */
    private function onWebsite(Customer $customer): array|false|null
    {
        try {
            $records = $this->website->status(
                CustomerSyncLedger::ENTITY_CUSTOMER,
                [$customer->bc_id],
            );
        } catch (Throwable) {
            // Never break the page over this: the rest of it is local data and
            // is still worth showing.
            return null;
        }

        return $records[$customer->bc_id] ?? false;
    }

    public function show(Customer $customer): View
    {
        $syncRecord = $customer->websiteSyncRecord()->first();

        return view('customers.show', [
            'customer' => $customer,
            'status' => CustomerStatus::for($customer),
            'syncRecord' => $syncRecord,
            'deliveryStatus' => DeliveryStatus::forCustomer($syncRecord),
            'payload' => $this->ledger->payloadFor($customer),
            'plan' => $this->ledger->plan($customer),
            'onWebsite' => $this->onWebsite($customer),
        ]);
    }
}
