<?php

namespace Tests\Feature\Sync;

use App\BusinessCentral\Import\ProductImporter;
use App\Enums\SyncStatus;
use App\Models\SyncRecord;
use App\Sync\SyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SyncLedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_marking_a_product_pending_creates_a_record_on_the_items_channel(): void
    {
        $result = app(ProductImporter::class)->import($this->row());

        $record = app(SyncLedger::class)->markPending($result->product, $result->changedFields);

        $this->assertSame('items', $record->channel);
        $this->assertSame($result->product->bc_id, $record->bc_id);
        $this->assertSame(SyncStatus::Pending, $record->status);
        $this->assertNotNull($record->dispatched_at);
    }

    public function test_stores_the_changed_fields_on_the_record(): void
    {
        $importer = app(ProductImporter::class);
        $ledger = app(SyncLedger::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['unitPrice'] = 9.5;
        $result = $importer->import($changed);

        $record = $ledger->markPending($result->product, $result->changedFields);

        $this->assertSame(['price'], $record->changed_fields);
    }

    public function test_keeps_one_record_per_channel_and_bc_id(): void
    {
        $result = app(ProductImporter::class)->import($this->row());
        $ledger = app(SyncLedger::class);

        $ledger->markPending($result->product, ['price']);
        $ledger->markPending($result->product, ['inventory']);
        $ledger->markPending($result->product, ['name']);

        $this->assertDatabaseCount('sync_records', 1);
        $this->assertSame(['name'], SyncRecord::query()->sole()->changed_fields);
    }

    public function test_reopens_a_synced_record_when_the_product_changes_again(): void
    {
        $result = app(ProductImporter::class)->import($this->row());
        $ledger = app(SyncLedger::class);

        $record = $ledger->markPending($result->product, ['price']);
        $record->update(['status' => SyncStatus::Synced, 'synced_at' => now()]);

        $reopened = $ledger->markPending($result->product, ['inventory']);

        $this->assertSame(SyncStatus::Pending, $reopened->status);
        $this->assertSame(['inventory'], $reopened->changed_fields);
    }

    public function test_clears_a_previous_error_when_the_record_is_reopened(): void
    {
        $result = app(ProductImporter::class)->import($this->row());
        $ledger = app(SyncLedger::class);

        $record = $ledger->markPending($result->product, ['price']);
        $record->update(['status' => SyncStatus::Failed, 'last_error' => 'boom']);

        $reopened = $ledger->markPending($result->product, ['price']);

        $this->assertSame(SyncStatus::Pending, $reopened->status);
        $this->assertNull($reopened->last_error);
    }

    public function test_produces_a_stable_hash_for_equivalent_product_state(): void
    {
        $importer = app(ProductImporter::class);
        $ledger = app(SyncLedger::class);

        $first = $importer->import($this->row());
        $hash = $ledger->payloadHash($first->product);

        // Same data, different key ordering inside the nested collections.
        $reordered = $this->row();
        $reordered['priceListLines'][0] = array_reverse($reordered['priceListLines'][0], preserve_keys: true);

        $second = $importer->import($reordered);

        $this->assertSame($hash, $ledger->payloadHash($second->product));
    }

    public function test_produces_a_different_hash_when_a_scalar_field_changes(): void
    {
        $importer = app(ProductImporter::class);
        $ledger = app(SyncLedger::class);

        $hash = $ledger->payloadHash($importer->import($this->row())->product);

        $changed = $this->row();
        $changed['unitPrice'] = 9.5;

        $this->assertNotSame($hash, $ledger->payloadHash($importer->import($changed)->product));
    }

    public function test_produces_a_different_hash_when_nested_data_changes(): void
    {
        $importer = app(ProductImporter::class);
        $ledger = app(SyncLedger::class);

        $hash = $ledger->payloadHash($importer->import($this->row())->product);

        $changed = $this->row();
        $changed['stockkeepingUnits'][0]['inventory'] = 7;

        $this->assertNotSame($hash, $ledger->payloadHash($importer->import($changed)->product));
    }

    public function test_records_the_bc_modified_timestamp(): void
    {
        $result = app(ProductImporter::class)->import($this->row());

        $record = app(SyncLedger::class)->markPending($result->product, $result->changedFields);

        $this->assertSame('2026-03-12 11:06:22', $record->bc_modified_at->format('Y-m-d H:i:s'));
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
            // Without a qualifying product group the item would be excluded, and
            // a removal payload carries no field values to change.
            'gppg' => 'FINISHED GOODS',
            'lastModifiedDateTime' => '2026-03-12T11:06:22.503Z',
            'priceListLines' => [['salesCode' => 'RRP', 'unitPrice' => 14.95]],
            'itemAttributes' => [['itemAttributeName' => 'Size', 'itemAttributeValueName' => 'Small']],
            'itemDefaultDimensions' => [['dimensionCode' => 'DEPARTMENT', 'dimensionValueCode' => 'DOG']],
            'stockkeepingUnits' => [['locationCode' => 'BROOKLANDS', 'inventory' => 40]],
        ];
    }
}
