<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business Central import
    |--------------------------------------------------------------------------
    |
    | The scheduler only starts these commands; each one fetches rows and
    | dispatches a job per row, so Horizon continues to do the per-product work.
    | Frequencies are configurable because the right cadence depends on how
    | often Business Central actually changes, which differs per environment.
    |
    */

    'import' => [
        'items' => [
            'enabled' => (bool) env('BC_IMPORT_ITEMS_ENABLED', true),

            // Every minute. The run is cheap when nothing has changed — one
            // request that returns no rows — and overlap protection stops a
            // slow run being joined by the next tick.
            'cron' => env('BC_IMPORT_ITEMS_CRON', '* * * * *'),

            // Records per Business Central request, NOT a total. A run keeps
            // paging until the result set is exhausted.
            'page_size' => (int) env('BC_IMPORT_ITEMS_PAGE_SIZE', 200),

            // A nightly re-read of the whole catalogue, which protects against
            // records whose lastModifiedDateTime does not move when it should.
            // Unchanged products cost nothing: the importer sees no difference
            // and opens no delivery work.
            'full_cron' => env('BC_IMPORT_ITEMS_FULL_CRON', '30 2 * * *'),
        ],

        'quantities' => [
            'enabled' => (bool) env('BC_IMPORT_QUANTITIES_ENABLED', true),

            // Every 15 minutes, and every run sweeps the whole endpoint. The
            // quantity page cannot support an incremental fetch — its
            // lastModifiedDateTime tracks the item record rather than stock and
            // is unset on 98% of rows — so sweeping is the only way stock
            // changes are seen at all. See ItemQuantitiesQuery.
            //
            // A sweep is ~29 requests; an unchanged row costs nothing beyond
            // the fetch, because the importer opens no delivery work for it.
            'cron' => env('BC_IMPORT_QUANTITIES_CRON', '*/15 * * * *'),

            'page_size' => (int) env('BC_IMPORT_QUANTITIES_PAGE_SIZE', 200),

            // A nightly sweep as well, offset from the item reconciliation so
            // the two do not compete for Business Central at the same moment.
            'full_cron' => env('BC_IMPORT_QUANTITIES_FULL_CRON', '45 3 * * *'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Website delivery
    |--------------------------------------------------------------------------
    |
    | Disabled by default, and deliberately so. Delivery writes to a live
    | website, and this environment's catalogue comes from a different Business
    | Central instance than the website was built from, so an unattended run
    | would create thousands of products that do not belong there.
    |
    | The flag gates registration of the scheduled entry itself rather than its
    | behaviour: when disabled the schedule simply does not exist, so it cannot
    | fire through a misconfiguration elsewhere. Manual runs of
    | `website:deliver-products` are unaffected and remain the way to test.
    |
    */

    'delivery' => [
        'enabled' => (bool) env('WEBSITE_DELIVERY_ENABLED', false),

        'cron' => env('WEBSITE_DELIVERY_CRON', '*/10 * * * *'),

        // Caps one scheduler tick. Without it, enabling the flag against a
        // large backlog would queue every outstanding delivery at once.
        'batch_size' => (int) env('WEBSITE_DELIVERY_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    */

    'schedule' => [
        'timezone' => env('SYNC_SCHEDULE_TZ', 'Pacific/Auckland'),

        // How long a scheduled command may hold its overlap lock. A slow import
        // must not start a second copy of itself, but a crashed one must not
        // block the next run forever either.
        'overlap_minutes' => (int) env('SYNC_OVERLAP_MINUTES', 120),
    ],

];
