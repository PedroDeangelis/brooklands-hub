<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\ShipToAddressImporter;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Attaching a Business Central ship-to address to its customer.
 *
 * The list is delivered whole and compared whole, so the normalisation here is
 * what decides whether a customer looks changed. A reorder from Business
 * Central must not; a genuinely new or moved address must.
 */
class ShipToAddressImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['number' => 'BROOKLAN']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => '8c56d70d-3b3c-f111-bec4-00224810e61c',
            'customerNo' => 'BROOKLAN',
            'code' => 'SHIPBROOK',
            'name' => 'Brooklands Staff Sales',
            'address' => '21 McGiven Drive',
            'address2' => '',
            'city' => 'New Plymouth',
            'postCode' => '4371',
            'countryCode' => 'NZ',
            'county' => 'Taranaki',
            'isRural' => false,
            'phoneNo' => '',
            'lastModifiedDateTime' => '2026-04-19T22:00:32.733Z',
        ], $overrides);
    }

    private function importer(): ShipToAddressImporter
    {
        return app(ShipToAddressImporter::class);
    }

    public function test_it_attaches_the_address_to_the_customer_by_number(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->skipped);
        $this->assertTrue($result->changed);

        $addresses = $this->customer->fresh()->shippingAddresses();
        $this->assertCount(1, $addresses);
        $this->assertSame('SHIPBROOK', $addresses[0]['code']);
        $this->assertSame('21 McGiven Drive', $addresses[0]['address_1']);
        $this->assertSame('north-island', $addresses[0]['region']);
        $this->assertFalse($addresses[0]['is_rural']);
    }

    /**
     * No customer, no home for the address. Skipped rather than stored as an
     * orphan, and left for the next sweep to retry once the customer lands.
     */
    public function test_a_row_for_an_unknown_customer_is_skipped(): void
    {
        $result = $this->importer()->import($this->row(['customerNo' => 'NOBODY']));

        $this->assertTrue($result->skipped);
        $this->assertSame([], $this->customer->fresh()->shippingAddresses());
    }

    public function test_rows_without_an_id_or_customer_number_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->importer()->import($this->row(['customerNo' => '']));
    }

    public function test_the_same_address_again_is_not_a_change(): void
    {
        $this->importer()->import($this->row());

        $this->assertFalse($this->importer()->import($this->row())->changed);
    }

    public function test_a_second_address_is_added_not_merged(): void
    {
        $this->importer()->import($this->row());
        $this->importer()->import($this->row(['id' => 'second', 'code' => 'WAREHOUSE', 'city' => 'Hamilton']));

        $this->assertCount(2, $this->customer->fresh()->shippingAddresses());
    }

    public function test_an_address_with_the_same_id_is_replaced_whole(): void
    {
        $this->importer()->import($this->row(['phoneNo' => '06 111']));
        $this->importer()->import($this->row(['phoneNo' => '']));

        $addresses = $this->customer->fresh()->shippingAddresses();

        $this->assertCount(1, $addresses);
        // Replaced, not merged: the blank phone won, it did not keep the old one.
        $this->assertSame('', $addresses[0]['phone']);
    }

    /**
     * The point of normalising: the list is sorted, so arrival order from
     * Business Central cannot make a customer look changed.
     */
    public function test_arrival_order_does_not_change_the_list(): void
    {
        $a = $this->row(['id' => 'id-a', 'code' => 'ALPHA']);
        $b = $this->row(['id' => 'id-b', 'code' => 'BRAVO']);

        $this->importer()->import($b);
        $this->importer()->import($a);
        $first = $this->customer->fresh()->shippingAddresses();

        $this->customer->update(['shipping_addresses' => []]);

        $this->importer()->import($a);
        $this->importer()->import($b);
        $second = $this->customer->fresh()->shippingAddresses();

        $this->assertSame($first, $second);
        $this->assertSame(['ALPHA', 'BRAVO'], array_column($second, 'code'));
    }

    public function test_south_island_counties_map_to_the_south_island_region(): void
    {
        foreach (['Canterbury', 'otago', 'West Coast', 'Southland'] as $county) {
            $address = $this->importer()->normalizeAddress($this->row(['county' => $county]));
            $this->assertSame('south-island', $address['region'], $county);
        }

        foreach (['Taranaki', 'Auckland', '', 'Nowhere'] as $county) {
            $address = $this->importer()->normalizeAddress($this->row(['county' => $county]));
            $this->assertSame('north-island', $address['region'], $county);
        }
    }

    public function test_is_rural_is_a_boolean(): void
    {
        $this->assertTrue($this->importer()->normalizeAddress($this->row(['isRural' => true]))['is_rural']);
        $this->assertTrue($this->importer()->normalizeAddress($this->row(['isRural' => 'true']))['is_rural']);
        $this->assertFalse($this->importer()->normalizeAddress($this->row(['isRural' => false]))['is_rural']);
    }

    // ---------------------------------------------------------- replace()

    /**
     * The only path that can remove an address: the list becomes exactly the
     * rows given, so one deleted in Business Central is dropped here.
     */
    public function test_replace_drops_addresses_no_longer_present(): void
    {
        $this->importer()->import($this->row(['id' => 'keep', 'code' => 'KEEP']));
        $this->importer()->import($this->row(['id' => 'gone', 'code' => 'GONE']));

        $result = $this->importer()->replace('BROOKLAN', [$this->row(['id' => 'keep', 'code' => 'KEEP'])]);

        $this->assertTrue($result->changed);
        $this->assertSame(['KEEP'], array_column($this->customer->fresh()->shippingAddresses(), 'code'));
    }

    public function test_replace_with_no_rows_clears_the_list(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->replace('BROOKLAN', []);

        $this->assertTrue($result->changed);
        $this->assertSame([], $this->customer->fresh()->shippingAddresses());
    }

    public function test_replace_with_the_same_rows_is_not_a_change(): void
    {
        $this->importer()->replace('BROOKLAN', [$this->row()]);

        $this->assertFalse($this->importer()->replace('BROOKLAN', [$this->row()])->changed);
    }

    public function test_replace_for_an_unknown_customer_is_skipped(): void
    {
        $this->assertTrue($this->importer()->replace('NOBODY', [$this->row()])->skipped);
    }
}
