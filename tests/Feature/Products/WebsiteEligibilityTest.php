<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Products\ExclusionReason;
use App\Products\WebsiteEligibility;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WebsiteEligibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A product passing every rule, which individual tests then break one rule of.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->make(array_merge([
            'blocked' => false,
            'item_category_id' => 'TOYS',
            'price' => 19.95,
            'type' => 'Inventory',
            'gppg' => 'FINISHED GOODS',
        ], $attributes));
    }

    private function eligibility(): WebsiteEligibility
    {
        return app(WebsiteEligibility::class);
    }

    public function test_a_normal_product_is_eligible(): void
    {
        $result = $this->eligibility()->for($this->product());

        $this->assertTrue($result->eligible);
        $this->assertSame([], $result->reasons());
        $this->assertNull($result->reason());
        $this->assertSame('Eligible', $result->label());
    }

    public function test_a_blocked_product_is_excluded(): void
    {
        $result = $this->eligibility()->for($this->product(['blocked' => true]));

        $this->assertFalse($result->eligible);
        $this->assertTrue($result->hasReason(ExclusionReason::Blocked));
        $this->assertSame(['Product is blocked in Business Central'], $result->reasons());
        $this->assertSame('Excluded', $result->label());
    }

    public function test_a_retire_category_product_is_excluded(): void
    {
        $result = $this->eligibility()->for($this->product(['item_category_id' => 'RETIRE']));

        $this->assertFalse($result->eligible);
        $this->assertSame(['Product belongs to the RETIRE category'], $result->reasons());
    }

    public function test_the_retire_category_match_ignores_case_and_padding(): void
    {
        $result = $this->eligibility()->for($this->product(['item_category_id' => ' retire ']));

        $this->assertTrue($result->hasReason(ExclusionReason::Retired));
    }

    public function test_an_empty_category_does_not_exclude_a_product(): void
    {
        $result = $this->eligibility()->for($this->product(['item_category_id' => '']));

        $this->assertTrue($result->eligible);
    }

    public function test_a_product_with_no_price_is_excluded(): void
    {
        $result = $this->eligibility()->for($this->product(['price' => 0]));

        $this->assertFalse($result->eligible);
        $this->assertSame(['Product has no selling price'], $result->reasons());
    }

    public function test_a_negative_price_is_excluded(): void
    {
        $result = $this->eligibility()->for($this->product(['price' => -5]));

        $this->assertTrue($result->hasReason(ExclusionReason::NoPrice));
    }

    public function test_an_unsupported_type_is_excluded_and_names_the_type(): void
    {
        $result = $this->eligibility()->for($this->product(['type' => 'Service']));

        $this->assertFalse($result->eligible);
        $this->assertSame(['Product type "Service" is not supported'], $result->reasons());
    }

    public function test_the_two_supported_types_are_eligible(): void
    {
        $this->assertTrue($this->eligibility()->isEligible($this->product(['type' => 'Inventory'])));
        $this->assertTrue($this->eligibility()->isEligible($this->product(['type' => 'Non_x002D_Inventory'])));
    }

    /**
     * Business Central escapes the hyphen, so the message must unescape it or a
     * person reading the dashboard sees a field name rather than a product type.
     */
    public function test_an_empty_type_is_treated_as_non_inventory_and_stays_eligible(): void
    {
        $this->assertTrue($this->eligibility()->isEligible($this->product(['type' => ''])));
    }

    public function test_a_product_outside_finished_goods_is_excluded_and_names_the_group(): void
    {
        $result = $this->eligibility()->for($this->product(['gppg' => 'RETIRED']));

        $this->assertFalse($result->eligible);
        $this->assertSame(['Product is not classified as FINISHED GOODS (RETIRED)'], $result->reasons());
    }

    public function test_a_missing_product_group_is_excluded_without_naming_a_group(): void
    {
        $result = $this->eligibility()->for($this->product(['gppg' => '']));

        $this->assertSame(['Product is not classified as FINISHED GOODS'], $result->reasons());
    }

    public function test_the_finished_goods_match_ignores_case_and_padding(): void
    {
        $this->assertTrue($this->eligibility()->isEligible($this->product(['gppg' => ' finished goods '])));
    }

    /**
     * The point of the rewrite: a product failing several rules must report all
     * of them, so nobody fixes one and expects the product to appear.
     */
    public function test_every_failing_rule_is_reported_not_just_the_first(): void
    {
        $result = $this->eligibility()->for($this->product([
            'blocked' => true,
            'item_category_id' => 'RETIRE',
            'price' => 0,
            'type' => 'Service',
            'gppg' => 'RAW MATERIALS',
        ]));

        $this->assertFalse($result->eligible);
        $this->assertSame(5, $result->reasonCount());
        $this->assertSame([
            'Product is blocked in Business Central',
            'Product belongs to the RETIRE category',
            'Product has no selling price',
            'Product type "Service" is not supported',
            'Product is not classified as FINISHED GOODS (RAW MATERIALS)',
        ], $result->reasons());
    }

    public function test_the_example_combination_reads_as_specified(): void
    {
        $result = $this->eligibility()->for($this->product([
            'type' => 'Service',
            'gppg' => 'SERVICES',
            'price' => 0,
        ]));

        $this->assertSame([
            'Product has no selling price',
            'Product type "Service" is not supported',
            'Product is not classified as FINISHED GOODS (SERVICES)',
        ], $result->reasons());
    }

    public function test_the_first_reason_is_offered_for_single_line_displays(): void
    {
        $result = $this->eligibility()->for($this->product(['blocked' => true, 'price' => 0]));

        $this->assertSame('Product is blocked in Business Central', $result->reason());
        $this->assertSame(2, $result->reasonCount());
    }

    public function test_the_excluded_query_scope_matches_the_per_product_rules(): void
    {
        $eligible = $this->product();
        $eligible->save();

        foreach ([
            ['blocked' => true],
            ['item_category_id' => 'RETIRE'],
            ['price' => 0],
            ['type' => 'Service'],
            ['gppg' => 'RETIRED'],
            ['type' => 'Service', 'price' => 0],
        ] as $attributes) {
            $this->product($attributes)->save();
        }

        Product::factory()->count(2)->create([
            'blocked' => false,
            'item_category_id' => 'TOYS',
            'price' => 10,
            'type' => 'Non_x002D_Inventory',
            'gppg' => 'FINISHED GOODS',
        ]);

        $eligibility = $this->eligibility();

        $excludedByQuery = $eligibility->scopeExcluded(Product::query())->pluck('id')->sort()->values()->all();
        $excludedByRules = Product::all()
            ->reject(fn (Product $p): bool => $eligibility->isEligible($p))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame(6, count($excludedByQuery));
        $this->assertSame($excludedByRules, $excludedByQuery);
    }

    public function test_the_eligible_scope_is_the_complement_of_the_excluded_scope(): void
    {
        $this->product()->save();
        $this->product(['price' => 0])->save();
        $this->product(['gppg' => 'RETIRED'])->save();

        $eligibility = $this->eligibility();

        $eligible = $eligibility->scopeEligible(Product::query())->pluck('id')->all();
        $expected = Product::all()
            ->filter(fn (Product $p): bool => $eligibility->isEligible($p))
            ->pluck('id')->all();

        $this->assertSame(1, count($eligible));
        $this->assertSame(array_values($expected), array_values($eligible));
    }
}
