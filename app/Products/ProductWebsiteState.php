<?php

namespace App\Products;

use App\Products\Website\PricingRule;
use App\Products\Website\ProductAttribute;
use App\Products\Website\ProductLocation;
use App\Products\Website\StockPosition;
use App\Products\Website\StockStatus;
use App\Products\Website\TaxonomyTerm;
use App\Products\Website\WebsiteDecision;

/**
 * What a product should look like on the website, given the Business Central
 * data currently held.
 *
 * This is a read-only interpretation: nothing here writes to the website or to
 * Business Central. It exists so the interpretation of Business Central data can
 * be reviewed in one place, rather than being spread through the website.
 *
 * The final decision about how the product should appear lives on $decision:
 * whether it exists on the website, whether it can be bought, whether stock is
 * managed, the quantity and stock status the website should hold, and why a
 * customer cannot buy it when they cannot.
 */
final readonly class ProductWebsiteState
{
    /**
     * @param  list<PricingRule>  $pricingRules
     * @param  list<ProductAttribute>  $attributes
     */
    public function __construct(
        public EligibilityResult $eligibility,
        public string $sku,
        public string $name,
        public ?float $price,
        public string $inventoryType,
        public bool $salesBlocked,
        public ?string $barcode,
        public ProductLocation $location,
        public ?TaxonomyTerm $brand,
        public ?TaxonomyTerm $department,
        public ?TaxonomyTerm $category,
        public ?TaxonomyTerm $subcategory,
        public string $shippingClass,
        public ?float $rrp,
        public array $pricingRules,
        public array $attributes,
        public StockPosition $stock,
        public WebsiteDecision $decision,
    ) {}

    public function isEligible(): bool
    {
        return $this->eligibility->eligible;
    }

    /**
     * Whether stock is tracked for this product.
     *
     * Non-inventory items are never stock tracked; Business Central requires
     * their stockkeeping units to be removed before the type can change, which
     * is why they carry no location signal of their own.
     */
    public function tracksInventory(): bool
    {
        return $this->inventoryType === ProductWebsiteStateBuilder::TYPE_INVENTORY;
    }

    /**
     * Whether the product should be present on the website at all.
     */
    public function existsOnWebsite(): bool
    {
        return $this->decision->existsOnWebsite;
    }

    /**
     * Whether customers may buy this product, ignoring stock levels.
     *
     * A sales block leaves the product listed and browsable but not purchasable,
     * unlike exclusion, which removes it from the website entirely.
     */
    public function isPurchasable(): bool
    {
        return $this->decision->purchasable;
    }

    /**
     * Whether the website should track a stock count for this product.
     */
    public function managesStock(): bool
    {
        return $this->decision->manageStock;
    }

    /**
     * The stock count the website should hold, or null when untracked.
     */
    public function websiteQuantity(): ?float
    {
        return $this->decision->websiteQuantity;
    }

    /**
     * The stock status the website should show.
     */
    public function stockStatus(): StockStatus
    {
        return $this->decision->stockStatus;
    }

    /**
     * Why a customer cannot buy this right now, or null when they can.
     */
    public function unavailableReason(): ?string
    {
        return $this->decision->unavailableReason;
    }

    /**
     * Whether a customer could buy this product right now.
     */
    public function isAvailableToBuy(): bool
    {
        return $this->decision->isAvailableToBuy();
    }

    /**
     * The category path, from department down to subcategory.
     *
     * @return list<TaxonomyTerm>
     */
    public function categoryPath(): array
    {
        return array_values(array_filter([$this->department, $this->category, $this->subcategory]));
    }
}
