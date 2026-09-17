<?php

namespace App\Sync\Payload;

use App\Models\Campaign;

/**
 * Builds the payload the website should be given for a campaign.
 *
 * A translation step and nothing more. Every value has already been decided by
 * Business Central and normalised by the importer.
 *
 * Unlike a product, a campaign has no eligibility rule here and is never
 * withdrawn. Laravel's whole responsibility is to mirror Business Central's
 * current campaign state into WordPress; WordPress decides from that state
 * whether the promotion is published, hidden, expired or deleted. That is why
 * `activated` is a field in the payload rather than a reason to stop sending
 * one: a deactivated campaign is still a fact about the campaign, and the
 * website needs to be told it.
 *
 * Payloads are deterministic — the same campaign always produces the same keys
 * in the same shape — so two payloads can be compared or hashed safely. The raw
 * Business Central row is deliberately absent: it carries fields the website has
 * no use for, and any movement inside it would change the hash and re-send an
 * otherwise identical payload.
 *
 * Dates go over as they are. WordPress decides what "future", "published" and
 * "expired" mean, in the site's timezone, because that is where the site's clock
 * and its existing promotion behaviour live.
 */
class CampaignWebsitePayloadBuilder
{
    /**
     * The complete payload for a campaign.
     *
     * "deadline" rather than "ending_date": that is what the promotion post type
     * has always called it, and renaming it here would mean the website had to
     * translate.
     *
     * @return array<string, mixed>
     */
    public function build(Campaign $campaign): array
    {
        return [
            'bc_id' => (string) $campaign->bc_id,
            'code' => (string) $campaign->code,
            'title' => $campaign->title(),
            'start_date' => $campaign->starting_date?->toDateString(),
            'deadline' => $campaign->ending_date?->toDateString(),
            'activated' => (bool) $campaign->activated,
            'customers' => $this->customers($campaign),
        ];
    }

    /**
     * The campaign's audience.
     *
     * Already normalised by the importer — blank-free, de-duplicated and
     * sorted — so this only guards against a column that has never been written.
     * Re-sorting here would hide a normalisation that had stopped working.
     *
     * @return array<int, string>
     */
    private function customers(Campaign $campaign): array
    {
        $customers = $campaign->customers;

        return is_array($customers) ? array_values($customers) : [];
    }
}
