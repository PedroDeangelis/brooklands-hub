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

        // Marketing copy. No 'enabled' key, for the reason given on campaigns.
        //
        // Incremental, unlike quantities: this page's lastModifiedDateTime is
        // genuine, so a "changed since" fetch sees every edit. See
        // ItemMarketingTextQuery.
        'marketing_text' => [
            // Every five minutes. All five residues of a five-minute cycle
            // are already taken, so this shares its minutes with the sales
            // order run. That is a safe neighbour: the copy is edited by hand
            // and changes rarely, so an incremental run almost always returns
            // nothing and costs one request. Five minutes is well inside the
            // window that matters for a description going live.
            'cron' => env('BC_IMPORT_MARKETING_TEXT_CRON', '8-59/5 * * * *'),

            'page_size' => (int) env('BC_IMPORT_MARKETING_TEXT_PAGE_SIZE', 200),

            // A nightly re-read of every row, offset from the other
            // reconciliations. The set is small — tens of rows, not thousands —
            // so a full run is a handful of requests.
            'full_cron' => env('BC_IMPORT_MARKETING_TEXT_FULL_CRON', '5 3 * * *'),
        ],

        // Business Central calls these Campaigns; the website calls them
        // Promotions.
        //
        // Deliberately carries no 'enabled' key. Products and quantities have
        // one for historical reasons, but a sync that can be switched off by
        // configuration is a sync that can be switched off by accident, and a
        // promotion that silently stops updating is not visibly broken until
        // someone notices the website is wrong.
        'campaigns' => [
            // Every five minutes. There are ten campaigns and their dates move
            // in weeks, so a tighter schedule would buy nothing; five minutes
            // is well inside the window that matters for a promotion going
            // live.
            'cron' => env('BC_IMPORT_CAMPAIGNS_CRON', '*/5 * * * *'),

            'page_size' => (int) env('BC_IMPORT_CAMPAIGNS_PAGE_SIZE', 200),

            // A nightly re-read, offset from the item and quantity
            // reconciliations so the three do not compete for Business Central
            // at the same moment.
            'full_cron' => env('BC_IMPORT_CAMPAIGNS_FULL_CRON', '15 3 * * *'),
        ],

        // No 'enabled' key, for the reason given on campaigns.
        'customers' => [
            // Every five minutes, offset from campaigns so the two never share
            // a tick. Contacts depend on customers, so this runs ahead of them.
            'cron' => env('BC_IMPORT_CUSTOMERS_CRON', '2-59/5 * * * *'),

            'page_size' => (int) env('BC_IMPORT_CUSTOMERS_PAGE_SIZE', 200),

            'full_cron' => env('BC_IMPORT_CUSTOMERS_FULL_CRON', '0 3 * * *'),
        ],

        // Ship-to addresses are swept, never incremental (see
        // ShipToAddressesQuery), so there is no full_cron: every run is full.
        'ship_to_addresses' => [
            // Every 15 minutes, offset from quantities.
            'cron' => env('BC_IMPORT_SHIP_TO_ADDRESSES_CRON', '7-59/15 * * * *'),

            'page_size' => (int) env('BC_IMPORT_SHIP_TO_ADDRESSES_PAGE_SIZE', 200),
        ],

        // Person contacts. No 'enabled' key, for the reason given on campaigns.
        'contacts' => [
            // Every five minutes, offset from campaigns and customers so no
            // two share a tick. Runs after customers, which contacts depend on.
            'cron' => env('BC_IMPORT_CONTACTS_CRON', '4-59/5 * * * *'),

            'page_size' => (int) env('BC_IMPORT_CONTACTS_PAGE_SIZE', 200),

            'full_cron' => env('BC_IMPORT_CONTACTS_FULL_CRON', '20 3 * * *'),
        ],

        // The contact → customer links are swept, never incremental (see
        // CustomerContactsQuery), so there is no full_cron: every run is full.
        // The sweep is also what re-evaluates every contact's website rules,
        // so it is the cadence at which a contact whose customer has just
        // arrived becomes eligible.
        'contact_links' => [
            // Every 15 minutes, offset from quantities and ship-tos.
            'cron' => env('BC_IMPORT_CONTACT_LINKS_CRON', '11-59/15 * * * *'),

            'page_size' => (int) env('BC_IMPORT_CONTACT_LINKS_PAGE_SIZE', 200),
        ],

        // Open sales orders. No 'enabled' key, for the reason given on
        // campaigns: an order that silently stops updating looks like a
        // shipment that never happened.
        'sales_orders' => [
            // Every five minutes, one tick after customers, which an order's
            // delivery waits for. A shipment or invoice posting shows up on
            // the customer's account within the tick.
            'cron' => env('BC_IMPORT_SALES_ORDERS_CRON', '3-59/5 * * * *'),

            'page_size' => (int) env('BC_IMPORT_SALES_ORDERS_PAGE_SIZE', 200),

            // A nightly re-read of every open order, offset from the other
            // reconciliations. Each order costs three further requests for
            // its documents, so this stays nightly rather than hourly; only
            // open orders exist in Business Central, so the set is bounded.
            'full_cron' => env('BC_IMPORT_SALES_ORDERS_FULL_CRON', '50 3 * * *'),
        ],

        // Posted sales invoices. No 'enabled' key, for the reason given on
        // campaigns.
        'sales_invoices' => [
            // Every five minutes, in the slot between campaigns and customers.
            // An invoice waits for its customer to reach the website, which
            // was almost always delivered long before the invoice was posted.
            'cron' => env('BC_IMPORT_SALES_INVOICES_CRON', '1-59/5 * * * *'),

            'page_size' => (int) env('BC_IMPORT_SALES_INVOICES_PAGE_SIZE', 200),

            // Weekly, not nightly. A posted invoice is immutable and is never
            // deleted, so the page only grows and a full re-read costs one
            // extra request per invoice for lines that cannot have changed.
            // Once a week is enough to catch a row whose timestamp did not
            // move when it should have.
            'full_cron' => env('BC_IMPORT_SALES_INVOICES_FULL_CRON', '10 4 * * 0'),
        ],

        // Document attachments: the files hanging off items, customers and
        // sales orders. No 'enabled' key, for the reason given on campaigns.
        //
        // Swept, never incremental (see DocumentAttachmentsQuery), so there is
        // no full_cron: every run is full.
        'document_attachments' => [
            // Every 15 minutes, offset from the other sweeps so no two share a
            // tick. Files are added by hand and rarely, so a tighter schedule
            // would buy nothing, and each run re-reads the whole endpoint.
            'cron' => env('BC_IMPORT_DOCUMENT_ATTACHMENTS_CRON', '13-59/15 * * * *'),

            'page_size' => (int) env('BC_IMPORT_DOCUMENT_ATTACHMENTS_PAGE_SIZE', 200),
        ],

        // Posted sales credit memos. They land in the same table and the same
        // website post type as invoices, but come from their own Business
        // Central page, so they have their own fetch and checkpoint.
        'sales_credit_memos' => [
            // Every five minutes. All five residues of a five-minute cycle
            // are already taken, so this shares its minutes with the invoice
            // run on every tick except :01. That is the cheapest neighbour:
            // both are near-empty incremental reads of immutable pages.
            'cron' => env('BC_IMPORT_SALES_CREDIT_MEMOS_CRON', '6-59/5 * * * *'),

            'page_size' => (int) env('BC_IMPORT_SALES_CREDIT_MEMOS_PAGE_SIZE', 200),

            // Weekly, after the invoice full run, for the reason given there.
            'full_cron' => env('BC_IMPORT_SALES_CREDIT_MEMOS_FULL_CRON', '25 4 * * 0'),
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

        // The website's own clock. A payload carries final website values,
        // and where the website stores a moment as text (an order's "last
        // updated"), that text is written in this zone before it is sent.
        'timezone' => env('WEBSITE_TIMEZONE', 'Pacific/Auckland'),
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
