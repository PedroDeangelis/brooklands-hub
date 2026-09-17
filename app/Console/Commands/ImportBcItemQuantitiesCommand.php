<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\ItemQuantitiesQuery;
use App\Jobs\ImportBcProductQuantity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bc:import-item-quantities {--top=1 : How many quantity rows to fetch from Business Central}')]
#[Description('Fetch stock figures from Business Central and queue an import job for each one')]
class ImportBcItemQuantitiesCommand extends Command
{
    /**
     * Fetches rows and dispatches one job per row.
     *
     * Normalisation and persistence live in the job, not here, so the same path
     * runs whether a row arrives from this command or a future scheduler.
     */
    public function handle(BusinessCentralClient $client): int
    {
        $top = (int) $this->option('top');

        if ($top < 1) {
            $this->error('--top must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $response = $client->getCustom(
                ItemQuantitiesQuery::PUBLISHER,
                ItemQuantitiesQuery::GROUP,
                ItemQuantitiesQuery::VERSION,
                ItemQuantitiesQuery::ENTITY_SET,
                ItemQuantitiesQuery::forTop($top),
            );
        } catch (BusinessCentralException $e) {
            $this->error($e->getMessage());

            if ($e->url !== null) {
                $this->line("URL: {$e->url}");
            }

            if ($e->bodyExcerpt !== null) {
                $this->line("Response: {$e->bodyExcerpt}");
            }

            return self::FAILURE;
        }

        $rows = $response['value'] ?? [];

        if (! is_array($rows) || $rows === []) {
            $this->warn('No quantity rows returned from Business Central for the current filter.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            ImportBcProductQuantity::dispatch($row);
            $dispatched++;

            $this->line(sprintf(
                'Queued %s (%s)',
                $row['number'] ?? 'unknown SKU',
                $row['id'] ?? 'unknown id',
            ));
        }

        $this->info("Dispatched {$dispatched} import job(s).");

        return self::SUCCESS;
    }
}
