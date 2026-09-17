<?php

namespace App\Console\Commands;

use App\Jobs\DeliverCustomerToWebsite;
use App\Models\Customer;
use App\Sync\CustomerSyncLedger;
use App\Sync\Payload\DeliveryType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opens ledger work for customers and queues their delivery.
 *
 * Two jobs in one command, deliberately. A customer imported before delivery
 * existed has no ledger row at all, and a customer whose row is already up to
 * date needs nothing; both are answered by rebuilding the plan from current
 * state and acting on what it says. Nothing here decides anything the ledger
 * could not decide on its own.
 *
 * Dry run is the default. Delivering a customer post creates or deletes a public
 * post, so sending has to be asked for explicitly rather than being what
 * happens when someone runs the command to see what it would do.
 */
#[Signature('website:deliver-customers
    {--send : Actually queue the deliveries (without this the command only reports)}
    {--number=* : Limit to these customer codes}
    {--limit= : Stop after this many customers}')]
#[Description('Plan and queue website delivery for customers')]
class DeliverCustomersToWebsiteCommand extends Command
{
    public function handle(CustomerSyncLedger $ledger): int
    {
        $send = (bool) $this->option('send');
        $numbers = array_filter(array_map('trim', (array) $this->option('number')));
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        if ($limit !== null && $limit < 1) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $customers = $this->customers($numbers, $limit);

        if ($customers->isEmpty()) {
            $this->info($numbers === []
                ? 'No customers to consider.'
                : 'No customers match those numbers.');

            return self::SUCCESS;
        }

        $this->line($send
            ? sprintf('Delivering %d customer(s).', $customers->count())
            : sprintf('Dry run over %d customer(s). Pass --send to queue them.', $customers->count()));
        $this->newLine();

        $queued = 0;
        $skipped = 0;

        foreach ($customers as $customer) {
            $plan = $ledger->plan($customer);
            $record = $ledger->find($customer);

            $this->line(sprintf(
                '  %-8s %-34s %-8s %-7s %s',
                $customer->number,
                mb_substr($customer->title(), 0, 32),
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
            $ledger->markPending($customer, $plan->diff->changedFields);

            DeliverCustomerToWebsite::dispatch($customer->bc_id);

            $queued++;
        }

        $this->newLine();
        $this->info($send
            ? sprintf('Queued %d customer(s); %d already up to date.', $queued, $skipped)
            : sprintf('%d customer(s) would be delivered; %d already up to date.', $queued, $skipped));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $numbers
     * @return Collection<int, Customer>
     */
    private function customers(array $numbers, ?int $limit): Collection
    {
        return Customer::query()
            ->when($numbers !== [], fn ($query) => $query->whereIn('number', $numbers))
            ->orderBy('number')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }
}
