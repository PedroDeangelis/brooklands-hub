<?php

namespace Tests\Feature\Http;

use App\Enums\SyncStatus;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Products\ExclusionReason;
use App\Products\ProductFilter;
use App\Sync\SyncLedger;
use App\Sync\WebsiteAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Filtering the product list, and the dashboard links that drive it.
 */
class ProductFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A product passing every website rule, which cases then break parts of.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'blocked' => false,
            'item_category_id' => '',
            'price' => 10,
            'type' => 'Inventory',
            'gppg' => 'FINISHED GOODS',
        ], $attributes));
    }

    /**
     * @return list<string>
     */
    private function listedSkus(string $url): array
    {
        $response = $this->get($url)->assertOk();
        $products = $response->viewData('products');

        return $products->pluck('sku')->values()->all();
    }

    public function test_no_filter_lists_every_product(): void
    {
        $this->product(['sku' => 'AAA1']);
        $this->product(['sku' => 'BBB1', 'price' => 0]);

        $this->assertSame(['AAA1', 'BBB1'], $this->listedSkus(route('products.index')));
    }

    public function test_the_excluded_filter_lists_only_excluded_products(): void
    {
        $this->product(['sku' => 'GOOD1']);
        $this->product(['sku' => 'NOPRICE1', 'price' => 0]);
        $this->product(['sku' => 'RETIRED1', 'gppg' => 'RETIRED']);

        $this->assertSame(['NOPRICE1', 'RETIRED1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED]),
        ));
    }

    public function test_the_eligible_filter_lists_only_eligible_products(): void
    {
        $this->product(['sku' => 'GOOD1']);
        $this->product(['sku' => 'NOPRICE1', 'price' => 0]);

        $this->assertSame(['GOOD1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_ELIGIBLE]),
        ));
    }

    public function test_the_reason_filter_uses_the_enum_value(): void
    {
        $this->product(['sku' => 'NOPRICE1', 'price' => 0]);
        $this->product(['sku' => 'RETIRED1', 'gppg' => 'RETIRED']);

        $this->assertSame(['NOPRICE1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_REASON => ExclusionReason::NoPrice->value]),
        ));

        $this->assertSame(['RETIRED1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_REASON => ExclusionReason::NotFinishedGoods->value]),
        ));
    }

    /**
     * Filtering by a reason asks whether the product's reasons contain it, not
     * whether it is the only one. A product failing three rules must appear
     * under all three.
     */
    public function test_a_product_with_several_reasons_appears_under_each_of_them(): void
    {
        $this->product(['sku' => 'MULTI1', 'price' => 0, 'type' => 'Service', 'gppg' => 'SERVICES']);
        $this->product(['sku' => 'ONLYPRICE1', 'price' => 0]);

        $this->assertSame(['MULTI1', 'ONLYPRICE1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_REASON => ExclusionReason::NoPrice->value]),
        ));

        $this->assertSame(['MULTI1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_REASON => ExclusionReason::UnsupportedType->value]),
        ));

        $this->assertSame(['MULTI1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_REASON => ExclusionReason::NotFinishedGoods->value]),
        ));
    }

    public function test_every_exclusion_reason_can_be_filtered_on(): void
    {
        $this->product(['sku' => 'BLOCKED1', 'blocked' => true]);
        $this->product(['sku' => 'RETIRE1', 'item_category_id' => 'RETIRE']);
        $this->product(['sku' => 'NOPRICE1', 'price' => 0]);
        $this->product(['sku' => 'SERVICE1', 'type' => 'Service']);
        $this->product(['sku' => 'GROUP1', 'gppg' => 'RETIRED']);

        foreach ([
            [ExclusionReason::Blocked, 'BLOCKED1'],
            [ExclusionReason::Retired, 'RETIRE1'],
            [ExclusionReason::NoPrice, 'NOPRICE1'],
            [ExclusionReason::UnsupportedType, 'SERVICE1'],
            [ExclusionReason::NotFinishedGoods, 'GROUP1'],
        ] as [$reason, $sku]) {
            $this->assertSame([$sku], $this->listedSkus(
                route('products.index', [ProductFilter::PARAM_REASON => $reason->value]),
            ), "filtering on {$reason->value}");
        }
    }

    public function test_the_sync_status_filter_matches_the_ledger(): void
    {
        foreach ([
            ['PEND1', SyncStatus::Pending],
            ['SYNC1', SyncStatus::Synced],
            ['FAIL1', SyncStatus::Failed],
        ] as [$sku, $status]) {
            $product = $this->product(['sku' => $sku]);
            SyncRecord::factory()->create([
                'bc_id' => $product->bc_id,
                'channel' => SyncLedger::CHANNEL_ITEMS,
                'status' => $status,
            ]);
        }

        $this->assertSame(['PEND1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_SYNC => 'pending']),
        ));
        $this->assertSame(['SYNC1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_SYNC => 'synced']),
        ));
        $this->assertSame(['FAIL1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_SYNC => 'failed']),
        ));
    }

    /**
     * An excluded product pending removal is real outstanding work, so the
     * pending filter must list it rather than hiding it behind eligibility.
     */
    public function test_the_pending_filter_lists_an_excluded_product_awaiting_removal(): void
    {
        $excluded = $this->product(['sku' => 'STALE1', 'price' => 0]);
        SyncRecord::factory()->create([
            'bc_id' => $excluded->bc_id,
            'channel' => SyncLedger::CHANNEL_ITEMS,
            'status' => SyncStatus::Pending,
            'action' => WebsiteAction::Remove,
        ]);

        $this->assertSame(['STALE1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_SYNC => 'pending']),
        ));
    }

    /**
     * Not applicable is for products the ledger has never seen: anything with a
     * row has work behind it, even if that work is a removal.
     */
    public function test_the_not_applicable_filter_only_lists_products_never_queued(): void
    {
        $this->product(['sku' => 'NEVER1', 'price' => 0]);

        $queued = $this->product(['sku' => 'STALE1', 'price' => 0]);
        SyncRecord::factory()->create([
            'bc_id' => $queued->bc_id,
            'channel' => SyncLedger::CHANNEL_ITEMS,
            'status' => SyncStatus::Pending,
            'action' => WebsiteAction::Remove,
        ]);

        $this->assertSame(['NEVER1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_SYNC => ProductFilter::SYNC_NOT_APPLICABLE]),
        ));
    }

    public function test_the_action_filter_splits_upserts_from_removals(): void
    {
        $this->product(['sku' => 'KEEP1']);
        $this->product(['sku' => 'DROP1', 'price' => 0]);

        $this->assertSame(['KEEP1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_ACTION => WebsiteAction::Upsert->value]),
        ));
        $this->assertSame(['DROP1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_ACTION => WebsiteAction::Remove->value]),
        ));
    }

    public function test_the_not_synced_filter_finds_eligible_products_with_no_ledger_row(): void
    {
        $this->product(['sku' => 'NEW1']);
        $queued = $this->product(['sku' => 'QUEUED1']);
        SyncRecord::factory()->create([
            'bc_id' => $queued->bc_id,
            'channel' => SyncLedger::CHANNEL_ITEMS,
            'status' => SyncStatus::Pending,
        ]);

        $this->assertSame(['NEW1'], $this->listedSkus(
            route('products.index', [ProductFilter::PARAM_SYNC => ProductFilter::SYNC_NOT_SYNCED]),
        ));
    }

    public function test_filters_combine_rather_than_replace_each_other(): void
    {
        $this->product(['sku' => 'AA10', 'price' => 0]);
        $this->product(['sku' => 'AA20', 'gppg' => 'RETIRED']);
        $this->product(['sku' => 'BB10', 'price' => 0]);

        $this->assertSame(['AA10'], $this->listedSkus(route('products.index', [
            ProductFilter::PARAM_SEARCH => 'AA',
            ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED,
            ProductFilter::PARAM_REASON => ExclusionReason::NoPrice->value,
        ])));
    }

    public function test_an_unrecognised_filter_value_is_ignored_rather_than_failing(): void
    {
        $this->product(['sku' => 'AAA1']);

        $this->assertSame(['AAA1'], $this->listedSkus(route('products.index', [
            ProductFilter::PARAM_WEBSITE => 'nonsense',
            ProductFilter::PARAM_SYNC => 'nonsense',
            ProductFilter::PARAM_REASON => 'nonsense',
        ])));
    }

    public function test_the_active_filters_are_shown_above_the_table(): void
    {
        $this->product(['sku' => 'AA10', 'price' => 0]);

        $this->get(route('products.index', [
            ProductFilter::PARAM_SEARCH => 'AA',
            ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED,
            ProductFilter::PARAM_REASON => ExclusionReason::NoPrice->value,
        ]))
            ->assertOk()
            ->assertSee('Search:')
            ->assertSee('Website:')
            ->assertSee('Excluded')
            ->assertSee('Reason:')
            ->assertSee('No price')
            ->assertSee('Clear filters');
    }

    public function test_no_filter_chips_are_shown_without_filters(): void
    {
        $this->product();

        $this->get(route('products.index'))
            ->assertOk()
            ->assertDontSee('Clear filters');
    }

    public function test_pagination_links_preserve_the_active_filters(): void
    {
        Product::factory()->count(30)->create([
            'price' => 0,
            'blocked' => false,
            'type' => 'Inventory',
            'gppg' => 'FINISHED GOODS',
        ]);

        $response = $this->get(route('products.index', [
            ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED,
        ]))->assertOk();

        $this->assertStringContainsString(
            ProductFilter::PARAM_WEBSITE.'='.ProductFilter::WEBSITE_EXCLUDED,
            $response->viewData('products')->nextPageUrl() ?? '',
        );
    }

    public function test_the_search_form_carries_the_other_filters_through(): void
    {
        $this->product(['sku' => 'AA10', 'price' => 0]);

        $this->get(route('products.index', [
            ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED,
            ProductFilter::PARAM_REASON => ExclusionReason::NoPrice->value,
        ]))
            ->assertOk()
            ->assertSee('<input type="hidden" name="'.ProductFilter::PARAM_WEBSITE.'" value="excluded">', false)
            ->assertSee('<input type="hidden" name="'.ProductFilter::PARAM_REASON.'" value="no_price">', false);
    }

    public function test_removing_one_chip_keeps_the_others(): void
    {
        $filter = ProductFilter::fromRequest(request()->merge([
            ProductFilter::PARAM_SEARCH => 'AA',
            ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED,
            ProductFilter::PARAM_REASON => ExclusionReason::NoPrice->value,
        ]));

        $this->assertSame([
            ProductFilter::PARAM_SEARCH => 'AA',
            ProductFilter::PARAM_REASON => 'no_price',
        ], $filter->without(ProductFilter::PARAM_WEBSITE));
    }

    public function test_the_dashboard_cards_link_to_the_matching_filters(): void
    {
        $this->product(['sku' => 'NOPRICE1', 'price' => 0]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('products.index', [ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_EXCLUDED]), false)
            ->assertSee(route('products.index', [ProductFilter::PARAM_SYNC => 'pending']), false)
            ->assertSee(route('products.index', [ProductFilter::PARAM_SYNC => 'synced']), false)
            ->assertSee(route('products.index', [ProductFilter::PARAM_SYNC => 'failed']), false)
            ->assertSee(route('products.index', [ProductFilter::PARAM_REASON => ExclusionReason::NoPrice->value]), false);
    }

    public function test_the_total_products_card_links_to_the_unfiltered_list(): void
    {
        $this->product();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('products.index').'"', false);
    }

    /**
     * The dashboard describes the whole catalogue, so its counts must not move
     * with whatever filter the product list happens to be showing.
     */
    public function test_the_dashboard_counts_ignore_product_list_filters(): void
    {
        $this->product(['sku' => 'GOOD1']);
        $this->product(['sku' => 'NOPRICE1', 'price' => 0]);
        $this->product(['sku' => 'NOPRICE2', 'price' => 0]);

        $response = $this->get(route('dashboard', [
            ProductFilter::PARAM_WEBSITE => ProductFilter::WEBSITE_ELIGIBLE,
            ProductFilter::PARAM_REASON => ExclusionReason::NotFinishedGoods->value,
        ]))->assertOk();

        $this->assertSame(3, $response->viewData('totalProducts'));
        $this->assertSame(2, $response->viewData('excluded'));
    }
}
