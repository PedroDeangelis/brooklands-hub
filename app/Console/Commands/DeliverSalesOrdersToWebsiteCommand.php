<?php

namespace App\Console\Commands;

use App\Jobs\DeliverSalesOrderToWebsite;
use App\Models\SalesOrder;
use App\Sync\Payload\DeliveryType;
use App\Sync\SalesOrderSyncLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opens ledger work for sales orders and queues their delivery.
 *
 * Two jobs in one command, deliberately. An order imported before delivery
 * existed has no ledger row at all, and an order whose row is already up to
 * date needs nothing; both are answered by rebuilding the plan from current
 * state and acting on what it says. Nothing here decides anything the ledger
 * could not decide on its own.
 *
 * Dry run is the default. Delivering an order creates a post the customer can
 * see in their account, so sending has to be asked for explicitly rather than
 * being what happens when someone runs the command to see what it would do.
 */
#[Signature('website:deliver-sales-orders
    {--send : Actually queue the deliveries (without this the command only reports)}
    {--number=* : Limit to these order numbers}
    {--limit= : Stop after this many orders}')]
#[Description('Plan and queue website delivery for sales orders')]
class DeliverSalesOrdersToWebsiteCommand extends Command
{
    public function handle(SalesOrderSyncLedger $ledger): int
    {
        $send = (bool) $this->option('send');
        $numbers = array_filter(array_map('trim', (array) $this->option('number')));
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        if ($limit !== null && $limit < 1) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $salesOrders = $this->salesOrders($numbers, $limit);

        if ($salesOrders->isEmpty()) {
            $this->info($numbers === []
                ? 'No sales orders to consider.'
                : 'No sales orders match those numbers.');

            return self::SUCCESS;
        }

        $this->line($send
            ? sprintf('Delivering %d order(s).', $salesOrders->count())
            : sprintf('Dry run over %d order(s). Pass --send to queue them.', $salesOrders->count()));
        $this->newLine();

        $queued = 0;
        $skipped = 0;

        foreach ($salesOrders as $salesOrder) {
            $plan = $ledger->plan($salesOrder);
            $record = $ledger->find($salesOrder);

            $this->line(sprintf(
                '  %-10s %-30s %-18s %-8s %-7s %s',
                $salesOrder->number,
                mb_substr((string) $salesOrder->customer_name, 0, 28),
                $salesOrder->websiteStatus()->value,
                $plan->action->value,
                $plan->type->value,
                $record === null ? 'no ledger row' : 'ledger: '.$record->status->value,
            ));

            if ($plan->type === DeliveryType::None) {
                $skipped++;

                continue;
            }

            if (! $send) {
                $queued++;

                continue;
            }

            // Opening the row is what makes the delivery job find something to
            // do: the job rebuilds the plan itself, but it returns immediately
            // when no ledger row exists.
            $ledger->markPending($salesOrder, $plan->diff->changedFields);

            DeliverSalesOrderToWebsite::dispatch($salesOrder->bc_id);

            $queued++;
        }

        $this->newLine();
        $this->info($send
            ? sprintf('Queued %d order(s); %d already up to date.', $queued, $skipped)
            : sprintf('%d order(s) would be delivered; %d already up to date.', $queued, $skipped));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $numbers
     * @return Collection<int, SalesOrder>
     */
    private function salesOrders(array $numbers, ?int $limit): Collection
    {
        return SalesOrder::query()
            ->when($numbers !== [], fn ($query) => $query->whereIn('number', $numbers))
            ->orderBy('number')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }
}
