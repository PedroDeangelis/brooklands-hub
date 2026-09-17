<?php

namespace App\Jobs;

use App\BusinessCentral\Import\CampaignImporter;
use App\Sync\CampaignSyncLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Normalises one Business Central campaign row and stores it as a Campaign.
 *
 * Business Central calls these Campaigns; the website calls them Promotions.
 *
 * The job carries the raw BC row for the same reason the item import does:
 * there is no local record to read until this job creates one.
 *
 * Only genuinely new work is delivered: the ledger returns null when it already
 * wants exactly this, which is what stops an unchanged re-import queueing a
 * delivery on every pass.
 */
class ImportBcCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $row  A raw campaignsExt row from Business Central.
     * @param  bool  $force  Deliver even when nothing has changed, for a deliberate resync.
     */
    public function __construct(
        public readonly array $row,
        public readonly bool $force = false,
    ) {}

    public function handle(CampaignImporter $importer, CampaignSyncLedger $ledger): void
    {
        $result = $importer->import($this->row);
        $campaign = $result->campaign;

        // The ledger decides whether this amounts to new work. It compares both
        // halves of the intent, so a campaign that has only been deactivated is
        // queued for removal even though no Business Central field moved.
        $record = $ledger->reconcile($campaign, $result->changedFields, force: $this->force);

        if ($record !== null) {
            DeliverCampaignToWebsite::dispatch($campaign->bc_id);
        }

        Log::info('bc.campaign.imported', [
            'bc_id' => $campaign->bc_id,
            'code' => $campaign->code,
            'campaign_id' => $campaign->id,
            'created' => $result->created,
            'changed_fields' => $result->changedFields,
            'activated' => $campaign->activated,
            'customers' => count($campaign->customers ?? []),
            'forced' => $this->force,
            'marked_pending' => $record !== null,
            'website_action' => $record?->action->value,
        ]);
    }
}
