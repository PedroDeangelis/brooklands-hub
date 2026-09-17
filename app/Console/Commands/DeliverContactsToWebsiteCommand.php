<?php

namespace App\Console\Commands;

use App\Contacts\ContactEligibility;
use App\Jobs\DeliverContactToWebsite;
use App\Models\Contact;
use App\Sync\ContactSyncLedger;
use App\Sync\Payload\DeliveryType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opens ledger work for contacts and queues their delivery.
 *
 * The contact counterpart to website:deliver-customers, with one more rule:
 * a contact that does not qualify for the website is reported with its
 * reasons and never queued, whatever else is asked. Delivering a contact
 * creates a user account, and this command is not where the rules bend.
 *
 * Dry run is the default. Pass --send to queue anything.
 */
#[Signature('website:deliver-contacts
    {--send : Actually queue the deliveries (without this the command only reports)}
    {--number=* : Limit to these contact numbers}
    {--limit= : Stop after this many contacts}')]
#[Description('Plan and queue website delivery for contacts')]
class DeliverContactsToWebsiteCommand extends Command
{
    public function handle(ContactSyncLedger $ledger, ContactEligibility $eligibility): int
    {
        $send = (bool) $this->option('send');
        $numbers = array_filter(array_map('trim', (array) $this->option('number')));
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        if ($limit !== null && $limit < 1) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $contacts = $this->contacts($numbers, $limit);

        if ($contacts->isEmpty()) {
            $this->info($numbers === []
                ? 'No contacts to consider.'
                : 'No contacts match those numbers.');

            return self::SUCCESS;
        }

        $this->line($send
            ? sprintf('Delivering %d contact(s).', $contacts->count())
            : sprintf('Dry run over %d contact(s). Pass --send to queue them.', $contacts->count()));
        $this->newLine();

        $queued = 0;
        $skipped = 0;
        $excluded = 0;

        foreach ($contacts as $contact) {
            $result = $eligibility->for($contact);
            $plan = $ledger->plan($contact);
            $record = $ledger->find($contact);

            $this->line(sprintf(
                '  %-12s %-30s %-9s %-8s %s',
                mb_substr($contact->number, 0, 12),
                mb_substr($contact->title(), 0, 28),
                $result->label(),
                $result->eligible ? $plan->type->value : '—',
                $record === null ? 'no ledger row' : 'ledger: '.$record->status->value,
            ));

            if (! $result->eligible) {
                foreach ($result->reasons() as $reason) {
                    $this->line(sprintf('  %-12s   · %s', '', $reason));
                }

                $excluded++;

                continue;
            }

            if ($plan->type === DeliveryType::None) {
                $skipped++;

                continue;
            }

            if (! $send) {
                $queued++;

                continue;
            }

            $ledger->markPending($contact, $plan->diff->changedFields);

            DeliverContactToWebsite::dispatch($contact->bc_id);

            $queued++;
        }

        $this->newLine();
        $this->info($send
            ? sprintf('Queued %d contact(s); %d already up to date; %d excluded.', $queued, $skipped, $excluded)
            : sprintf('%d contact(s) would be delivered; %d already up to date; %d excluded.', $queued, $skipped, $excluded));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $numbers
     * @return Collection<int, Contact>
     */
    private function contacts(array $numbers, ?int $limit): Collection
    {
        return Contact::query()
            ->with('customer')
            ->when($numbers !== [], fn ($query) => $query->whereIn('number', $numbers))
            ->orderBy('number')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }
}
