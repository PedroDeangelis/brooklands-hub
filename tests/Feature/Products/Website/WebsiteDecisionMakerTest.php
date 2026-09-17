<?php

namespace Tests\Feature\Products\Website;

use App\Products\EligibilityResult;
use App\Products\Exclusion;
use App\Products\ExclusionReason;
use App\Products\Website\StockPosition;
use App\Products\Website\StockStatus;
use App\Products\Website\WebsiteDecision;
use App\Products\Website\WebsiteDecisionMaker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rules deciding how a product should finally appear on the website.
 *
 * Ported from the website's own upsert logic, where they were spread across
 * stock writes and read-time filters.
 */
class WebsiteDecisionMakerTest extends TestCase
{
    private function decide(
        bool $eligible = true,
        bool $tracksInventory = true,
        bool $salesBlocked = false,
        ?StockPosition $stock = null,
    ): WebsiteDecision {
        $eligibility = $eligible
            ? EligibilityResult::eligible()
            : EligibilityResult::excluded([new Exclusion(ExclusionReason::NoPrice)]);

        return (new WebsiteDecisionMaker)->decide(
            $eligibility,
            $tracksInventory,
            $salesBlocked,
            $stock ?? $this->stock(0.0),
        );
    }

    private function stock(float $sellable): StockPosition
    {
        return new StockPosition(known: true, inventory: $sellable, sellableStock: $sellable);
    }

    public function test_an_excluded_product_does_not_exist_on_the_website(): void
    {
        $decision = $this->decide(eligible: false);

        $this->assertFalse($decision->existsOnWebsite);
        $this->assertFalse($decision->purchasable);
        $this->assertSame(WebsiteDecisionMaker::REASON_EXCLUDED, $decision->unavailableReason);
    }

    public function test_an_excluded_product_has_no_stock_status_of_its_own(): void
    {
        $decision = $this->decide(eligible: false, stock: $this->stock(99.0));

        $this->assertSame(StockStatus::NotApplicable, $decision->stockStatus);
        $this->assertFalse($decision->manageStock);
        $this->assertNull($decision->websiteQuantity);
        $this->assertFalse($decision->isAvailableToBuy());
    }

    /**
     * Exclusion removes the product; a sales block only stops it being sold.
     */
    public function test_a_sales_blocked_product_stays_on_the_website_but_cannot_be_bought(): void
    {
        $decision = $this->decide(salesBlocked: true, stock: $this->stock(50.0));

        $this->assertTrue($decision->existsOnWebsite);
        $this->assertFalse($decision->purchasable);
        $this->assertSame(StockStatus::OutOfStock, $decision->stockStatus);
        $this->assertSame(WebsiteDecisionMaker::REASON_SALES_BLOCKED, $decision->unavailableReason);
    }

    /**
     * A blocked item must not keep a managed count: a later quantity write
     * would otherwise resolve it back into stock.
     */
    public function test_a_sales_blocked_product_stops_managing_stock(): void
    {
        $decision = $this->decide(salesBlocked: true, stock: $this->stock(50.0));

        $this->assertFalse($decision->manageStock);
        $this->assertNull($decision->websiteQuantity);
    }

    public function test_a_sales_block_overrides_non_inventory_and_forces_out_of_stock(): void
    {
        $decision = $this->decide(tracksInventory: false, salesBlocked: true);

        $this->assertTrue($decision->existsOnWebsite);
        $this->assertFalse($decision->purchasable);
        $this->assertSame(StockStatus::OutOfStock, $decision->stockStatus);
        $this->assertFalse($decision->isAvailableToBuy());
    }

    public function test_an_inventory_product_manages_stock_at_its_sellable_quantity(): void
    {
        $decision = $this->decide(stock: $this->stock(7.0));

        $this->assertTrue($decision->manageStock);
        $this->assertSame(7.0, $decision->websiteQuantity);
        $this->assertSame('7.00', $decision->quantityLabel());
    }

    public function test_an_inventory_product_with_sellable_stock_is_in_stock(): void
    {
        $decision = $this->decide(stock: $this->stock(1.0));

        $this->assertSame(StockStatus::InStock, $decision->stockStatus);
        $this->assertTrue($decision->purchasable);
        $this->assertTrue($decision->isAvailableToBuy());
        $this->assertNull($decision->unavailableReason);
    }

