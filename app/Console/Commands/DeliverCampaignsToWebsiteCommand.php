<?php

namespace App\Console\Commands;

use App\Jobs\DeliverCampaignToWebsite;
use App\Models\Campaign;
use App\Sync\CampaignSyncLedger;
use App\Sync\Payload\DeliveryType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opens ledger work for campaigns and queues their delivery.
 *
 * Two jobs in one command, deliberately. A campaign imported before delivery
 * existed has no ledger row at all, and a campaign whose row is already up to
 * date needs nothing; both are answered by rebuilding the plan from current
 * state and acting on what it says. Nothing here decides anything the ledger
 * could not decide on its own.
 *
 * Dry run is the default. Delivering a promotion creates or deletes a public
 * post, so sending has to be asked for explicitly rather than being what
 * happens when someone runs the command to see what it would do.
 */
#[Signature('website:deliver-campaigns
    {--send : Actually queue the deliveries (without this the command only reports)}
    {--code=* : Limit to these campaign codes}
    {--limit= : Stop after this many campaigns}')]
#[Description('Plan and queue website delivery for campaigns')]
class DeliverCampaignsToWebsiteCommand extends Command
{
    public function handle(CampaignSyncLedger $ledger): int
    {
        $send = (bool) $this->option('send');
        $codes = array_filter(array_map('trim', (array) $this->option('code')));
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        if ($limit !== null && $limit < 1) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $campaigns = $this->campaigns($codes, $limit);

        if ($campaigns->isEmpty()) {
            $this->info($codes === []
                ? 'No campaigns to consider.'
                : 'No campaigns match those codes.');

            return self::SUCCESS;
        }

        $this->line($send
            ? sprintf('Delivering %d campaign(s).', $campaigns->count())
            : sprintf('Dry run over %d campaign(s). Pass --send to queue them.', $campaigns->count()));
        $this->newLine();

        $queued = 0;
        $skipped = 0;
        $expired = 0;

        foreach ($campaigns as $campaign) {
            $plan = $ledger->plan($campaign);
            $record = $ledger->find($campaign);

            // A campaign that ended before the website ever held it is not
            // worth publishing: the promotion would never be shown. Reported
            // rather than hidden, so a dry run explains the whole catalogue.
            $stale = $ledger->isStale($campaign, $record);

            $this->line(sprintf(
                '  %-8s %-34s %-8s %-7s %s',
                $campaign->code,
                mb_substr($campaign->title(), 0, 32),
                $plan->action->value,
                $stale ? 'expired' : $plan->type->value,
                $record === null ? 'no ledger row' : 'ledger: '.$record->status->value,
            ));

            if ($stale) {
                $expired++;

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

            // Opening the row is what makes the delivery job find something to
            // do: the job rebuilds the plan itself, but it returns immediately
            // when no ledger row exists.
            $ledger->markPending($campaign, $plan->diff->changedFields);

            DeliverCampaignToWebsite::dispatch($campaign->bc_id);

            $queued++;
        }

        $this->newLine();
        $this->info($send
            ? sprintf('Queued %d campaign(s); %d already up to date.', $queued, $skipped)
            : sprintf('%d campaign(s) would be delivered; %d already up to date.', $queued, $skipped));

        if ($expired > 0) {
            $this->line(sprintf(
                '%d campaign(s) skipped: ended before the website ever held them.',
                $expired,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $codes
     * @return Collection<int, Campaign>
     */
    private function campaigns(array $codes, ?int $limit): Collection
    {
        return Campaign::query()
            ->when($codes !== [], fn ($query) => $query->whereIn('code', $codes))
            ->orderBy('code')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }
}
