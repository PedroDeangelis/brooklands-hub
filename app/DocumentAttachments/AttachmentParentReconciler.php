<?php

namespace App\DocumentAttachments;

use App\Jobs\DeliverCustomerToWebsite;
use App\Jobs\DeliverProductToWebsite;
use App\Jobs\DeliverSalesOrderToWebsite;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SyncRecord;
use App\Sync\CustomerSyncLedger;
use App\Sync\SalesOrderSyncLedger;
use App\Sync\SyncLedger;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Reopens the delivery a changed attachment list has made necessary.
 *
 * Attachments are never delivered on their own: they are a field of the record
 * they hang off, so a new or deleted file means the parent's payload has moved
 * and the parent is what has to be delivered again.
 *
 * Which parent that is differs per row, and each kind has its own ledger and
 * its own delivery job with its own types. This is the one place that chooses
 * between them, so the import job can reconcile a parent without knowing which
 * of the three it has.
 */
class AttachmentParentReconciler
{
    public function __construct(
        private readonly SyncLedger $products,
        private readonly CustomerSyncLedger $customers,
        private readonly SalesOrderSyncLedger $salesOrders,
    ) {}

    /**
     * Mark a parent as needing delivery, and queue it.
     *
     * Returns the ledger row when work was opened, and null when the website is
     * already being asked for exactly this — reconcile() compares the rebuilt
     * payload, so an attachment that changed nothing the website can see costs
     * no delivery.
     *
     * @param  bool  $force  Reopen the row even when the payload is unchanged.
     *
     * @throws InvalidArgumentException when the parent is not one of the three kinds.
     */
    public function reconcile(Model $parent, bool $force = false): ?SyncRecord
    {
        $changedFields = ['attachments'];

        if ($parent instanceof Product) {
            $record = $this->products->reconcile($parent, $changedFields, force: $force);

            if ($record !== null) {
                DeliverProductToWebsite::dispatch($parent->bc_id);
            }

            return $record;
        }

        if ($parent instanceof Customer) {
            $record = $this->customers->reconcile($parent, $changedFields, force: $force);

            if ($record !== null) {
                DeliverCustomerToWebsite::dispatch($parent->bc_id);
            }

            return $record;
        }

        if ($parent instanceof SalesOrder) {
            $record = $this->salesOrders->reconcile($parent, $changedFields, force: $force);

            if ($record !== null) {
                DeliverSalesOrderToWebsite::dispatch($parent->bc_id);
            }

            return $record;
        }

        throw new InvalidArgumentException(sprintf(
            'No ledger knows how to deliver %s.',
            $parent::class,
        ));
    }
}
