<?php

namespace App\Products;

use App\Models\Product;
use App\Products\Website\LocationSelector;
use App\Products\Website\PricingRuleBuilder;
use App\Products\Website\ProductAttribute;
use App\Products\Website\ProductLocation;
use App\Products\Website\StockCalculator;
use App\Products\Website\StockPosition;
use App\Products\Website\TaxonomyTerm;
use App\Products\Website\WebsiteDecisionMaker;

/**
 * Derives the website representation of a product from its Business Central data.
 *
 * These rules were previously applied while writing to the website, which made
 * them impossible to inspect without querying the website itself. Reading them
 * here keeps the interpretation reviewable and testable.
 *
 * Scalar values come from the product's own columns; anything Business Central
 * returns as a nested collection (locations, dimensions, price lines,
 * attributes) is read from the stored payload.
 */
class ProductWebsiteStateBuilder
{
    public const string TYPE_INVENTORY = 'INVENTORY';

    public const string TYPE_NON_INVENTORY = 'NON-INVENTORY';

    public const string SHIPPING_STANDARD = 'standard';

    public const string SHIPPING_NON_STANDARD = 'non-standard';

    /**
     * Business Central escapes the hyphen in "Non-Inventory" when serialising the type.
     */
    private const BC_NON_INVENTORY = 'Non_x002D_Inventory';

    private const DIMENSION_SHIPPING_TYPE = 'SHIPPING TYPE';

    private const DIMENSION_BRAND = 'BRAND';

    private const DIMENSION_DEPARTMENT = 'DEPARTMENT';

    private const DIMENSION_CATEGORY = 'CATEGORY';

    private const DIMENSION_SUBCATEGORY = 'SUBCATEGORY';

    private const SHIPPING_NON_STANDARD_CODE = 'NON-STANDARD';

    public function __construct(
        private readonly WebsiteEligibility $eligibility,
        private readonly LocationSelector $locations,
        private readonly PricingRuleBuilder $pricing,
        private readonly StockCalculator $stock,
        private readonly WebsiteDecisionMaker $decisions,
    ) {}

    public function build(Product $product): ProductWebsiteState
    {
        $payload = is_array($product->bc_payload) ? $product->bc_payload : [];

        $dimensions = $this->readDimensions($payload);
        $inventoryType = $this->inventoryType($product);
        $pricing = $this->pricing->build($this->collection($payload, 'priceListLines'));
        $location = $this->location($payload, $inventoryType);

        $eligibility = $this->eligibility->for($product);
        $stock = $this->stock($product, $location);
        $salesBlocked = (bool) $product->sales_blocked;
        $tracksInventory = $inventoryType === self::TYPE_INVENTORY;

        return new ProductWebsiteState(
            eligibility: $eligibility,
            sku: (string) $product->sku,
            name: (string) $product->name,
            price: $this->price($product),
            inventoryType: $inventoryType,
            salesBlocked: $salesBlocked,
            barcode: $this->barcode($product),
            location: $location,
            brand: $dimensions[self::DIMENSION_BRAND] ?? null,
            department: $dimensions[self::DIMENSION_DEPARTMENT] ?? null,
            category: $dimensions[self::DIMENSION_CATEGORY] ?? null,
            subcategory: $dimensions[self::DIMENSION_SUBCATEGORY] ?? null,
            shippingClass: $this->shippingClass($dimensions),
            rrp: $pricing['rrp'],
            pricingRules: $pricing['rules'],
            attributes: $this->attributes($payload),
            stock: $stock,
            decision: $this->decisions->decide($eligibility, $tracksInventory, $salesBlocked, $stock),
        );
    }

    /**
     * Stock figures arrive from a separate Business Central entity, so they may
     * legitimately be missing: a product can be imported before its quantities.
     */
    private function stock(Product $product, ProductLocation $location): StockPosition
    {
        $quantity = $product->relationLoaded('quantity')
            ? $product->getRelation('quantity')
            : $product->quantity()->first();

        return $quantity === null
            ? StockPosition::unknown()
            : $this->stock->calculate($quantity, $location);
    }

    /**
     * A price of zero is not published: Business Central uses it for items that
     * have no sell price set, and the website would otherwise advertise $0.00.
     */
    private function price(Product $product): ?float
    {
        $price = (float) $product->price;

        return $price > 0 ? $price : null;
    }

    private function inventoryType(Product $product): string
    {
        $type = trim((string) $product->type);

        if ($type === '' || $type === self::BC_NON_INVENTORY) {
            $type = self::TYPE_NON_INVENTORY;
        }

        return mb_strtoupper($type);
    }

    private function barcode(Product $product): ?string
    {
        $gtin = trim((string) $product->gtin);

        return $gtin === '' ? null : $gtin;
    }

    /**
     * Non-inventory items default to the general location: Business Central
     * requires their stockkeeping units to be removed before the type can
     * change, so they carry no location of their own, and there is no stock to
     * cap for an untracked item.
     *
     * @param  array<string, mixed>  $payload
     */
    private function location(array $payload, string $inventoryType): ProductLocation
    {
        if ($inventoryType === self::TYPE_NON_INVENTORY) {
            return new ProductLocation(LocationSelector::BROOKLANDS, 0);
        }

        return $this->locations->select($this->collection($payload, 'stockkeepingUnits'));
    }

    /**
     * Index the dimensions that drive website taxonomies by dimension code.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, TaxonomyTerm>
     */
    private function readDimensions(array $payload): array
    {
        $terms = [];

        foreach ($this->collection($payload, 'itemDefaultDimensions') as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $code = mb_strtoupper(trim((string) ($dimension['dimensionCode'] ?? '')));
            $term = TaxonomyTerm::fromDimension($dimension);

            if ($code === '' || ! $term instanceof TaxonomyTerm) {
                continue;
            }

            $terms[$code] = $term;
        }

        return $terms;
    }

    /**
     * @param  array<string, TaxonomyTerm>  $dimensions
     */
    private function shippingClass(array $dimensions): string
    {
        $shippingType = $dimensions[self::DIMENSION_SHIPPING_TYPE] ?? null;

        return $shippingType instanceof TaxonomyTerm
            && mb_strtoupper($shippingType->code) === self::SHIPPING_NON_STANDARD_CODE
                ? self::SHIPPING_NON_STANDARD
                : self::SHIPPING_STANDARD;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ProductAttribute>
     */
    private function attributes(array $payload): array
    {
        $attributes = [];

        foreach ($this->collection($payload, 'itemAttributes') as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $value = trim((string) ($attribute['itemAttributeValueName'] ?? ''));

            // A named attribute with no value says nothing about the product.
            if ($value === '') {
                continue;
            }

            $attributes[] = new ProductAttribute(
                trim((string) ($attribute['itemAttributeName'] ?? '')),
                $value,
            );
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, mixed>
     */
    private function collection(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
