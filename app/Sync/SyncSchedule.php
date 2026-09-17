<?php

namespace App\Sync;

use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduled sync entries.
 *
 * Two independent stages, deliberately not chained:
 *
 *   scheduler → bc:import-*       → Horizon ImportBcProduct     → Product + ledger
 *   scheduler → website:deliver-* → Horizon DeliverProductToWeb… → WordPress v2
 *
 * Keeping them apart means importing keeps working when the website is
 * unreachable, and delivery can be turned off without stopping imports. The
 * scheduler only ever starts a command; every per-product job runs on Horizon.
 *
 * A class rather than inline calls in routes/console.php so the registration
 * can be exercised against a fresh Schedule in tests, which is the only way to
 * prove that the delivery flag really does prevent the entry existing.
 */
class SyncSchedule
{
    public static function register(Schedule $schedule): void
    {
        $timezone = (string) config('sync.schedule.timezone');
        $overlapMinutes = (int) config('sync.schedule.overlap_minutes');

        /**
         * Register a command with the guards every one of these needs.
         *
         * withoutOverlapping: a slow import must not start a second copy of
         * itself. onOneServer: only one host runs it, should this ever run on
         * more than one. runInBackground: a long import must not delay the
         * entries queued behind it.
         */
        $entry = function (string $command, string $cron) use ($schedule, $timezone, $overlapMinutes): void {
            $schedule->command($command)
                ->cron($cron)
                ->timezone($timezone)
                ->withoutOverlapping($overlapMinutes)
                ->onOneServer()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/schedule.log'));
        };

        if (config('sync.import.items.enabled')) {
            $pageSize = (int) config('sync.import.items.page_size');

            // Incremental: everything changed since the checkpoint, however
            // many pages that takes. No --top, because a total cap would leave
            // the remainder unfetched and the checkpoint unable to advance.
            $entry(
                sprintf('bc:import-items --page-size=%d', $pageSize),
                (string) config('sync.import.items.cron'),
            );

            // Nightly reconciliation over the complete catalogue.
            $entry(
                sprintf('bc:import-items --full --page-size=%d', $pageSize),
                (string) config('sync.import.items.full_cron'),
            );
        }

        if (config('sync.import.quantities.enabled')) {
            $quantityPageSize = (int) config('sync.import.quantities.page_size');

            // No --top: a cap would leave most of the endpoint unread, and this
            // sweep is the only thing that sees stock move.
            $entry(
                sprintf('bc:import-item-quantities --page-size=%d', $quantityPageSize),
                (string) config('sync.import.quantities.cron'),
            );

            $entry(
                sprintf('bc:import-item-quantities --full --page-size=%d', $quantityPageSize),
                (string) config('sync.import.quantities.full_cron'),
            );
        }

        // Registered only when explicitly enabled. Gating registration rather
        // than behaviour means that while delivery is disabled the entry does
        // not exist at all: there is nothing to fire by accident, and
        // `schedule:list` shows plainly that nothing will be delivered.
        //
        // It calls the existing command rather than re-implementing selection,
        // so the dry run someone tests with and the scheduled run agree.
        if (config('sync.delivery.enabled')) {
            $entry(
                sprintf('website:deliver-products --limit=%d', (int) config('sync.delivery.batch_size')),
                (string) config('sync.delivery.cron'),
            );
        }
    }
}
