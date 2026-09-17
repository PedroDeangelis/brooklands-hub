<?php

namespace Tests\Feature\Products\Website;

use App\Products\Website\PricingRule;
use App\Products\Website\PricingRuleBuilder;
use Tests\TestCase;

class PricingRuleBuilderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'id' => 'line-1',
            'salesCode' => 'LIST PRICE',
            'salesType' => 'Customer_x0020_Price_x0020_Group',
            'unitPrice' => 18.22,
            'amountType' => 'Price',
            'lineDiscountPercent' => 0,
            'minimumQuantity' => 0,
            'unitOfMeasureCode' => 'EA',
            'startingDate' => '0001-01-01',
            'endingDate' => '0001-01-01',
            'currencyCode' => '',
            'lastModifiedDateTime' => '2026-04-16T23:31:12.32Z',
        ], $overrides);
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return array{rules: list<PricingRule>, rrp: float|null}
     */
    private function build(array $lines): array
    {
        return app(PricingRuleBuilder::class)->build($lines);
    }

    public function test_a_price_line_becomes_a_fixed_price_rule(): void
    {
        $result = $this->build([$this->line()]);

        $this->assertCount(1, $result['rules']);
        $rule = $result['rules'][0];

        $this->assertSame('LIST PRICE', $rule->salesCode);
        $this->assertSame(PricingRule::TYPE_FIXED_PRICE, $rule->pricingType);
        $this->assertSame(18.22, $rule->price);
        $this->assertNull($rule->amount);
        $this->assertSame('EA', $rule->unitOfMeasureCode);
        $this->assertTrue($rule->isFixedPrice());
    }

    public function test_a_discount_line_becomes_a_discount_rule(): void
    {
        $result = $this->build([$this->line([
            'salesCode' => 'TRADE',
            'amountType' => 'Discount',
            'unitPrice' => 0,
            'lineDiscountPercent' => 12.5,
        ])]);

        $rule = $result['rules'][0];

        $this->assertSame(PricingRule::TYPE_DISCOUNT, $rule->pricingType);
        $this->assertSame(12.5, $rule->amount);
        $this->assertNull($rule->price);
        $this->assertSame('12.5% off', $rule->label());
    }

    public function test_a_price_and_discount_line_is_stored_as_the_effective_fixed_price(): void
    {
        // Business Central serialises "Price & Discount" as the enum name "Any" and
        // charges the price minus the discount, so the effective value is recorded.
        $result = $this->build([$this->line([
            'amountType' => 'Any',
            'unitPrice' => 100.0,
            'lineDiscountPercent' => 10.0,
        ])]);

        $rule = $result['rules'][0];

        $this->assertSame(PricingRule::TYPE_FIXED_PRICE, $rule->pricingType);
        $this->assertSame(90.0, $rule->price);
        $this->assertSame(10.0, $rule->amount, 'the discount is kept for reference');
    }

    public function test_an_explicit_price_and_discount_amount_type_behaves_the_same(): void
    {
        $result = $this->build([$this->line([
            'amountType' => 'Price_x0020_Discount',
            'unitPrice' => 50.0,
            'lineDiscountPercent' => 20.0,
        ])]);

        $this->assertSame(40.0, $result['rules'][0]->price);
    }

    public function test_the_effective_price_is_rounded_to_four_places(): void
    {
        $result = $this->build([$this->line([
            'amountType' => 'Any',
            'unitPrice' => 19.99,
            'lineDiscountPercent' => 7.5,
        ])]);

        $this->assertSame(18.4908, $result['rules'][0]->price);
    }

    public function test_an_rrp_line_sets_the_rrp_and_is_not_a_pricing_rule(): void
    {
        $result = $this->build([
            $this->line(['salesCode' => 'RRP', 'unitPrice' => 24.99]),
            $this->line(),
        ]);

        $this->assertSame(24.99, $result['rrp']);
        $this->assertCount(1, $result['rules']);
        $this->assertSame('LIST PRICE', $result['rules'][0]->salesCode);
    }

    public function test_the_rrp_sales_code_is_matched_ignoring_case(): void
    {
        $result = $this->build([$this->line(['salesCode' => 'rrp', 'unitPrice' => 30.0])]);

        $this->assertSame(30.0, $result['rrp']);
        $this->assertSame([], $result['rules']);
    }

    public function test_a_zero_rrp_is_ignored(): void
    {
        $result = $this->build([$this->line(['salesCode' => 'RRP', 'unitPrice' => 0])]);

        $this->assertNull($result['rrp']);
    }

    public function test_lines_without_a_sales_code_are_skipped(): void
    {
        $result = $this->build([$this->line(['salesCode' => '   ']), $this->line(['salesCode' => ''])]);

        $this->assertSame([], $result['rules']);
    }

    public function test_a_price_line_with_no_price_produces_no_rule(): void
    {
        $result = $this->build([$this->line(['unitPrice' => 0, 'lineDiscountPercent' => 0])]);

        $this->assertSame([], $result['rules']);
    }

    public function test_a_discount_line_with_no_discount_produces_no_rule(): void
    {
        $result = $this->build([$this->line([
            'amountType' => 'Discount',
            'unitPrice' => 0,
            'lineDiscountPercent' => 0,
        ])]);

        $this->assertSame([], $result['rules']);
    }

    public function test_empty_business_central_dates_become_null(): void
    {
        $result = $this->build([$this->line(['startingDate' => '0001-01-01', 'endingDate' => '0000-00-00'])]);

        $this->assertNull($result['rules'][0]->startingDate);
        $this->assertNull($result['rules'][0]->endingDate);
    }

    public function test_real_dates_are_normalised_and_invalid_ones_dropped(): void
    {
        $result = $this->build([$this->line(['startingDate' => '2026-01-05', 'endingDate' => 'not-a-date'])]);

        $this->assertSame('2026-01-05', $result['rules'][0]->startingDate);
        $this->assertNull($result['rules'][0]->endingDate);
    }

    public function test_the_minimum_quantity_is_a_non_negative_integer(): void
    {
        $result = $this->build([
            $this->line(['salesCode' => 'A', 'minimumQuantity' => '6']),
            $this->line(['salesCode' => 'B', 'minimumQuantity' => -3]),
            $this->line(['salesCode' => 'C', 'minimumQuantity' => null]),
        ]);

        $quantities = array_map(fn (PricingRule $r): int => $r->minimumQuantity, $result['rules']);

        $this->assertSame([6, 0, 0], $quantities);
    }

    public function test_rules_are_ordered_by_sales_type_then_code_then_quantity_break(): void
    {
        $result = $this->build([
            $this->line(['salesType' => 'Customer', 'salesCode' => 'B', 'minimumQuantity' => 5]),
            $this->line(['salesType' => 'Customer', 'salesCode' => 'B', 'minimumQuantity' => 1]),
            $this->line(['salesType' => 'Customer', 'salesCode' => 'A', 'minimumQuantity' => 9]),
            $this->line(['salesType' => 'All', 'salesCode' => 'Z', 'minimumQuantity' => 0]),
        ]);

        $order = array_map(
            fn (PricingRule $r): string => "{$r->salesType}/{$r->salesCode}/{$r->minimumQuantity}",
            $result['rules'],
        );

        $this->assertSame(['All/Z/0', 'Customer/A/9', 'Customer/B/1', 'Customer/B/5'], $order);
    }

    public function test_the_sales_type_label_unescapes_the_business_central_enum_name(): void
    {
        $result = $this->build([$this->line(['salesType' => 'Customer_x0020_Price_x0020_Group'])]);

        $this->assertSame('Customer_x0020_Price_x0020_Group', $result['rules'][0]->salesType);
        $this->assertSame('Customer Price Group', $result['rules'][0]->salesTypeLabel());
    }

    public function test_the_sales_type_label_is_empty_when_no_sales_type_is_set(): void
    {
        $result = $this->build([$this->line(['salesType' => ''])]);

        $this->assertSame('', $result['rules'][0]->salesTypeLabel());
    }

    public function test_malformed_lines_are_skipped(): void
    {
        $result = $this->build(['not an array', 42, $this->line()]);

        $this->assertCount(1, $result['rules']);
    }

    public function test_no_price_lines_yields_no_rules_and_no_rrp(): void
    {
        $result = $this->build([]);

        $this->assertSame([], $result['rules']);
        $this->assertNull($result['rrp']);
    }
}
