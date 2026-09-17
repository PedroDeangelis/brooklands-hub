<?php

namespace App\Sync\Payload;

use App\Models\Product;
use App\Products\ProductWebsiteState;
use App\Products\ProductWebsiteStateBuilder;
use App\Products\Website\PricingRule;
use App\Products\Website\ProductAttribute;
use App\Products\Website\TaxonomyTerm;
use App\Sync\DesiredWebsiteState;
use App\Sync\WebsiteAction;

/**
 * Builds the payload the website should be given for a product.
 *
 * This is a translation step and nothing more. Every value here has already been
 * decided by a domain class: eligibility by WebsiteEligibility, stock and status
 * by WebsiteDecisionMaker, locations and taxonomies by ProductWebsiteStateBuilder.
 * No Business Central field is read or reinterpreted here, so there is exactly
 * one place where each rule lives.
 *
 * Payloads are deterministic: the same state always produces the same keys in
 * the same shape, so two payloads can be compared or hashed safely.
 */
class ProductWebsitePayloadBuilder
{
    public function __construct(
        private readonly ProductWebsiteStateBuilder $states,
    ) {}

    /**
     * The complete payload for a product, in whichever form its desired state calls for.
     *
     * @return array<string, mixed>
     */
    public function build(Product $product): array
    {
        $state = $this->states->build($product);
        $desired = DesiredWebsiteState::from($state->eligibility);

        return $desired->action === WebsiteAction::Remove
            ? $this->removePayload($product, $desired)
            : $this->upsertPayload($product, $state);
    }

    /**
     * The values the website should hold for a product that belongs on it.
     *
     * @return array<string, mixed>
     */
    public function upsertPayload(Product $product, ProductWebsiteState $state): array
    {
        return [
            'bc_id' => (string) $product->bc_id,
            'sku' => $state->sku,
            'name' => $state->name,
            'name_2' => trim((string) $product->name_2),
            'price' => $state->price,
            'rrp' => $state->rrp,
            'purchasable' => $state->isPurchasable(),
            'manage_stock' => $state->managesStock(),
            'website_quantity' => $state->websiteQuantity(),
            'stock_status' => $state->stockStatus()->value,
            'inventory_type' => $state->inventoryType,
            'barcode' => $state->barcode,
            'location' => $this->location($state),
            'brand' => $this->term($state->brand),
            'categories' => $this->categories($state),
            'shipping_class' => $state->shippingClass,
            'attributes' => $this->attributes($state->attributes),
            'group_prices' => $this->groupPrices($state->pricingRules),
        ];
    }

    /**
     * The payload for a product that should not be on the website.
     *
     * Deliberately minimal, and deliberately carries the reasons as codes rather
     * than sentences: Laravel has already decided the product must go, and the
     * website is told what to do, not asked to work it out again. The codes are
     * there so a removal can be explained later without re-deriving anything.
     *
     * @return array<string, mixed>
     */
    public function removePayload(Product $product, DesiredWebsiteState $desired): array
    {
        return [
            'bc_id' => (string) $product->bc_id,
            'sku' => (string) $product->sku,
            'reasons' => $desired->reasonCodes(),
        ];
    }

    /**
     * The selected location, or null when none resolved.
     *
     * Treated atomically by the diff: code and inventory only mean anything
     * together, so a changed location is sent whole.
     *
     * @return array<string, mixed>|null
     */
    private function location(ProductWebsiteState $state): ?array
    {
        return $state->location->isResolved()
            ? ['code' => $state->location->code, 'inventory' => $state->location->inventory]
            : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function term(?TaxonomyTerm $term): ?array
    {
        return $term instanceof TaxonomyTerm
            ? ['code' => $term->code, 'title' => $term->title]
            : null;
    }

    /**
     * The category path, keyed by level so a website can place each term.
     *
     * @return array<string, array<string, string>|null>
     */
    private function categories(ProductWebsiteState $state): array
    {
        return [
            'department' => $this->term($state->department),
            'category' => $this->term($state->category),
            'subcategory' => $this->term($state->subcategory),
        ];
    }

    /**
     * @param  list<ProductAttribute>  $attributes
     * @return list<array<string, string>>
     */
    private function attributes(array $attributes): array
    {
        return array_map(
            static fn (ProductAttribute $attribute): array => [
                'name' => $attribute->name,
                'value' => $attribute->value,
            ],
            $attributes,
        );
    }

    /**
     * @param  list<PricingRule>  $rules
     * @return list<array<string, mixed>>
     */
    private function groupPrices(array $rules): array
    {
        return array_map(static fn (PricingRule $rule): array => $rule->toArray(), $rules);
    }
}
