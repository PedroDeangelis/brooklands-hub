<?php

namespace App\Console\Commands;

use App\BusinessCentral\BusinessCentralClient;
use App\BusinessCentral\BusinessCentralException;
use App\BusinessCentral\ItemsQuery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bc:test-items')]
#[Description('Fetch a single item from the Business Central itemsExt custom API and print it')]
class TestBusinessCentralItemsCommand extends Command
{
    /**
     * Read-only connectivity check: fetches one item and prints it, storing nothing.
     */
    public function handle(BusinessCentralClient $client): int
    {
        try {
            $response = $client->getCustom(
                ItemsQuery::PUBLISHER,
                ItemsQuery::GROUP,
                ItemsQuery::VERSION,
                ItemsQuery::ENTITY_SET,
                ItemsQuery::forTop(1),
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

        $item = $response['value'][0] ?? null;

        if (! is_array($item)) {
            $this->warn('No items returned from Business Central for the current filter.');

            return self::SUCCESS;
        }

        $this->info('Business Central item:');
        $this->line((string) json_encode(
            $item,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }
}
