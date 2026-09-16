<?php

namespace Tests\Feature\Jobs;

use App\Enums\SyncStatus;
use App\Jobs\ImportBcProduct;
use App\Models\Product;
use App\Models\SyncRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class ImportBcProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_saves_the_product_when_the_job_runs(): void
    {
        $this->runJob($this->row());

        $this->assertDatabaseHas('products', [
            'bc_id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'sku' => 'POLY1',
            'name' => 'Tropical Fish 1 Poly Bin with Lid',
        ]);
    }

    public function test_running_the_job_twice_updates_rather_than_duplicates(): void
    {
        $this->runJob($this->row());

        $changed = $this->row();
        $changed['displayName'] = 'Renamed Bin';
        $this->runJob($changed);

        $this->assertDatabaseCount('products', 1);
        $this->assertSame('Renamed Bin', Product::query()->sole()->name);
    }

    public function test_logs_the_changed_fields_when_the_job_reimports_a_product(): void
    {
        Log::spy();

        $this->runJob($this->row());

        $changed = $this->row();
        $changed['unitPrice'] = 9.5;
        $this->runJob($changed);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'bc.product.imported'
                    && $context['created'] === false
                    && $context['changed_fields'] === ['price'];
            })
            ->once();
    }

    public function test_logs_the_first_import_as_created(): void
    {
        Log::spy();

        $this->runJob($this->row());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'bc.product.imported' && $context['created'] === true;
            })
            ->once();
    }

    public function test_retries_transient_failures_a_bounded_number_of_times(): void
    {
        $job = new ImportBcProduct($this->row());

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff);
    }

    public function test_tags_the_job_with_the_bc_id_and_sku_for_horizon(): void
    {
        $tags = (new ImportBcProduct($this->row()))->tags();

        $this->assertContains('bc-product', $tags);
        $this->assertContains('bc:ab3349b2-3d1c-f111-8341-6045bde65a16', $tags);
        $this->assertContains('sku:POLY1', $tags);
    }

    public function test_fails_without_saving_when_the_row_has_no_bc_id(): void
    {
        $row = $this->row();
        unset($row['id']);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->runJob($row);
        } finally {
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_first_import_creates_a_pending_sync_record(): void
    {
        $this->runJob($this->row());

        $this->assertDatabaseCount('sync_records', 1);

        $record = SyncRecord::query()->sole();
        $this->assertSame('items', $record->channel);
        $this->assertSame('ab3349b2-3d1c-f111-8341-6045bde65a16', $record->bc_id);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertNotNull($record->payload_hash);
    }

    public function test_identical_reimport_does_not_create_a_second_sync_record(): void
    {
        $this->runJob($this->row());
        $this->runJob($this->row());

        $this->assertDatabaseCount('sync_records', 1);
    }

    public function test_identical_reimport_does_not_reset_an_already_synced_record(): void
    {
        $this->runJob($this->row());

        SyncRecord::query()->sole()->update([
            'status' => SyncStatus::Synced,
            'synced_at' => now(),
        ]);

        $this->runJob($this->row());

        $record = SyncRecord::query()->sole();
        $this->assertSame(SyncStatus::Synced, $record->status);
        $this->assertNotNull($record->synced_at);
    }

    public function test_a_changed_product_sets_the_record_back_to_pending(): void
    {
        $this->runJob($this->row());
        SyncRecord::query()->sole()->update(['status' => SyncStatus::Synced, 'synced_at' => now()]);

        $changed = $this->row();
        $changed['unitPrice'] = 9.5;
        $this->runJob($changed);

        $record = SyncRecord::query()->sole();
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertSame(['price'], $record->changed_fields);
    }

    public function test_stores_nested_changed_sections_on_the_sync_record(): void
    {
        $this->runJob($this->row());

        $changed = $this->row();
        $changed['itemDefaultDimensions'][0]['dimensionCode'] = 'CATEGORY';
        $this->runJob($changed);

        $this->assertSame(['item_default_dimensions'], SyncRecord::query()->sole()->changed_fields);
    }

    public function test_updates_the_payload_hash_when_the_product_changes(): void
    {
        $this->runJob($this->row());
        $before = SyncRecord::query()->sole()->payload_hash;

        $changed = $this->row();
        $changed['unitPrice'] = 9.5;
        $this->runJob($changed);

        $this->assertNotSame($before, SyncRecord::query()->sole()->payload_hash);
    }

    public function test_keeps_the_payload_hash_stable_across_an_identical_reimport(): void
    {
        $this->runJob($this->row());
        $before = SyncRecord::query()->sole()->payload_hash;

        $this->runJob($this->row());

        $this->assertSame($before, SyncRecord::query()->sole()->payload_hash);
    }

    public function test_creates_separate_sync_records_for_different_products(): void
    {
        $this->runJob($this->row());

        $other = $this->row();
        $other['id'] = '11111111-2222-3333-4444-555555555555';
        $other['number'] = 'OTHER1';
        $this->runJob($other);

        $this->assertDatabaseCount('sync_records', 2);
    }

    public function test_does_not_write_a_sync_record_when_the_row_is_rejected(): void
    {
        $row = $this->row();
        unset($row['id']);

        try {
            $this->runJob($row);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('sync_records', 0);
    }

    /**
     * Run the job through the container so its dependencies are resolved.
     *
     * @param  array<string, mixed>  $row
     */
    private function runJob(array $row): void
    {
        $this->app->call([new ImportBcProduct($row), 'handle']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'number' => 'POLY1',
            'displayName' => 'Tropical Fish 1 Poly Bin with Lid',
            'type' => 'Inventory',
            'unitPrice' => 2,
            'lastModifiedDateTime' => '2026-03-12T11:06:22.503Z',
            'itemDefaultDimensions' => [['dimensionCode' => 'DEPARTMENT']],
        ];
    }
}