    public function test_an_inventory_product_without_sellable_stock_is_out_of_stock(): void
    {
        $decision = $this->decide(stock: $this->stock(0.0));

        $this->assertSame(StockStatus::OutOfStock, $decision->stockStatus);
        $this->assertSame(0.0, $decision->websiteQuantity);
        $this->assertSame(WebsiteDecisionMaker::REASON_NO_SELLABLE_STOCK, $decision->unavailableReason);
    }

    /**
     * It remains purchasable in principle: nothing about the product forbids
     * buying it, there is simply none to sell.
     */
    public function test_an_out_of_stock_inventory_product_is_still_purchasable_in_principle(): void
    {
        $decision = $this->decide(stock: $this->stock(0.0));

        $this->assertTrue($decision->purchasable);
        $this->assertFalse($decision->isAvailableToBuy());
    }

    public function test_a_non_inventory_product_does_not_manage_stock_and_is_in_stock(): void
    {
        $decision = $this->decide(tracksInventory: false);

        $this->assertTrue($decision->existsOnWebsite);
        $this->assertTrue($decision->purchasable);
        $this->assertFalse($decision->manageStock);
        $this->assertNull($decision->websiteQuantity);
        $this->assertSame(StockStatus::InStock, $decision->stockStatus);
        $this->assertTrue($decision->isAvailableToBuy());
    }

    /**
     * A non-inventory item carries no stock figures, so whatever a stale
     * quantity row says must not take it out of stock.
     */
    public function test_a_non_inventory_product_ignores_any_stock_figures(): void
    {
        $decision = $this->decide(tracksInventory: false, stock: $this->stock(0.0));

        $this->assertSame(StockStatus::InStock, $decision->stockStatus);
        $this->assertSame('Not tracked', $decision->quantityLabel());
    }

    /**
     * Quantities arrive from their own Business Central entity and can lag.
     * Guessing zero would read as "sold out", which is a different claim.
     */
    public function test_an_inventory_product_without_quantity_data_reports_it_rather_than_guessing(): void
    {
        $decision = $this->decide(stock: StockPosition::unknown());

        $this->assertTrue($decision->manageStock);
        $this->assertNull($decision->websiteQuantity);
        $this->assertSame(StockStatus::OutOfStock, $decision->stockStatus);
        $this->assertSame(WebsiteDecisionMaker::REASON_NO_QUANTITY_DATA, $decision->unavailableReason);
    }

    /**
     * Exclusion beats a sales block, which beats stock.
     *
     * @param  array{0: bool, 1: bool, 2: bool}  $inputs
     */
    #[DataProvider('precedenceCases')]
    public function test_the_rules_apply_in_the_documented_order(
        array $inputs,
        string $expectedReason,
    ): void {
        [$eligible, $tracksInventory, $salesBlocked] = $inputs;

        $decision = $this->decide(
            eligible: $eligible,
            tracksInventory: $tracksInventory,
            salesBlocked: $salesBlocked,
            stock: $this->stock(0.0),
        );

        $this->assertSame($expectedReason, $decision->unavailableReason);
    }

    /**
     * @return array<string, array{0: array{0: bool, 1: bool, 2: bool}, 1: string}>
     */
    public static function precedenceCases(): array
    {
        return [
            'excluded and sales blocked' => [[false, true, true], WebsiteDecisionMaker::REASON_EXCLUDED],
            'excluded non-inventory' => [[false, false, false], WebsiteDecisionMaker::REASON_EXCLUDED],
            'sales blocked beats stock' => [[true, true, true], WebsiteDecisionMaker::REASON_SALES_BLOCKED],
            'stock decides last' => [[true, true, false], WebsiteDecisionMaker::REASON_NO_SELLABLE_STOCK],
        ];
    }

    public function test_every_stock_status_has_a_label(): void
    {
        $this->assertSame('In stock', StockStatus::InStock->label());
        $this->assertSame('Out of stock', StockStatus::OutOfStock->label());
        $this->assertSame('Not applicable', StockStatus::NotApplicable->label());
    }
}
