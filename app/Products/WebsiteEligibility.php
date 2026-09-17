<?php

namespace App\Products;

use App\Models\Product;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a product currently meets the criteria to appear on the website.
 *
 * These rules used to live in the Business Central query, which meant a product
 * failing any of them was never fetched and could not be explained. They are
 * applied here instead, so every item is imported and the dashboard can say
 * exactly why one is not on the website.
 *
 * Every rule is evaluated: a product may fail several, and reporting only the
 * first would send someone to fix one thing and leave the product still excluded.
 */
class WebsiteEligibility
{
    /**
     * Business Central category used to retire a product from sale.
     */
    public const RETIRE_CATEGORY = 'RETIRE';

    /**
     * The only product group the website sells from.
     */
    public const FINISHED_GOODS = 'FINISHED GOODS';

    /**
     * Business Central item types the website can represent.
     *
     * Anything else (Service, Assembly) has no stock or shipping behaviour the
     * website knows how to model.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_TYPES = [
        ProductWebsiteStateBuilder::TYPE_INVENTORY,
        ProductWebsiteStateBuilder::TYPE_NON_INVENTORY,
    ];

    /**
     * Every reason this product does not qualify for the website.
     */
    public function for(Product $product): EligibilityResult
    {
        $exclusions = [];

        if ($product->blocked) {
            $exclusions[] = new Exclusion(ExclusionReason::Blocked);
        }

        if ($this->isRetired($product)) {
            $exclusions[] = new Exclusion(ExclusionReason::Retired);
        }

        if ((float) $product->price <= 0.0) {
            $exclusions[] = new Exclusion(ExclusionReason::NoPrice);
        }

        if (! $this->hasSupportedType($product)) {
            $exclusions[] = new Exclusion(ExclusionReason::UnsupportedType, $this->displayType($product));
        }

        if (! $this->isFinishedGoods($product)) {
            $exclusions[] = new Exclusion(ExclusionReason::NotFinishedGoods, trim((string) $product->gppg));
        }

        return EligibilityResult::excluded($exclusions);
    }

    public function isEligible(Product $product): bool
    {
        return $this->for($product)->eligible;
    }

    /**
     * Constrain a query to products that do not meet the website criteria.
     *
     * Kept alongside the per-product rules so the dashboard counts and the detail
     * page can never disagree; a test asserts the two select the same products.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeExcluded(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('blocked', true)
                ->orWhereRaw("UPPER(TRIM(COALESCE(item_category_id, ''))) = ?", [self::RETIRE_CATEGORY])
                ->orWhere('price', '<=', 0)
                ->orWhereNotIn($this->normalizedType(), self::SUPPORTED_TYPES)
                ->orWhereRaw("UPPER(TRIM(COALESCE(gppg, ''))) <> ?", [self::FINISHED_GOODS]);
        });
    }

    /**
     * Constrain a query to products that meet every website criterion.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeEligible(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $query): Builder => $this->scopeExcluded($query));
    }

    /**
     * Constrain a query to products excluded for one particular reason.
     *
     * A product may fail several rules, so this matches every product whose
     * reasons include this one rather than only those failing it alone.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeWithReason(Builder $query, ExclusionReason $reason): Builder
    {
        return match ($reason) {
            ExclusionReason::Blocked => $query->where('blocked', true),
            ExclusionReason::Retired => $query->whereRaw(
                "UPPER(TRIM(COALESCE(item_category_id, ''))) = ?",
                [self::RETIRE_CATEGORY],
            ),
            ExclusionReason::NoPrice => $query->where('price', '<=', 0),
            ExclusionReason::UnsupportedType => $query->whereNotIn(
                $this->normalizedType(),
                self::SUPPORTED_TYPES,
            ),
            ExclusionReason::NotFinishedGoods => $query->whereRaw(
                "UPPER(TRIM(COALESCE(gppg, ''))) <> ?",
                [self::FINISHED_GOODS],
            ),
        };
    }

    /**
     * SQL normalising the stored type the same way the per-product rule does.
     *
     * Business Central escapes the hyphen in "Non-Inventory", and an empty type
     * is treated as non-inventory, so both have to be folded before comparison.
     */
    private function normalizedType(): Expression
    {
        $nonInventory = ProductWebsiteStateBuilder::TYPE_NON_INVENTORY;

        return DB::raw(
            "CASE WHEN TRIM(COALESCE(type, '')) IN ('', 'Non_x002D_Inventory') THEN '{$nonInventory}' "
            .'ELSE UPPER(TRIM(type)) END'
        );
    }

    private function isRetired(Product $product): bool
    {
        return mb_strtoupper(trim((string) $product->item_category_id)) === self::RETIRE_CATEGORY;
    }

    /**
     * Whether the item is one the website can represent.
     */
    private function hasSupportedType(Product $product): bool
    {
        return in_array($this->normalizeType($product), self::SUPPORTED_TYPES, true);
    }

    private function isFinishedGoods(Product $product): bool
    {
        return mb_strtoupper(trim((string) $product->gppg)) === self::FINISHED_GOODS;
    }

    /**
     * Normalise the stored type the way the website state does, so the two
     * cannot disagree about what "Non-Inventory" means.
     */
    private function normalizeType(Product $product): string
    {
        $type = trim((string) $product->type);

        if ($type === '' || $type === 'Non_x002D_Inventory') {
            return ProductWebsiteStateBuilder::TYPE_NON_INVENTORY;
        }

        return mb_strtoupper($type);
    }

    /**
     * The type as Business Central spells it, for the exclusion message.
     */
    private function displayType(Product $product): string
    {
        return str_replace('_x002D_', '-', trim((string) $product->type));
    }
}
