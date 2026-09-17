<?php

namespace Tests\Feature\BusinessCentral\Import;

use App\BusinessCentral\Import\CampaignImporter;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Normalising one Business Central campaign row.
 *
 * The audience is the part that matters most: the website decides which
 * customers see a promotion by matching against it, so a silently wrong list
 * shows the wrong prices to the wrong people without anything looking broken.
 */
class CampaignImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<int, string>  $customers
     * @return array<string, mixed>
     */
    private function row(array $customers = [], mixed $activated = true): array
    {
        return [
            'id' => '5a6b7f77-0321-f111-8340-7ced8d3493eb',
            'code' => 'CP0001',
            'description' => 'Ezydog Harness | 25% Off',
            'startingDate' => '2026-04-07',
            'endingDate' => '2026-05-30',
            'activated' => $activated,
            'lastModifiedDateTime' => '2026-04-16T04:27:03.077Z',
            'campaignCustomers' => array_map(
                fn (string $id): array => ['id' => "link-{$id}", 'customerId' => $id],
                $customers,
            ),
        ];
    }

    private function importer(): CampaignImporter
    {
        return app(CampaignImporter::class);
    }

    public function test_it_stores_a_campaign_from_a_business_central_row(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertTrue($result->created);
        $this->assertSame('CP0001', $result->campaign->code);
        $this->assertSame('Ezydog Harness | 25% Off', $result->campaign->description);
        $this->assertSame('2026-04-07', $result->campaign->starting_date->toDateString());
        $this->assertSame('2026-05-30', $result->campaign->ending_date->toDateString());
        $this->assertTrue($result->campaign->activated);
    }

    public function test_the_bc_id_is_the_identity(): void
    {
        $this->importer()->import($this->row());
        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->created);
        $this->assertSame(1, Campaign::count());
    }

    public function test_a_row_without_an_id_is_refused(): void
    {
        $row = $this->row();
        unset($row['id']);

        $this->expectException(InvalidArgumentException::class);

        $this->importer()->import($row);
    }

    public function test_it_keeps_the_millisecond_precision_business_central_sent(): void
    {
        $result = $this->importer()->import($this->row());

        $this->assertSame(
            '2026-04-16T04:27:03.077Z',
            $result->campaign->fresh()->bc_modified_at->toIso8601ZuluString('millisecond'),
        );
    }

    // ----------------------------------------------------------- the audience

    public function test_the_audience_is_flattened_to_customer_ids(): void
    {
        $result = $this->importer()->import($this->row(['cust-a', 'cust-b']));

        $this->assertSame(['cust-a', 'cust-b'], $result->campaign->customers);
    }

    public function test_the_audience_is_sorted_deterministically(): void
    {
        $result = $this->importer()->import($this->row(['cust-c', 'cust-a', 'cust-b']));

        $this->assertSame(['cust-a', 'cust-b', 'cust-c'], $result->campaign->customers);
    }

    public function test_duplicate_customers_are_collapsed(): void
    {
        $result = $this->importer()->import($this->row(['cust-a', 'cust-b', 'cust-a']));

        $this->assertSame(['cust-a', 'cust-b'], $result->campaign->customers);
    }

    public function test_blank_customers_are_dropped(): void
    {
        $result = $this->importer()->import($this->row(['cust-a', '', '   ']));

        $this->assertSame(['cust-a'], $result->campaign->customers);
    }

    public function test_a_missing_audience_is_an_empty_list(): void
    {
        $row = $this->row();
        unset($row['campaignCustomers']);

        $result = $this->importer()->import($row);

        $this->assertSame([], $result->campaign->customers);
    }

    /**
     * The point of normalising: Business Central is free to return the same
     * audience in any order, and a reorder must not read as a change. Without
     * this the campaign would report an update on every import and, once
     * delivery is wired up, re-send an identical payload forever.
     */
    public function test_the_same_audience_in_a_different_order_is_not_a_change(): void
    {
        $this->importer()->import($this->row(['cust-a', 'cust-b', 'cust-c']));

        $result = $this->importer()->import($this->row(['cust-c', 'cust-a', 'cust-b']));

        $this->assertFalse($result->changed());
        $this->assertSame([], $result->changedFields);
    }

    public function test_a_genuinely_different_audience_is_a_change(): void
    {
        $this->importer()->import($this->row(['cust-a', 'cust-b']));

        $result = $this->importer()->import($this->row(['cust-a', 'cust-b', 'cust-c']));

        $this->assertTrue($result->changed());
        $this->assertContains('customers', $result->changedFields);
    }

    // ------------------------------------------------------- change detection

    public function test_an_unchanged_row_reports_no_change(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row());

        $this->assertFalse($result->changed());
    }

    public function test_a_changed_description_is_reported(): void
    {
        $this->importer()->import($this->row());

        $row = $this->row();
        $row['description'] = 'Ezydog Harness | 30% Off';

        $result = $this->importer()->import($row);

        $this->assertSame(['description'], $result->changedFields);
    }

    public function test_deactivation_is_reported_as_a_change(): void
    {
        $this->importer()->import($this->row());

        $result = $this->importer()->import($this->row([], false));

        $this->assertContains('activated', $result->changedFields);
        $this->assertFalse($result->campaign->activated);
    }

    // ------------------------------------------------------------- coercions

    /**
     * Business Central's activation flag is not reliably a boolean, and
     * anything not recognisably true must read as false: a promotion is shown
     * only when Business Central positively says so.
     */
    public function test_it_coerces_the_activation_flag(): void
    {
        $cases = [
            [true, true],
            [1, true],
            ['1', true],
            ['true', true],
            ['yes', true],
            ['TRUE', true],
            [false, false],
            [0, false],
            [null, false],
            ['', false],
            ['on', false],
        ];

        foreach ($cases as [$value, $expected]) {
            $result = $this->importer()->import($this->row([], $value));

            $this->assertSame(
                $expected,
                $result->campaign->activated,
                sprintf('activated: %s', var_export($value, true)),
            );
        }
    }

    public function test_the_business_central_null_date_becomes_null(): void
    {
        $row = $this->row();
        $row['startingDate'] = '0001-01-01';
        $row['endingDate'] = '';

        $result = $this->importer()->import($row);

        $this->assertNull($result->campaign->starting_date);
        $this->assertNull($result->campaign->ending_date);
    }

    /**
     * Business Central does not guarantee a description, and a promotion with
     * no title at all would be unidentifiable in the WordPress admin.
     */
    public function test_the_title_falls_back_from_description_to_code_to_id(): void
    {
        $row = $this->row();
        $this->assertSame('Ezydog Harness | 25% Off', $this->importer()->import($row)->campaign->title());

        $row['description'] = '';
        $this->assertSame('CP0001', $this->importer()->import($row)->campaign->title());

        $row['code'] = '';
        $this->assertSame(
            '5a6b7f77-0321-f111-8340-7ced8d3493eb',
            $this->importer()->import($row)->campaign->title(),
        );
    }

    public function test_the_whole_row_is_kept_as_the_payload(): void
    {
        $result = $this->importer()->import($this->row(['cust-a']));

        $this->assertSame('CP0001', $result->campaign->bc_payload['code']);
        $this->assertArrayHasKey('campaignCustomers', $result->campaign->bc_payload);
    }
}
