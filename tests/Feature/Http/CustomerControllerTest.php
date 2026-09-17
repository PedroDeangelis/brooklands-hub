<?php

namespace Tests\Feature\Http;

use App\Customers\CustomerFilter;
use App\Customers\CustomerStatus;
use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Sync\CustomerSyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The customer list and detail pages.
 *
 * The status column is derived rather than stored, and the filter reproduces
 * that derivation in SQL. The two must agree, or a filter would hide rows whose
 * badge says they should be there — so both are asserted against the same
 * fixtures.
 */
class CustomerControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = 'https://website.test/wp-json/horizon/v2/products';

    private const STATUS_ENDPOINT = 'https://website.test/wp-json/horizon/v2/status';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.website', [
            'url' => self::ENDPOINT,
            'secret' => 'shared-secret-for-tests',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        // Deliberately no default stub here. A second Http::fake() does not
        // override the first, so faking "holds nothing" in setUp() would make
        // every test that says otherwise silently read as missing.
    }

    /**
     * Fake the website's answer about which records it holds.
     *
     * Call once per test, before the request.
     *
     * @param  array<string, array{wp_id: int, status: string}>  $records
     */
    private function websiteHolds(array $records): void
    {
        Http::fake([
            self::STATUS_ENDPOINT => Http::response(['ok' => true, 'records' => $records], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes);
    }

    private function active(): Customer
    {
        return $this->customer(['number' => 'C-ACTIVE', 'blocked' => 'none']);
    }

    private function blocked(): Customer
    {
        return $this->customer(['number' => 'C-BLOCKED', 'blocked' => 'All']);
    }

    // ------------------------------------------------------------- the list

    public function test_the_list_renders(): void
    {
        $this->active();

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Customers')
            ->assertSee('C-ACTIVE');
    }

    public function test_the_list_shows_every_customer_whatever_its_state(): void
    {
        $this->active();
        $this->blocked();

        $this->get(route('customers.index'))->assertOk()->assertSee('C-ACTIVE')->assertSee('C-BLOCKED');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('No customers have been imported yet.');
    }

    public function test_the_ship_to_count_is_shown(): void
    {
        $this->customer(['number' => 'CUST0001', 'shipping_addresses' => [['bc_id' => 'a', 'number' => 'X'], ['bc_id' => 'b', 'number' => 'Y']]]);

        $this->get(route('customers.index'))->assertOk()->assertSee('CUST0001');
    }

    // ----------------------------------------------------- derived status

    public function test_status_is_derived_from_the_block_flag(): void
    {
        $this->assertSame(CustomerStatus::Active, CustomerStatus::for($this->active()));
        $this->assertSame(CustomerStatus::Blocked, CustomerStatus::for($this->blocked()));
    }

    public function test_any_block_kind_reads_as_blocked(): void
    {
        foreach (['Ship', 'Invoice', 'All'] as $how) {
            $customer = $this->customer(['number' => "C-{$how}", 'blocked' => $how]);
            $this->assertSame(CustomerStatus::Blocked, CustomerStatus::for($customer), $how);
        }
    }

    // ---------------------------------------------------------- filtering

    /**
     * The SQL filter and the derived badge must select the same customers, or a
     * filter would hide a row whose own badge says it belongs.
     */
    public function test_each_status_filter_matches_the_derived_status(): void
    {
        $cases = [
            [CustomerStatus::Active, $this->active()],
            [CustomerStatus::Blocked, $this->blocked()],
        ];

        foreach ($cases as [$status, $expected]) {
            // The badge and the filter must agree about this customer.
            $this->assertSame($status, CustomerStatus::for($expected));

            $response = $this->get(route('customers.index', [
                CustomerFilter::PARAM_STATUS => $status->value,
            ]))->assertOk();

            $response->assertSee($expected->number);

            foreach ($cases as [, $other]) {
                if ($other->number !== $expected->number) {
                    $response->assertDontSee($other->number);
                }
            }
        }
    }

    public function test_the_search_filter_matches_number_name_or_email(): void
    {
        $this->customer(['number' => 'CUST0001', 'display_name' => 'Three Stone Vets', 'email' => 'vets@example.test']);
        $this->customer(['number' => 'CUST0002', 'display_name' => 'Four Paws', 'email' => 'paws@example.test']);

        $this->get(route('customers.index', [CustomerFilter::PARAM_SEARCH => 'Stone']))->assertOk()->assertSee('CUST0001')->assertDontSee('CUST0002');
        $this->get(route('customers.index', [CustomerFilter::PARAM_SEARCH => 'paws@']))->assertOk()->assertSee('CUST0002')->assertDontSee('CUST0001');
    }

    public function test_the_not_synced_filter_finds_customers_with_no_ledger_row(): void
    {
        $delivered = $this->customer(['number' => 'C-SENT']);
        $this->customer(['number' => 'C-NEW']);

        app(CustomerSyncLedger::class)->markPending($delivered, []);

        $this->get(route('customers.index', [
            CustomerFilter::PARAM_SYNC => CustomerFilter::SYNC_NOT_SYNCED,
        ]))
            ->assertOk()
            ->assertSee('C-NEW')
            ->assertDontSee('C-SENT');
    }

    public function test_a_ledger_status_filter_reads_the_ledger(): void
    {
        $customer = $this->customer(['number' => 'C-PENDING']);
        app(CustomerSyncLedger::class)->markPending($customer, []);

        $this->get(route('customers.index', [
            CustomerFilter::PARAM_SYNC => SyncStatus::Pending->value,
        ]))->assertOk()->assertSee('C-PENDING');
    }

    /**
     * A stale or hand-edited link should show the full list, not an error.
     */
    public function test_an_unrecognised_filter_value_is_ignored(): void
    {
        $this->customer(['number' => 'CUST0001']);

        $this->get(route('customers.index', [
            CustomerFilter::PARAM_STATUS => 'nonsense',
            CustomerFilter::PARAM_SYNC => 'nonsense',
        ]))->assertOk()->assertSee('CUST0001');
    }

    public function test_the_search_survives_alongside_another_filter(): void
    {
        $this->customer([
            'number' => 'C-OFF-ONE',
            'display_name' => 'Winter sale',
            'blocked' => 'All',
        ]);
        $this->customer([
            'number' => 'C-OFF-TWO',
            'display_name' => 'Summer sale',
            'blocked' => 'All',
        ]);

        $this->get(route('customers.index', [
            CustomerFilter::PARAM_STATUS => CustomerStatus::Blocked->value,
            CustomerFilter::PARAM_SEARCH => 'Winter',
        ]))
            ->assertOk()
            ->assertSee('C-OFF-ONE')
            ->assertDontSee('C-OFF-TWO');
    }

    // ----------------------------------------------------------- the detail

    public function test_the_detail_page_renders(): void
    {
        $customer = $this->customer([
            'number' => 'CUST0001',
            'display_name' => 'Three Stone Vets',
        ]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('CUST0001')
            ->assertSee('Three Stone Vets')
            ->assertSee('Business Central')
            ->assertSee('Ship-to addresses')
            ->assertSee('Next delivery');
    }

    public function test_the_detail_page_lists_every_ship_to_address(): void
    {
        $customer = $this->customer(['shipping_addresses' => [
            ['bc_id' => 'a', 'number' => 'MAIN', 'address_1' => '1 Alpha Road', 'city' => 'Hamilton', 'region' => 'north-island'],
            ['bc_id' => 'b', 'number' => 'YARD', 'address_1' => '2 Bravo Street', 'city' => 'Dunedin', 'region' => 'south-island'],
        ]]);

        $this->get(route('customers.show', $customer))->assertOk()->assertSee('1 Alpha Road')->assertSee('2 Bravo Street')->assertSee('MAIN')->assertSee('YARD');
    }

    public function test_no_ship_to_addresses_says_so(): void
    {
        $customer = $this->customer(['shipping_addresses' => []]);

        $this->get(route('customers.show', $customer))->assertOk()->assertSee('No ship-to addresses have been attached');
    }

    public function test_the_detail_page_shows_the_delivery_payload(): void
    {
        $customer = $this->customer(['number' => 'CUST0001']);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Full desired payload')
            ->assertSee('shipping_addresses');
    }

    /**
     * Customers are never removed, so the detail page must never offer that as
     * the next action.
     */
    public function test_the_detail_page_never_shows_a_removal(): void
    {
        foreach ([$this->active(), $this->blocked()] as $customer) {
            $this->get(route('customers.show', $customer))
                ->assertOk()
                ->assertDontSee('>Remove<', false);
        }
    }

    public function test_a_customer_with_no_ledger_row_says_so(): void
    {
        $customer = $this->customer();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('No ledger row yet.');
    }

    public function test_an_unknown_customer_is_not_found(): void
    {
        $this->get('/customer/99999')->assertNotFound();
    }

    // ----------------------------------------------------------- navigation

    public function test_the_customers_link_is_in_the_sidebar(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('customers.index'));
    }

    // ------------------------------------------- what the website actually holds

    /**
     * The failure this column exists for: the ledger says delivered, the
     * website does not have it, and until now the page said everything was
     * fine. A customer missing from the site has to be visible here.
     */
    public function test_a_delivered_customer_absent_from_the_website_is_flagged(): void
    {
        $customer = $this->customer(['number' => 'CUST0001']);
        $ledger = app(CustomerSyncLedger::class);
        $ledger->markSynced($ledger->markPending($customer, []));

        $this->websiteHolds([]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Not on the website')
            ->assertSee('website:deliver-customers --number=CUST0001 --send');
    }

    public function test_a_customer_the_website_holds_is_not_flagged(): void
    {
        $customer = $this->customer();

        $this->websiteHolds([$customer->bc_id => ['wp_id' => 121638, 'status' => 'publish']]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('Not on the website')
            ->assertSee('post 121638');
    }

    /**
     * An unreachable website is not the same as a deleted customer, and must
     * never be reported as one.
     */
    public function test_an_unreachable_website_reads_as_unknown_rather_than_missing(): void
    {
        $customer = $this->customer();

        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('Not on the website')
            ->assertSee('could not be reached');
    }

    public function test_the_list_shows_which_customers_are_on_the_site(): void
    {
        $present = $this->customer(['number' => 'C-HERE']);
        $absent = $this->customer(['number' => 'C-GONE', 'bc_id' => fake()->uuid()]);

        $this->websiteHolds([$present->bc_id => ['wp_id' => 500, 'status' => 'publish']]);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Present')
            ->assertSee('Missing');
    }

    /**
     * One request for the whole page, not one per row.
     */
    public function test_the_list_asks_the_website_once(): void
    {
        foreach (range(1, 4) as $n) {
            $this->customer(['number' => sprintf('CUST%04d', $n), 'bc_id' => fake()->uuid()]);
        }

        $this->websiteHolds([]);

        $this->get(route('customers.index'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_list_still_renders_when_the_website_is_unreachable(): void
    {
        $this->customer(['number' => 'CUST0001']);

        Http::fake([self::STATUS_ENDPOINT => Http::response('boom', 500)]);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('CUST0001')
            ->assertSee('unknown');
    }
}
