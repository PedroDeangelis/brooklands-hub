<?php

namespace Tests\Feature\Products;

use App\BusinessCentral\Import\ProductImporter;
use App\BusinessCentral\ItemsQuery;
use App\Enums\SyncStatus;
use App\Jobs\ImportBcProduct;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\ExclusionReason;
use App\Products\WebsiteEligibility;
use App\Sync\DeliveryStatus;
use App\Sync\SyncLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Products that the Business Central filter used to remove must now arrive in
 * Laravel and explain themselves.
 *
 * Every row here would previously have failed the removed OData filter
 * ("unitPrice gt 0 and (type eq 'Inventory' or type eq 'Non_x002D_Inventory')
 * and gppg eq 'FINISHED GOODS' and itemCategoryId ne 'RETIRE'") and so would
 * never have been fetched at all.
 */
class IneligibleProductsAreStillImportedTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token';

    private const ITEMS_URL = 'https://api.businesscentral.dynamics.com/v2.0/tenant-abc/Sandbox_Test'
        .'/api/brooklands/catalog/v1.0/companies(company-guid)/itemsExt';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bc', [
            'url' => 'https://api.businesscentral.dynamics.com',
            'tenant_id' => 'tenant-abc',
            'client_id' => 'client-abc',
            'client_secret' => 'secret-abc',
            'instance' => 'Sandbox_Test',
            'company_id' => 'company-guid',
            'api_version' => 'v2.0',
            'http_timeout' => 30,
            'http_connect_timeout' => 10,
        ]);
    }

    /**
     * Rows that the removed filter would have excluded, with what the dashboard
     * must now say about each.
     *
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function previouslyFilteredRows(): array
    {
        return [
            'zero price' => [
                ['unitPrice' => 0],
                ['Product has no selling price'],
            ],
            'service type' => [
                ['type' => 'Service'],
                ['Product type "Service" is not supported'],
            ],
            'outside finished goods' => [
                ['gppg' => 'RETIRED'],
                ['Product is not classified as FINISHED GOODS (RETIRED)'],
            ],
            'retire category' => [
                ['itemCategoryId' => 'RETIRE'],
                ['Product belongs to the RETIRE category'],
            ],
            'blocked' => [
                ['blocked' => true],
                ['Product is blocked in Business Central'],
            ],
            'several rules at once' => [
                ['unitPrice' => 0, 'type' => 'Service', 'gppg' => 'SERVICES'],
                [
                    'Product has no selling price',
                    'Product type "Service" is not supported',
                    'Product is not classified as FINISHED GOODS (SERVICES)',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $expectedReasons
     */
    #[DataProvider('previouslyFilteredRows')]
    public function test_a_previously_filtered_row_is_imported_and_explained(array $overrides, array $expectedReasons): void
    {
        app(ProductImporter::class)->import($this->row($overrides));

        $product = Product::query()->sole();
        $result = app(WebsiteEligibility::class)->for($product);

        $this->assertFalse($result->eligible);
        $this->assertSame($expectedReasons, $result->reasons());
    }

    public function test_the_business_central_query_carries_no_eligibility_filter(): void
    {
        $query = ItemsQuery::forTop(50);

        $this->assertArrayNotHasKey('$filter', $query);
        $this->assertStringNotContainsString('FINISHED GOODS', json_encode($query) ?: '');
        $this->assertStringNotContainsString('RETIRE', json_encode($query) ?: '');
    }

    public function test_the_query_still_selects_the_field_the_finished_goods_rule_needs(): void
    {
        $this->assertStringContainsString('gppg', ItemsQuery::SELECT);
    }

    public function test_the_query_keeps_pagination(): void
    {
        $this->assertSame(25, ItemsQuery::forTop(25)['$top']);
        $this->assertSame(100, ItemsQuery::forTop(25, 100)['$skip']);
        $this->assertArrayNotHasKey('$skip', ItemsQuery::forTop(25));
        $this->assertSame('lastModifiedDateTime asc', ItemsQuery::forTop(25)['$orderby']);
    }

    public function test_the_import_command_sends_no_filter_and_pages_with_skip(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-123', 'expires_in' => 3599]),
            self::ITEMS_URL.'*' => Http::response(['value' => [$this->row(['type' => 'Service'])]]),
        ]);

        $this->artisan('bc:import-items', ['--top' => 5, '--skip' => 10])->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::ITEMS_URL)
                && ! isset($request['$filter'])
                && $request['$top'] === 5
                && $request['$skip'] === 10;
        });
    }

    public function test_the_import_command_rejects_a_negative_skip(): void
    {
        $this->artisan('bc:import-items', ['--top' => 1, '--skip' => -1])
            ->expectsOutputToContain('--skip cannot be negative')
            ->assertExitCode(1);
    }

    /**
     * The whole point of keeping these rows: the queued path stores them rather
     * than discarding them, so they stay searchable in the dashboard.
     */
    public function test_an_ineligible_row_is_stored_by_the_queued_import(): void
    {
        ImportBcProduct::dispatchSync($this->row(['number' => 'SERV1', 'type' => 'Service']));

        $this->assertDatabaseHas('products', ['sku' => 'SERV1', 'type' => 'Service']);
    }

    public function test_an_ineligible_product_is_searchable_in_the_dashboard(): void
    {
        app(ProductImporter::class)->import($this->row(['number' => 'SERV1', 'type' => 'Service']));

        $this->get(route('products.index', ['search' => 'SERV1']))
            ->assertOk()
            ->assertSee('SERV1')
            ->assertSee('Excluded');
    }

    /**
     * An excluded product has nothing to deliver, so it must not be reported as
     * waiting or failed: being off the website is a valid outcome.
     */
    public function test_an_ineligible_product_reports_not_applicable_rather_than_a_failure(): void
    {
        app(ProductImporter::class)->import($this->row(['type' => 'Service']));
        $product = Product::query()->sole();

        $status = DeliveryStatus::for($product, app(WebsiteEligibility::class), null);

        $this->assertSame(DeliveryStatus::NotApplicable, $status);
        $this->assertSame('Not applicable', $status->label());
        $this->assertFalse($status->isProblem());
    }

    public function test_the_detail_page_lists_every_reason_for_an_ineligible_product(): void
    {
        app(ProductImporter::class)->import($this->row([
            'unitPrice' => 0,
            'type' => 'Service',
            'gppg' => 'SERVICES',
        ]));

        $this->get(route('products.show', Product::query()->sole()))
            ->assertOk()
            ->assertSee('Excluded')
            ->assertSee('Reasons:')
            ->assertSee('Product has no selling price')
            ->assertSee('Product type &quot;Service&quot; is not supported', false)
            ->assertSee('Product is not classified as FINISHED GOODS (SERVICES)')
            ->assertSee('Not applicable');
    }

    public function test_the_dashboard_breaks_the_exclusions_down_by_reason(): void
    {
        $importer = app(ProductImporter::class);
        $importer->import($this->row(['id' => $this->id('a'), 'number' => 'A1', 'unitPrice' => 0]));
        $importer->import($this->row(['id' => $this->id('b'), 'number' => 'B1', 'unitPrice' => 0]));
        $importer->import($this->row(['id' => $this->id('c'), 'number' => 'C1', 'type' => 'Service']));
        $importer->import($this->row(['id' => $this->id('d'), 'number' => 'D1']));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Why products are excluded')
            ->assertSeeInOrder(['data-exclusion="no_price"', '2'], false)
            ->assertSeeInOrder(['data-exclusion="unsupported_type"', '1'], false);
    }

    /**
     * A product can lose its eligibility after being queued. Its ledger row is
     * left alone, but it must not sit in the pending count forever: nothing
     * will ever clear it.
     */
    public function test_the_dashboard_does_not_count_an_excluded_product_as_pending(): void
    {
        app(ProductImporter::class)->import($this->row(['type' => 'Service']));
        SyncRecord::factory()->create([
            'bc_id' => Product::query()->sole()->bc_id,
            'channel' => SyncLedger::CHANNEL_ITEMS,
            'status' => SyncStatus::Pending,
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['data-stat="pending"', '0'], false)
            ->assertSeeInOrder(['data-stat="excluded"', '1'], false);
    }

    public function test_the_dashboard_shows_no_breakdown_when_every_product_qualifies(): void
    {
        app(ProductImporter::class)->import($this->row());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Why products are excluded');
    }

    public function test_an_eligible_row_is_unaffected_by_the_wider_import(): void
    {
        app(ProductImporter::class)->import($this->row());

        $product = Product::query()->sole();
        $result = app(WebsiteEligibility::class)->for($product);

        $this->assertTrue($result->eligible);
        $this->assertFalse($result->hasReason(ExclusionReason::NotFinishedGoods));
        $this->assertSame('FINISHED GOODS', $product->gppg);
    }

    private function id(string $seed): string
    {
        return str_repeat($seed, 8).'-3d1c-f111-8341-6045bde65a16';
    }

    /**
     * A row that passes every rule, which each case then breaks one part of.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ab3349b2-3d1c-f111-8341-6045bde65a16',
            'number' => 'POLY1',
            'displayName' => 'Tropical Fish 1 Poly Bin with Lid',
            'type' => 'Inventory',
            'unitPrice' => 2,
            'gppg' => 'FINISHED GOODS',
            'itemCategoryId' => '',
            'blocked' => false,
            'lastModifiedDateTime' => '2026-03-12T11:06:22.503Z',
        ], $overrides);
    }
}
