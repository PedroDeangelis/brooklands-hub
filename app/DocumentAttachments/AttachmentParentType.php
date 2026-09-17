<?php

namespace App\DocumentAttachments;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * The kind of record a document attachment hangs off.
 *
 * Business Central names the parent type as free text on every attachment row
 * ("Item", "Customer", "Sales Order"), and the id alone is not enough to place
 * a file: ids are only unique within a type, so two records of different types
 * could in principle carry the same GUID.
 *
 * This is the one place that maps that text onto a local model, a website
 * entity and a label. Everything downstream — the importer, the payload, the
 * list page — reads it from here rather than matching on the raw string, so a
 * fourth parent type is added once.
 *
 * The cases are deliberately limited to what the website can actually store.
 * Business Central attaches documents to purchase documents, journals and
 * resources as well; those have nowhere to go, so they are not imported.
 */
enum AttachmentParentType: string
{
    case Product = 'product';

    case Customer = 'customer';

    case SalesOrder = 'sales_order';

    /**
     * Read Business Central's parentType text.
     *
     * Null for anything we cannot place, which the importer treats as a row to
     * skip rather than an error: an attachment on a purchase invoice is a
     * perfectly valid record that simply has no home here.
     */
    public static function fromBusinessCentral(?string $parentType): ?self
    {
        return match (mb_strtolower(trim((string) $parentType))) {
            'item' => self::Product,
            'customer' => self::Customer,
            'sales order', 'salesorder' => self::SalesOrder,
            default => null,
        };
    }

    /**
     * The Business Central name for this type, as it appears in a filter.
     */
    public function businessCentralName(): string
    {
        return match ($this) {
            self::Product => 'Item',
            self::Customer => 'Customer',
            self::SalesOrder => 'Sales Order',
        };
    }

    /**
     * The local model that carries this parent's Business Central id.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Product => Product::class,
            self::Customer => Customer::class,
            self::SalesOrder => SalesOrder::class,
        };
    }

    /**
     * The website entity whose delivery carries this parent's attachments.
     *
     * Attachments are not delivered on their own. They are a field of the
     * parent record — a repeater on the product, customer or order post — so
     * the delivery that carries them is the parent's own. This names which one.
     */
    public function websiteEntity(): string
    {
        return match ($this) {
            self::Product => 'product',
            self::Customer => 'customer',
            self::SalesOrder => 'sales_order',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Customer => 'Customer',
            self::SalesOrder => 'Sales order',
        };
    }

    /**
     * How to name one of these records in a list.
     *
     * Each model says this differently — a customer and an order both have a
     * title(), a product has a SKU and a name — so the rule lives here with the
     * rest of what differs per type rather than in the view.
     */
    public function labelFor(Model $parent): string
    {
        return match ($this) {
            self::Product => trim(sprintf('%s — %s', $parent->sku, $parent->name), ' —') ?: (string) $parent->bc_id,
            self::Customer, self::SalesOrder => $parent->title(),
        };
    }

    /**
     * The route that shows this parent, given its local key.
     */
    public function routeName(): string
    {
        return match ($this) {
            self::Product => 'products.show',
            self::Customer => 'customers.show',
            self::SalesOrder => 'sales-orders.show',
        };
    }
}
