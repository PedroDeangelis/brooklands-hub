<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\WebsiteEligibility;
use App\Sync\DeliveryStatus;
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

    private function record(SyncStatus $status): SyncRecord
    {
        return SyncRecord::factory()->make(['status' => $status]);
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

    public function test_an_excluded_product_is_not_applicable(): void
    {
        $product = $this->eligible();
        $product->type = 'Service';

        $this->assertSame(DeliveryStatus::NotApplicable, $this->statusFor($product));
    }

    /**
     * A product that qualified once may carry a ledger row from that time.
     * Eligibility is asked first, so it does not linger as pending forever.
     */
    public function test_eligibility_overrides_a_stale_ledger_row(): void
    {
        $product = $this->eligible();
        $product->blocked = true;

        $this->assertSame(
            DeliveryStatus::NotApplicable,
            $this->statusFor($product, $this->record(SyncStatus::Pending)),
        );
    }

    public function test_only_a_genuine_failure_counts_as_a_problem(): void
    {
        $this->assertTrue(DeliveryStatus::Failed->isProblem());

        foreach ([
            DeliveryStatus::NotApplicable,
            DeliveryStatus::NotSynced,
            DeliveryStatus::Pending,
            DeliveryStatus::Synced,
        ] as $status) {
            $this->assertFalse($status->isProblem(), $status->value.' must not be a problem');
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
