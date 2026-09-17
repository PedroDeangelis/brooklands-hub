<?php

namespace App\Products\Website;

use DateTimeImmutable;

/**
 * Turns Business Central price list lines into customer pricing rules and the RRP.
 *
 * A line is either a fixed price for a customer group or a percentage discount.
 * Lines with a sales code of RRP are not pricing rules: they carry the
 * recommended retail price used for display.
 */
class PricingRuleBuilder
{
    public const string RRP_SALES_CODE = 'RRP';

    /**
     * Business Central dates for "no date set".
     *
     * @var list<string>
     */
    private const EMPTY_DATES = ['0001-01-01', '0000-00-00'];

    /**
     * @param  array<int, mixed>  $priceListLines
     * @return array{rules: list<PricingRule>, rrp: float|null}
     */
    public function build(array $priceListLines): array
    {
        $rules = [];
        $rrp = null;

        foreach ($priceListLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $salesCode = trim((string) ($line['salesCode'] ?? ''));

            if ($salesCode === '') {
                continue;
            }

            $unitPrice = $this->floatValue($line['unitPrice'] ?? null);
            $discount = $this->floatValue($line['lineDiscountPercent'] ?? null);
            $amountType = $this->normaliseAmountType($line['amountType'] ?? null);

            if (strcasecmp($salesCode, self::RRP_SALES_CODE) === 0) {
                if ($unitPrice > 0) {
                    $rrp = $unitPrice;
                }

                continue;
            }

            $rule = $this->buildRule($line, $salesCode, $amountType, $unitPrice, $discount);

            if ($rule instanceof PricingRule) {
                $rules[] = $rule;
            }
        }

        return ['rules' => $this->sort($rules), 'rrp' => $rrp];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function buildRule(array $line, string $salesCode, string $amountType, float $unitPrice, float $discount): ?PricingRule
    {
        $pricingType = $this->pricingType($amountType, $unitPrice);

        // Business Central serialises the "Price & Discount" amount type by its
        // enum member name, "Any": the line defines both a unit price and a line
        // discount, and Business Central charges the price minus that discount.
        // Record it as a fixed price at the effective value so the website applies
        // it unchanged, keeping the discount for reference.
        if ($this->definesPriceAndDiscount($amountType) && $unitPrice > 0 && $discount > 0) {
            return $this->makeRule($line, $salesCode, PricingRule::TYPE_FIXED_PRICE, [
                'price' => round($unitPrice * (1 - $discount / 100), 4),
                'amount' => $discount,
            ]);
        }

        if ($pricingType === PricingRule::TYPE_FIXED_PRICE && $this->hasFixedPrice($amountType, $unitPrice)) {
            return $this->makeRule($line, $salesCode, $pricingType, ['price' => $unitPrice]);
        }

        if ($pricingType === PricingRule::TYPE_DISCOUNT && $this->hasDiscount($amountType, $discount)) {
            return $this->makeRule($line, $salesCode, $pricingType, ['amount' => $discount]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array{price?: float, amount?: float}  $effect
     */
    private function makeRule(array $line, string $salesCode, string $pricingType, array $effect): PricingRule
    {
        return new PricingRule(
            id: trim((string) ($line['id'] ?? '')),
            salesType: trim((string) ($line['salesType'] ?? '')),
            salesCode: $salesCode,
            pricingType: $pricingType,
            unitOfMeasureCode: trim((string) ($line['unitOfMeasureCode'] ?? '')),
            minimumQuantity: $this->minimumQuantity($line['minimumQuantity'] ?? null),
            startingDate: $this->normaliseDate($line['startingDate'] ?? null),
            endingDate: $this->normaliseDate($line['endingDate'] ?? null),
            currencyCode: trim((string) ($line['currencyCode'] ?? '')),
            lastModifiedDateTime: trim((string) ($line['lastModifiedDateTime'] ?? '')),
            price: $effect['price'] ?? null,
            amount: $effect['amount'] ?? null,
        );
    }

    /**
     * Group the rules the way the website reads them: by sales type, then code,
     * then the quantity break.
     *
     * @param  list<PricingRule>  $rules
     * @return list<PricingRule>
     */
    private function sort(array $rules): array
    {
        usort($rules, static function (PricingRule $left, PricingRule $right): int {
            return [mb_strtolower($left->salesType), mb_strtolower($left->salesCode), $left->minimumQuantity]
                <=> [mb_strtolower($right->salesType), mb_strtolower($right->salesCode), $right->minimumQuantity];
        });

        return $rules;
    }

    private function pricingType(string $amountType, float $unitPrice): string
    {
        if (str_contains($amountType, 'discount') || $unitPrice <= 0) {
            return PricingRule::TYPE_DISCOUNT;
        }

        return PricingRule::TYPE_FIXED_PRICE;
    }

    private function definesPriceAndDiscount(string $amountType): bool
    {
        if ($amountType === 'any') {
            return true;
        }

        return str_contains($amountType, 'price') && str_contains($amountType, 'discount');
    }

    private function hasFixedPrice(string $amountType, float $unitPrice): bool
    {
        return $unitPrice > 0 && ! str_contains($amountType, 'discount');
    }

    private function hasDiscount(string $amountType, float $discount): bool
    {
        return $discount > 0 && ! str_contains($amountType, 'price');
    }

    /**
     * Business Central escapes spaces and punctuation in enum names
     * ("Price_x0020_Discount"), so flatten to lowercase words before matching.
     */
    private function normaliseAmountType(mixed $value): string
    {
        $amountType = trim((string) $value);

        if ($amountType === '') {
            return '';
        }

        $amountType = str_replace(['_x0020_', 'x002e'], ' ', $amountType);
        $amountType = str_replace(['_', '-', '.'], ' ', $amountType);

        return preg_replace('/\s+/', ' ', mb_strtolower($amountType)) ?: '';
    }

    private function normaliseDate(mixed $value): ?string
    {
        $date = trim((string) $value);

        if ($date === '' || in_array($date, self::EMPTY_DATES, true)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        $hasErrors = is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if (! $parsed || $hasErrors) {
            return null;
        }

        return $parsed->format('Y-m-d');
    }

    private function minimumQuantity(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
