<?php

namespace App\Console\Commands;

use App\Jobs\DeliverSalesInvoiceToWebsite;
use App\Models\SalesInvoice;
use App\Sync\Payload\DeliveryType;
use App\Sync\SalesInvoiceSyncLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opens ledger work for sales invoices and queues their delivery.
 *
 * Two jobs in one command, deliberately. An invoice imported before delivery
 * existed has no ledger row at all, and an invoice whose row is already up to
 * date needs nothing; both are answered by rebuilding the plan from current
 * state and acting on what it says. Nothing here decides anything the ledger
 * could not decide on its own.
 *
 * Dry run is the default. Delivering an invoice creates a post the customer can
 * see in their account, so sending has to be asked for explicitly rather than
 * being what happens when someone runs the command to see what it would do.
 */
#[Signature('website:deliver-sales-invoices
    {--send : Actually queue the deliveries (without this the command only reports)}
    {--number=* : Limit to these invoice numbers}
    {--limit= : Stop after this many invoices}')]
#[Description('Plan and queue website delivery for sales invoices')]
class DeliverSalesInvoicesToWebsiteCommand extends Command
{
    public function handle(SalesInvoiceSyncLedger $ledger): int
    {
        $send = (bool) $this->option('send');
        $numbers = array_filter(array_map('trim', (array) $this->option('number')));
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        if ($limit !== null && $limit < 1) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $salesInvoices = $this->salesInvoices($numbers, $limit);

        if ($salesInvoices->isEmpty()) {
            $this->info($numbers === []
                ? 'No sales invoices to consider.'
                : 'No sales invoices match those numbers.');

            return self::SUCCESS;
        }

        $this->line($send
            ? sprintf('Delivering %d invoice(s).', $salesInvoices->count())
            : sprintf('Dry run over %d invoice(s). Pass --send to queue them.', $salesInvoices->count()));
        $this->newLine();

        $queued = 0;
        $skipped = 0;

        foreach ($salesInvoices as $salesInvoice) {
            $plan = $ledger->plan($salesInvoice);
            $record = $ledger->find($salesInvoice);

            $this->line(sprintf(
                '  %-10s %-12s %-30s %-12s %-8s %-7s %s',
                $salesInvoice->number,
                $salesInvoice->kind()->label(),
                mb_substr((string) $salesInvoice->customer_name, 0, 28),
                (string) $salesInvoice->order_number,
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
            $ledger->markPending($salesInvoice, $plan->diff->changedFields);

            DeliverSalesInvoiceToWebsite::dispatch($salesInvoice->bc_id);

            $queued++;
        }

        $this->newLine();
        $this->info($send
            ? sprintf('Queued %d invoice(s); %d already up to date.', $queued, $skipped)
            : sprintf('%d invoice(s) would be delivered; %d already up to date.', $queued, $skipped));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $numbers
     * @return Collection<int, SalesInvoice>
     */
    private function salesInvoices(array $numbers, ?int $limit): Collection
    {
        return SalesInvoice::query()
            ->when($numbers !== [], fn ($query) => $query->whereIn('number', $numbers))
            ->orderBy('number')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }
}
