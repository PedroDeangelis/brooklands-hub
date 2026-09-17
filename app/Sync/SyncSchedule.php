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

        // Marketing copy. Unconditional, like campaigns: a description that
        // silently stops updating looks like a working site showing the wrong
        // copy. Incremental, so no --top; a cap would leave the remainder
        // unfetched and the checkpoint unable to advance.
        $marketingTextPageSize = (int) config('sync.import.marketing_text.page_size');

        $entry(
            sprintf('bc:import-item-marketing-text --page-size=%d', $marketingTextPageSize),
            (string) config('sync.import.marketing_text.cron'),
        );

        $entry(
            sprintf('bc:import-item-marketing-text --full --page-size=%d', $marketingTextPageSize),
            (string) config('sync.import.marketing_text.full_cron'),
        );

        // Campaigns are registered unconditionally. There is no enable flag to
        // read, because a promotion that silently stops updating looks like a
        // working site showing the wrong prices.
        $campaignPageSize = (int) config('sync.import.campaigns.page_size');

        $entry(
            sprintf('bc:import-campaigns --page-size=%d', $campaignPageSize),
            (string) config('sync.import.campaigns.cron'),
        );

        $entry(
            sprintf('bc:import-campaigns --full --page-size=%d', $campaignPageSize),
            (string) config('sync.import.campaigns.full_cron'),
        );

        // Customers, and the ship-to sweep that attaches addresses to them.
        // Unconditional, like campaigns.
        $customerPageSize = (int) config('sync.import.customers.page_size');

        $entry(
            sprintf('bc:import-customers --page-size=%d', $customerPageSize),
            (string) config('sync.import.customers.cron'),
        );

        $entry(
            sprintf('bc:import-customers --full --page-size=%d', $customerPageSize),
            (string) config('sync.import.customers.full_cron'),
        );

        $entry(
            sprintf('bc:import-ship-to-addresses --page-size=%d', (int) config('sync.import.ship_to_addresses.page_size')),
            (string) config('sync.import.ship_to_addresses.cron'),
        );

        // Contacts, and the link sweep that attaches customers to them and
        // re-evaluates their website rules. Unconditional, like customers.
        $contactPageSize = (int) config('sync.import.contacts.page_size');

        $entry(
            sprintf('bc:import-contacts --page-size=%d', $contactPageSize),
            (string) config('sync.import.contacts.cron'),
        );

        $entry(
            sprintf('bc:import-contacts --full --page-size=%d', $contactPageSize),
            (string) config('sync.import.contacts.full_cron'),
        );

        $entry(
            sprintf('bc:import-contact-links --page-size=%d', (int) config('sync.import.contact_links.page_size')),
            (string) config('sync.import.contact_links.cron'),
        );

        // Open sales orders. Unconditional, like customers. Runs a tick after
        // customers because an order's delivery waits for its customer.
        $salesOrderPageSize = (int) config('sync.import.sales_orders.page_size');

        $entry(
            sprintf('bc:import-sales-orders --page-size=%d', $salesOrderPageSize),
            (string) config('sync.import.sales_orders.cron'),
        );

        $entry(
            sprintf('bc:import-sales-orders --full --page-size=%d', $salesOrderPageSize),
            (string) config('sync.import.sales_orders.full_cron'),
        );

        // Posted sales invoices. Unconditional, like orders. The full run is
        // weekly: posted invoices never change once written.
        $salesInvoicePageSize = (int) config('sync.import.sales_invoices.page_size');

        $entry(
            sprintf('bc:import-sales-invoices --page-size=%d', $salesInvoicePageSize),
            (string) config('sync.import.sales_invoices.cron'),
        );

        $entry(
            sprintf('bc:import-sales-invoices --full --page-size=%d', $salesInvoicePageSize),
            (string) config('sync.import.sales_invoices.full_cron'),
        );

        // Posted sales credit memos: the same table and website post as
        // invoices, from their own page. Same cadence, same weekly full run.
        $salesCreditMemoPageSize = (int) config('sync.import.sales_credit_memos.page_size');

        $entry(
            sprintf('bc:import-sales-credit-memos --page-size=%d', $salesCreditMemoPageSize),
            (string) config('sync.import.sales_credit_memos.cron'),
        );

        $entry(
            sprintf('bc:import-sales-credit-memos --full --page-size=%d', $salesCreditMemoPageSize),
            (string) config('sync.import.sales_credit_memos.full_cron'),
        );

        // Document attachments: the files hanging off items, customers and
        // sales orders. Unconditional, like the rest. Swept on every run, so
        // there is one entry rather than an incremental and a full pair.
        $entry(
            sprintf('bc:import-document-attachments --page-size=%d', (int) config('sync.import.document_attachments.page_size')),
            (string) config('sync.import.document_attachments.cron'),
        );

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
