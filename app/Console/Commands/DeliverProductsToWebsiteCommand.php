<?php

namespace App\Console\Commands;

use App\Jobs\DeliverProductToWebsite;
use App\Sync\DeliveryCandidate;
use App\Sync\DeliveryQueue;
use App\Sync\SyncLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Dispatches website deliveries for the products that need one.
 *
 * Deliberately conservative. Every candidate's plan is rebuilt from current
 * Laravel state before anything is dispatched, so a stale ledger status can
 * neither cause a needless delivery nor hide a needed one. Nothing is retried
 * automatically, and a conflict is never re-sent.
 *
 * Written so the scheduler can call it later. It is not scheduled yet: bulk
 * delivery is a decision to be taken deliberately, after the safety options
 * here have been exercised by hand.
 */
#[Signature('website:deliver-products
    {--dry-run : Report what would be dispatched without dispatching anything}
    {--limit= : Dispatch at most this many jobs}
    {--sku=* : Only consider these SKUs}')]
#[Description('Dispatch website delivery jobs for products whose desired state has not been delivered')]
class DeliverProductsToWebsiteCommand extends Command
{
    /**
     * How many example SKUs to show per delivery type.
     */
    private const EXAMPLES = 5;

    public function handle(DeliveryQueue $queue, SyncLedger $ledger): int
    {
        $limit = $this->limit();

        if ($limit === false) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        /** @var array<int, string> $skus */
        $skus = array_values(array_filter(array_map('trim', (array) $this->option('sku'))));

        $candidates = $queue->candidates($skus);

        if ($candidates->isEmpty()) {
            $this->warn($skus === []
                ? 'No products have been imported yet.'
                : 'No products match the given SKUs.');

            return self::SUCCESS;
        }

        $this->summarise($candidates);

        $deliverable = $candidates->filter(
            fn (DeliveryCandidate $candidate): bool => $candidate->isDeliverable(),
        )->values();

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line(sprintf(
                '<comment>Dry run.</comment> %d job(s) would be dispatched%s. Nothing was sent.',
                $limit === null ? $deliverable->count() : min($deliverable->count(), $limit),
                $limit === null ? '' : " (limited to {$limit})",
            ));

            return self::SUCCESS;
        }

        if ($deliverable->isEmpty()) {
            $this->newLine();
            $this->info('Nothing to deliver: the website already holds the desired state.');

            return self::SUCCESS;
        }

        $this->newLine();

        return $this->dispatch($deliverable, $limit, $ledger);
    }

    /**
     * Dispatch a job per deliverable product.
     *
     * The ledger row is opened first so the record reflects the work before the
     * job runs. Two runs cannot duplicate effort: the job holds a per-product
     * lock, and a second run rebuilds the plan and finds nothing owed.
     *
     * @param  Collection<int, DeliveryCandidate>  $deliverable
     */
    private function dispatch(Collection $deliverable, ?int $limit, SyncLedger $ledger): int
    {
        $dispatched = 0;

        foreach ($deliverable as $candidate) {
            if ($limit !== null && $dispatched >= $limit) {
                break;
            }

            $ledger->reconcile($candidate->product);

            DeliverProductToWebsite::dispatch($candidate->product->bc_id);
            $dispatched++;

            $this->line(sprintf(
                '  <info>%s</info> %s',
                str_pad($candidate->type->value, 8),
                $candidate->sku(),
            ));
        }

        $this->newLine();
        $this->info(sprintf('Dispatched %d delivery job(s).', $dispatched));

        $remaining = $deliverable->count() - $dispatched;

        if ($remaining > 0) {
            $this->line(sprintf('%d more still waiting; run again to continue.', $remaining));
        }

        return self::SUCCESS;
    }

    /**
     * Print the counts, with a few example SKUs for each.
     *
     * @param  Collection<int, DeliveryCandidate>  $candidates
     */
    private function summarise(Collection $candidates): void
    {
        $grouped = $candidates->groupBy(
            fn (DeliveryCandidate $candidate): string => $candidate->bucket(),
        );

        // A fixed order so two runs read the same way, with the states worth
        // acting on first and the quiet majority last.
        $order = ['full', 'partial', 'remove', 'conflict', 'failed', 'syncing', 'up to date', 'none'];

        $rows = [];

        foreach ($order as $bucket) {
            $group = $grouped->get($bucket);

            if ($group === null || $group->isEmpty()) {
                continue;
            }

            $examples = $group->take(self::EXAMPLES)
                ->map(fn (DeliveryCandidate $candidate): string => $candidate->sku())
                ->implode(', ');

            if ($group->count() > self::EXAMPLES) {
                $examples .= sprintf(', … (+%d)', $group->count() - self::EXAMPLES);
            }

            $rows[] = [$this->bucketLabel($bucket), number_format($group->count()), $examples];
        }

        $this->table(['Delivery', 'Count', 'Examples'], $rows);
    }

    private function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'full' => 'Full',
            'partial' => 'Partial',
            'remove' => 'Remove',
            'conflict' => 'Conflicts (skipped)',
            'failed' => 'Failed (skipped)',
            'syncing' => 'Syncing (skipped)',
            'up to date', 'none' => 'None',
            default => ucfirst($bucket),
        };
    }

    /**
     * @return int|null|false The limit, null for none, or false when invalid.
     */
    private function limit(): int|null|false
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        return ctype_digit((string) $limit) && (int) $limit > 0 ? (int) $limit : false;
    }
}
