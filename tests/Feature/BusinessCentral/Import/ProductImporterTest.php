<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\ProductImporter;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProductImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_product_from_a_business_central_row(): void
    {
        $product = app(ProductImporter::class)->import($this->row())->product;

        $this->assertDatabaseCount('products', 1);
        $this->assertSame('ab3349b2-3d1c-f111-8341-6045bde65a16', $product->bc_id);
        $this->assertSame('POLY1', $product->sku);
        $this->assertSame('Tropical Fish 1 Poly Bin with Lid', $product->name);
        $this->assertSame('Inventory', $product->type);
        $this->assertSame('2.00000', $product->price);
        $this->assertFalse($product->blocked);
        $this->assertFalse($product->sales_blocked);
    }

    public function test_stores_the_complete_row_including_nested_collections(): void
    {
        $product = app(ProductImporter::class)->import($this->row())->product;

        $this->assertSame($this->row(), $product->bc_payload);
        $this->assertSame(
            'DEPARTMENT',
            $product->bc_payload['itemDefaultDimensions'][0]['dimensionCode'],
        );
    }

    public function test_updates_the_existing_product_when_the_same_bc_id_is_imported_again(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['displayName'] = 'Renamed Bin';
        $changed['unitPrice'] = 9.5;

        $product = $importer->import($changed)->product;

        $this->assertDatabaseCount('products', 1);
        $this->assertSame('Renamed Bin', $product->name);
        $this->assertSame('9.50000', $product->price);
    }

    public function test_treats_the_bc_id_rather_than_the_sku_as_the_identity(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        // Same SKU, different GUID: a genuinely different BC record.
        $other = $this->row();
        $other['id'] = '11111111-2222-3333-4444-555555555555';

        $importer->import($other);

        $this->assertDatabaseCount('products', 2);
    }

    public function test_stores_the_modified_timestamp_as_utc(): void
    {
        $product = app(ProductImporter::class)->import($this->row())->product;

        $this->assertNotNull($product->bc_modified_at);
        $this->assertSame('2026-03-12 11:06:22', $product->bc_modified_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $product->bc_modified_at->timezoneName);
    }

    public function test_does_not_mark_a_reimported_identical_row_as_changed(): void
    {
        // Guards the decimal casts: MySQL returns decimals as strings, so an
        // uncast column would look dirty on every import.
        //
        // Asserted on changedFields rather than getChanges(): MySQL rewrites JSON
        // columns into its own normalised form (keys reordered, spacing added), so
        // the stored bc_payload string always differs from the one just written
        // even when the decoded value is identical. That is why bc_payload is
        // excluded from change detection.
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $result = $importer->import($this->row());

        $this->assertSame([], $result->changedFields);
        $this->assertSame([], array_diff(array_keys($result->product->getChanges()), ['bc_payload']));
    }

    public function test_defaults_missing_optional_fields_rather_than_failing(): void
    {
        $product = app(ProductImporter::class)->import([
            'id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'number' => 'BARE1',
        ])->product;

        $this->assertSame('BARE1', $product->sku);
        $this->assertSame('', $product->name);
        $this->assertSame('0.00000', $product->price);
        $this->assertFalse($product->blocked);
        $this->assertNull($product->bc_modified_at);
    }

    public function test_normalizes_blocked_flags_from_business_central(): void
    {
        $row = $this->row();
        $row['blocked'] = true;
        $row['salesBlocked'] = true;

        $product = app(ProductImporter::class)->import($row)->product;

        $this->assertTrue($product->blocked);
        $this->assertTrue($product->sales_blocked);
    }

    public function test_rejects_a_row_without_a_business_central_id(): void
    {
        $row = $this->row();
        unset($row['id']);

        $this->expectException(InvalidArgumentException::class);

        app(ProductImporter::class)->import($row);
    }

    public function test_does_not_persist_anything_when_the_row_is_rejected(): void
    {
        $row = $this->row();
        unset($row['id']);

        try {
            app(ProductImporter::class)->import($row);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_reports_a_first_import_as_created_with_all_relevant_fields_changed(): void
    {
        $result = app(ProductImporter::class)->import($this->row());

        $this->assertTrue($result->created);
        $this->assertTrue($result->changed());

        foreach (['sku', 'name', 'type', 'price', 'inventory', 'blocked', 'bc_modified_at'] as $field) {
            $this->assertContains($field, $result->changedFields);
        }
    }

    public function test_excludes_the_raw_payload_and_timestamps_from_changed_fields(): void
    {
        // bc_payload mirrors the whole BC row, so including it would make every
        // product look changed whenever any nested value moved.
        $result = app(ProductImporter::class)->import($this->row());

        $this->assertNotContains('bc_payload', $result->changedFields);
        $this->assertNotContains('created_at', $result->changedFields);
        $this->assertNotContains('updated_at', $result->changedFields);
    }

    public function test_reports_no_changed_fields_when_an_identical_row_is_imported_again(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $result = $importer->import($this->row());

        $this->assertFalse($result->created);
        $this->assertSame([], $result->changedFields);
        $this->assertFalse($result->changed());
    }

    public function test_reports_only_the_single_field_that_changed(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['unitPrice'] = 9.5;

        $result = $importer->import($changed);

        $this->assertFalse($result->created);
        $this->assertSame(['price'], $result->changedFields);
        $this->assertTrue($result->hasChanged('price'));
    }

    public function test_reports_only_the_fields_that_changed_when_several_move(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['unitPrice'] = 9.5;
        $changed['inventory'] = 12;

        $result = $importer->import($changed);

        $this->assertFalse($result->created);
        $this->assertSame(['inventory', 'price'], $result->changedFields);
        $this->assertNotContains('name', $result->changedFields);
        $this->assertNotContains('sku', $result->changedFields);
    }

    public function test_detects_a_changed_boolean_flag(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['salesBlocked'] = true;

        $result = $importer->import($changed);

        $this->assertSame(['sales_blocked'], $result->changedFields);
    }

    public function test_detects_a_changed_modified_timestamp(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['lastModifiedDateTime'] = '2026-04-01T08:00:00.000Z';

        $result = $importer->import($changed);

        $this->assertSame(['bc_modified_at'], $result->changedFields);
    }

    public function test_reports_the_nested_section_when_only_nested_payload_data_moves(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['itemDefaultDimensions'][0]['dimensionValueName'] = 'Cat';

        $result = $importer->import($changed);

        $this->assertSame(['item_default_dimensions'], $result->changedFields);
        $this->assertSame('Cat', $result->product->bc_payload['itemDefaultDimensions'][0]['dimensionValueName']);
    }

    public function test_treats_a_numerically_equal_price_as_unchanged(): void
    {
        // BC may send 2 or 2.0 for the same price; the decimal cast must not
        // report that as a change.
        $importer = app(ProductImporter::class);
        $importer->import($this->row());

        $changed = $this->row();
        $changed['unitPrice'] = 2.0;

        $result = $importer->import($changed);

        $this->assertSame([], $result->changedFields);
    }

    public function test_reports_no_nested_changes_when_the_nested_data_is_identical(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $result = $importer->import($this->nestedRow());

        $this->assertSame([], $result->changedFields);
    }

    public function test_detects_a_change_to_price_list_lines(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $changed = $this->nestedRow();
        $changed['priceListLines'][0]['unitPrice'] = 19.95;

        $result = $importer->import($changed);

        $this->assertSame(['price_list_lines'], $result->changedFields);
    }

    public function test_detects_a_change_to_item_attributes(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $changed = $this->nestedRow();
        $changed['itemAttributes'][0]['itemAttributeValueName'] = 'Large';

        $result = $importer->import($changed);

        $this->assertSame(['item_attributes'], $result->changedFields);
    }

    public function test_detects_a_change_to_item_default_dimensions(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $changed = $this->nestedRow();
        $changed['itemDefaultDimensions'][0]['dimensionValueCode'] = 'CAT';

        $result = $importer->import($changed);

        $this->assertSame(['item_default_dimensions'], $result->changedFields);
    }

    public function test_detects_a_change_to_stockkeeping_units(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $changed = $this->nestedRow();
        $changed['stockkeepingUnits'][0]['inventory'] = 7;

        $result = $importer->import($changed);

        $this->assertSame(['stockkeeping_units'], $result->changedFields);
    }

    public function test_ignores_key_ordering_alone_when_comparing_nested_data(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $reordered = $this->nestedRow();
        $reordered['priceListLines'][0] = array_reverse($reordered['priceListLines'][0], preserve_keys: true);
        $reordered['itemAttributes'][0] = array_reverse($reordered['itemAttributes'][0], preserve_keys: true);
        $reordered['stockkeepingUnits'][0] = array_reverse($reordered['stockkeepingUnits'][0], preserve_keys: true);

        $result = $importer->import($reordered);

        $this->assertSame([], $result->changedFields);
    }

    public function test_detects_several_nested_sections_changing_at_once(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $changed = $this->nestedRow();
        $changed['itemDefaultDimensions'][0]['dimensionValueCode'] = 'CAT';
        $changed['stockkeepingUnits'][0]['inventory'] = 7;

        $result = $importer->import($changed);

        $this->assertSame(['item_default_dimensions', 'stockkeeping_units'], $result->changedFields);
    }

    public function test_reports_scalar_and_nested_changes_together(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $changed = $this->nestedRow();
        $changed['unitPrice'] = 44.0;
        $changed['priceListLines'][0]['unitPrice'] = 19.95;

        $result = $importer->import($changed);

        $this->assertSame(['price', 'price_list_lines'], $result->changedFields);
    }

    public function test_detects_a_nested_entry_being_added_or_removed(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $added = $this->nestedRow();
        $added['stockkeepingUnits'][] = ['locationCode' => 'LIVESTOCK', 'inventory' => 3];

        $this->assertSame(['stockkeeping_units'], $importer->import($added)->changedFields);
        $this->assertSame(['stockkeeping_units'], $importer->import($this->nestedRow())->changedFields);
    }

    public function test_reports_populated_nested_sections_on_a_first_import(): void
    {
        $result = app(ProductImporter::class)->import($this->nestedRow());

        $this->assertTrue($result->created);

        foreach (['price_list_lines', 'item_attributes', 'item_default_dimensions', 'stockkeeping_units'] as $section) {
            $this->assertContains($section, $result->changedFields);
        }
    }

    public function test_does_not_report_empty_nested_sections_on_a_first_import(): void
    {
        // The demo row carries no price list lines or attributes.
        $result = app(ProductImporter::class)->import($this->row());

        $this->assertNotContains('price_list_lines', $result->changedFields);
        $this->assertNotContains('item_attributes', $result->changedFields);
        $this->assertNotContains('stockkeeping_units', $result->changedFields);
        $this->assertContains('item_default_dimensions', $result->changedFields);
    }

    public function test_never_reports_the_raw_payload_as_a_changed_field(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->nestedRow());

        $changed = $this->nestedRow();
        $changed['priceListLines'][0]['unitPrice'] = 19.95;

        $result = $importer->import($changed);

        $this->assertNotContains('bc_payload', $result->changedFields);
    }

    /**
     * A row carrying all four nested collections.
     *
     * @return array<string, mixed>
     */
    private function nestedRow(): array
    {
        return array_merge($this->row(), [
            'priceListLines' => [
                [
                    'salesType' => 'Customer Price Group',
                    'salesCode' => 'RRP',
                    'unitPrice' => 14.95,
                    'lineDiscountPercent' => 0,
                ],
            ],
            'itemAttributes' => [
                ['itemAttributeName' => 'Size', 'itemAttributeValueName' => 'Small'],
            ],
            'itemDefaultDimensions' => [
                [
                    'itemNo' => 'POLY1',
                    'dimensionCode' => 'DEPARTMENT',
                    'dimensionValueCode' => 'DOG',
                    'dimensionValueName' => 'Dog',
                ],
            ],
            'stockkeepingUnits' => [
                ['locationCode' => 'BROOKLANDS', 'inventory' => 40],
            ],
        ]);
    }

    /**
     * A trimmed but faithful itemsExt row, taken from a real Sandbox response.
     *
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'number' => 'POLY1',
            'displayName' => 'Tropical Fish 1 Poly Bin with Lid',
            'displayName2' => '',
            'type' => 'Inventory',
            'unitPrice' => 2,
            'blocked' => false,
            'salesBlocked' => false,
            'gtin' => '',
            'inventory' => 0,
            'itemCategoryId' => '',
            'lastModifiedDateTime' => '2026-03-12T11:06:22.503Z',
            'weight' => 0,
            'priceListLines' => [],
            'itemAttributes' => [],
            'itemDefaultDimensions' => [
                [
                    'itemNo' => 'POLY1',
                    'dimensionCode' => 'DEPARTMENT',
                    'dimensionValueCode' => 'DOG',
                    'dimensionValueName' => 'Dog',
                ],
            ],
            'stockkeepingUnits' => [],
        ];
    }
}
