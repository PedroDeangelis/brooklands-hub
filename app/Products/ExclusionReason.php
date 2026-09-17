<?php

namespace App\Products;

/**
 * A single reason a product does not qualify for the website.
 *
 * Reasons are values rather than free text so the dashboard can group and count
 * them, and so a reason can be recognised again after the wording changes.
 */
enum ExclusionReason: string
{
    /** Business Central has blocked the item outright. */
    case Blocked = 'blocked';

    /** The item sits in the category used to retire products from sale. */
    case Retired = 'retired';

    /** No sell price is set, so the website has nothing to charge. */
    case NoPrice = 'no_price';

    /** The item is not a kind of product the website can sell. */
    case UnsupportedType = 'unsupported_type';

    /** The item is not part of the finished goods the website sells. */
    case NotFinishedGoods = 'not_finished_goods';

    /**
     * The sentence shown to a person reading the dashboard.
     *
     * The value carries any detail from the product itself, so "Service" and
     * "Assembly" read as different reasons rather than one generic message.
     */
    public function describe(?string $value = null): string
    {
        $value = trim((string) $value);

        return match ($this) {
            self::Blocked => 'Product is blocked in Business Central',
            self::Retired => 'Product belongs to the RETIRE category',
            self::NoPrice => 'Product has no selling price',
            self::UnsupportedType => $value === ''
                ? 'Product type is not supported'
                : sprintf('Product type "%s" is not supported', $value),
            self::NotFinishedGoods => $value === ''
                ? 'Product is not classified as FINISHED GOODS'
                : sprintf('Product is not classified as FINISHED GOODS (%s)', $value),
        };
    }

    /**
     * A short label for grouping in lists, without the product-specific detail.
     */
    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Blocked',
            self::Retired => 'Retired',
            self::NoPrice => 'No price',
            self::UnsupportedType => 'Unsupported type',
            self::NotFinishedGoods => 'Not finished goods',
        };
    }
}
