<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\WebsiteEligibility;
use App\Sync\DeliveryStatus;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeliveryStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function eligible(): Product
    {
        return Product::factory()->make([
            'blocked' => false,
            'item_category_id' => '',
            'price' => 10,
            'type' => 'Inventory',
            'gppg' => 'FINISHED GOODS',
        ]);
    }

    private function statusFor(Product $product, ?SyncRecord $record = null): DeliveryStatus
    {
        return DeliveryStatus::for($product, app(WebsiteEligibility::class), $record);
    }

    /**
     * A ledger row. A synced row is only up to date when what was delivered
     * matches what is currently wanted, so those are set together.
     */
    private function record(SyncStatus $status, WebsiteAction $action = WebsiteAction::Upsert): SyncRecord
    {
        $record = SyncRecord::factory()->make([
            'status' => $status,
            'action' => $action,
            'payload_hash' => str_repeat('a', 64),
        ]);

        if ($status === SyncStatus::Synced) {
            $record->delivered_action = $action;
            $record->delivered_hash = $record->payload_hash;
        }

        return $record;
    }

    /**
     * A row that was delivered while the product still qualified, and whose
     * intent has since moved on to removal.
     */
    private function staleSyncedRecord(): SyncRecord
    {
        $record = $this->record(SyncStatus::Synced, WebsiteAction::Upsert);
        $record->action = WebsiteAction::Remove;

        return $record;
    }

    public function test_an_eligible_product_with_no_record_is_not_synced(): void
    {
        $this->assertSame(DeliveryStatus::NotSynced, $this->statusFor($this->eligible()));
    }

    public function test_an_eligible_product_reflects_its_ledger_row(): void
    {
        foreach ([
            [SyncStatus::Pending, DeliveryStatus::Pending],
            [SyncStatus::Synced, DeliveryStatus::Synced],
            [SyncStatus::Failed, DeliveryStatus::Failed],
        ] as [$ledger, $expected]) {
            $this->assertSame($expected, $this->statusFor($this->eligible(), $this->record($ledger)));
        }
    }

    /**
     * Not applicable is narrow: never delivered, and not wanted. Only a product
     * the ledger has never seen can be in that position.
     */
    public function test_an_excluded_product_that_was_never_delivered_is_not_applicable(): void
    {
        $product = $this->eligible();
        $product->type = 'Service';

        $this->assertSame(DeliveryStatus::NotApplicable, $this->statusFor($product));
    }

    /**
     * The case "not applicable" must never swallow: the product was delivered
     * while it qualified, so the website still holds it and a removal is owed.
     */
    public function test_an_excluded_product_awaiting_removal_is_pending_not_hidden(): void
    {
        $product = $this->eligible();
        $product->blocked = true;

        $status = $this->statusFor($product, $this->staleSyncedRecord());

        $this->assertSame(DeliveryStatus::Pending, $status);
        $this->assertNotSame(DeliveryStatus::NotApplicable, $status);
        $this->assertTrue($status->needsDelivery());
    }

    /**
     * The ledger is authoritative once a row exists, so a pending removal is
     * reported as pending rather than being overridden by eligibility.
     */
    public function test_a_pending_row_for_an_excluded_product_stays_pending(): void
    {
        $product = $this->eligible();
        $product->blocked = true;

        $this->assertSame(
            DeliveryStatus::Pending,
            $this->statusFor($product, $this->record(SyncStatus::Pending, WebsiteAction::Remove)),
        );
    }

    /**
     * Once the removal itself has been delivered, the website matches again.
     */
    public function test_a_delivered_removal_is_synced(): void
    {
        $product = $this->eligible();
        $product->blocked = true;

        $this->assertSame(
            DeliveryStatus::Synced,
            $this->statusFor($product, $this->record(SyncStatus::Synced, WebsiteAction::Remove)),
        );
    }

    /**
     * A synced row whose payload has moved on is not up to date either.
     */
    public function test_a_synced_row_with_a_newer_payload_is_pending(): void
    {
        $record = $this->record(SyncStatus::Synced);
        $record->payload_hash = str_repeat('b', 64);

        $this->assertSame(DeliveryStatus::Pending, $this->statusFor($this->eligible(), $record));
    }

    public function test_only_outstanding_states_need_delivery(): void
    {
        $this->assertTrue(DeliveryStatus::Pending->needsDelivery());
        $this->assertTrue(DeliveryStatus::Failed->needsDelivery());
        $this->assertTrue(DeliveryStatus::NotSynced->needsDelivery());
        $this->assertFalse(DeliveryStatus::Synced->needsDelivery());
        $this->assertFalse(DeliveryStatus::NotApplicable->needsDelivery());
    }

    public function test_only_states_needing_attention_count_as_problems(): void
    {
        // Enumerated from cases() so a new status must be classified here
        // rather than defaulting to "not a problem" by being forgotten.
        $problems = [DeliveryStatus::Failed, DeliveryStatus::Conflict];

        foreach (DeliveryStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, $problems, true),
                $status->isProblem(),
                $status->value.' is classified wrongly',
            );
        }
    }

    public function test_every_status_has_a_label_and_an_explanation(): void
    {
        foreach (DeliveryStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertNotSame('', $status->explain());
        }

        $this->assertSame('Not applicable', DeliveryStatus::NotApplicable->label());
    }
}
