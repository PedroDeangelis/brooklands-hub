<?php

namespace App\Products\Website;

/**
 * One customer pricing rule derived from a Business Central price list line.
 *
 * Either a fixed price or a percentage discount. A line that defines both is
 * collapsed into a fixed price at the effective value, matching how Business
 * Central charges it.
 */
final readonly class PricingRule
{
    public const string TYPE_FIXED_PRICE = 'fixed_price';

    public const string TYPE_DISCOUNT = 'discount';

    public function __construct(
        public string $id,
        public string $salesType,
        public string $salesCode,
        public string $pricingType,
        public string $unitOfMeasureCode,
        public int $minimumQuantity,
        public ?string $startingDate,
        public ?string $endingDate,
        public string $currencyCode,
        public string $lastModifiedDateTime,
        public ?float $price = null,
        public ?float $amount = null,
    ) {}

    public function isFixedPrice(): bool
    {
        return $this->pricingType === self::TYPE_FIXED_PRICE;
    }

    /**
     * The sales type as a reader would say it.
     *
     * Business Central escapes spaces in enum names, so the raw value arrives as
     * "Customer_x0020_Price_x0020_Group".
     */
    public function salesTypeLabel(): string
    {
        $label = trim(str_replace(['_x0020_', '_'], ' ', $this->salesType));

        return preg_replace('/\s+/', ' ', $label) ?: '';
    }

    /**
     * The rule's effect, formatted for display.
     */
    public function label(): string
    {
        if ($this->isFixedPrice() && $this->price !== null) {
            return number_format($this->price, 2);
        }

        if ($this->amount !== null) {
            return rtrim(rtrim(number_format($this->amount, 2, '.', ''), '0'), '.').'% off';
        }

        return '—';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'sales_type' => $this->salesType,
            'sales_code' => $this->salesCode,
            'pricing_type' => $this->pricingType,
            'unit_of_measure_code' => $this->unitOfMeasureCode,
            'minimum_quantity' => $this->minimumQuantity,
            'starting_date' => $this->startingDate,
            'ending_date' => $this->endingDate,
            'currency_code' => $this->currencyCode,
            'last_modified_datetime' => $this->lastModifiedDateTime,
            'price' => $this->price,
            'amount' => $this->amount,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
