<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\CustomerImporter;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Normalising one Business Central customer row.
 */
class CustomerImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => '71431cfe-a51d-f111-8340-7ced8d32d199',
            'number' => '3STONEVE',
            'displayName' => '3 Stone Veterinary Services',
            'type' => 'Company',
            'addressLine1' => '372 Bond Road',
            'addressLine2' => '',
            'city' => 'Te Awamutu',
            'state' => '',
            'postalCode' => '3800',
            'country' => '',
            'phoneNumber' => '',
            'email' => '',
            'shipmentMethodCode' => '',
            'shippingLocationCode' => 'LOCAL',
            'blocked' => '_x0020_',
            'lastModifiedDateTime' => '2026-06-15T00:23:21.653Z',
            'customerPriceGroup' => 'LIST PRICE',
            'customerDiscGroup' => 'LIST',
            'salespersonCode' => 'REBECCA LIPP',
        ], $overrides);
    }

    private function importer(): CustomerImporter
    {
        return app(CustomerImporter::class);
    }

    public function test_it_stores_a_customer_from_a_business_central_row(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertTrue($result->created);
        $this->assertSame('3STONEVE', $result->customer->number);
        $this->assertSame('3 Stone Veterinary Services', $result->customer->display_name);
        $this->assertSame('LIST', $result->customer->customer_disc_group);
        $this->assertSame('LIST PRICE', $result->customer->customer_price_group);
        $this->assertSame('REBECCA LIPP', $result->customer->salesperson_code);
    }

    public function test_the_bc_id_is_the_identity(): void
    {
        $this->importer()->import($this->row());
        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->created);
        $this->assertSame(1, Customer::count());
    }

    public function test_a_row_without_an_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->importer()->import($this->row(['id' => '']));
    }

    public function test_it_keeps_the_millisecond_precision_business_central_sent(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertSame(
            '2026-06-15T00:23:21.653Z',
            $result->customer->fresh()->bc_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    /**
     * "blocked" is an enum whose empty member is a space, which OData sends as
     * "_x0020_". Every customer carries it today, and the website's select
     * field spells that state "none".
     */
    public function test_the_odata_encoded_space_means_not_blocked(): void
    {
        $this->assertSame('none', $this->importer()->import($this->row(['blocked' => '_x0020_']))->customer->blocked);
    }

    public function test_an_empty_blocked_value_means_not_blocked(): void
    {
        $this->assertSame('none', $this->importer()->import($this->row(['blocked' => '']))->customer->blocked);
    }

    public function test_a_real_block_is_kept_verbatim(): void
    {
        foreach (['Ship', 'Invoice', 'All'] as $how) {
            $customer = $this->importer()->import($this->row(['id' => fake()->uuid(), 'blocked' => $how]))->customer;
            $this->assertSame($how, $customer->blocked);
        }
    }

    public function test_an_unchanged_row_reports_no_change(): void
    {
        $this->importer()->import($this->row());

        $this->assertFalse($this->importer()->import($this->row())->changed());
    }

    public function test_a_changed_field_is_reported(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row(['phoneNumber' => '06 123 4567']));

        $this->assertSame(['phone'], $result->changedFields);
    }

    /**
     * Ship-to addresses arrive from their own endpoint. A customer re-import
     * must leave the ones already attached exactly as they are.
     */
    public function test_a_reimport_does_not_touch_shipping_addresses(): void
    {
        $customer = $this->importer()->import($this->row())->customer;
        $customer->update(['shipping_addresses' => [['bc_id' => 'a', 'code' => 'MAIN']]]);

        $result = $this->importer()->import($this->row(['city' => 'Hamilton']));

        // assertEquals, not assertSame: MySQL normalises JSON object key order on
        // storage, so the round-tripped array is the same data in a different
        // order. The app compares through Canonical, which sorts keys first.
        $this->assertEquals([['bc_id' => 'a', 'code' => 'MAIN']], $result->customer->fresh()->shipping_addresses);
        $this->assertSame(['city'], $result->changedFields);
    }

    public function test_the_title_falls_back_from_name_to_number_to_id(): void
    {
        $row = $this->row();
        $this->assertSame('3 Stone Veterinary Services', $this->importer()->import($row)->customer->title());

        $row['displayName'] = '';
        $this->assertSame('3STONEVE', $this->importer()->import($row)->customer->title());

        $row['number'] = '';
        $this->assertSame('71431cfe-a51d-f111-8340-7ced8d32d199', $this->importer()->import($row)->customer->title());
    }
}
