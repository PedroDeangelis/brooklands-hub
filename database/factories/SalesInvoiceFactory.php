<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\SalesInvoices\SalesInvoiceKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInvoice>
 */
class SalesInvoiceFactory extends Factory
{
    protected $model = SalesInvoice::class;

    /**
     * A posted invoice with one line.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'number' => mb_strtoupper(fake()->unique()->bothify('INV######')),
            'kind' => SalesInvoiceKind::Invoice->value,
            'document_api_id' => null,
            'order_number' => mb_strtoupper(fake()->bothify('SO######')),
            'customer_bc_id' => fake()->uuid(),
            'customer_name' => fake()->company(),
            'invoice_date' => fake()->dateTimeBetween('-1 month'),
            'order_date' => fake()->dateTimeBetween('-2 months', '-1 month'),
            'external_document_number' => '',
            'total_amount_excluding_tax' => 100,
            'total_tax_amount' => 15,
            'total_amount_including_tax' => 115,
            'ship_to_address_1' => fake()->streetAddress(),
            'ship_to_address_2' => '',
            'ship_to_city' => fake()->city(),
            'ship_to_state' => '',
            'ship_to_post_code' => (string) fake()->numberBetween(1000, 9999),
            'work_description' => '',
            'lines' => [self::line()],
            'bc_created_at' => fake()->dateTimeBetween('-1 month'),
            'bc_modified_at' => fake()->dateTimeBetween('-1 month'),
            'bc_payload' => [],
        ];
    }

    /**
     * One normalised line, as the importer stores it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function line(array $overrides = []): array
    {
        return array_replace([
            'bc_id' => fake()->uuid(),
            'sequence' => 10000,
            'item_bc_id' => fake()->uuid(),
            'item_number' => 'ITEM0001',
            'description' => 'A product',
            'quantity' => 2.0,
            'unit_price' => 50.0,
            'quantity_to_ship' => 0.0,
            'quantity_shipped' => 0.0,
            'quantity_invoiced' => 0.0,
        ], $overrides);
    }

    /**
     * A posted credit memo: the same row shape, its own kind, no order date,
     * and the standard-API GUID the website builds the PDF link from.
     */
    public function creditMemo(): static
    {
        return $this->state(fn (): array => [
            'number' => mb_strtoupper(fake()->unique()->bothify('CM######')),
            'kind' => SalesInvoiceKind::CreditMemo->value,
            'document_api_id' => fake()->uuid(),
            'order_number' => '',
            'order_date' => null,
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'customer_bc_id' => $customer->bc_id,
            'customer_name' => $customer->display_name,
        ]);
    }

    public function forOrder(SalesOrder $salesOrder): static
    {
        return $this->state(fn (): array => [
            'order_number' => $salesOrder->number,
            'customer_bc_id' => $salesOrder->customer_bc_id,
            'customer_name' => $salesOrder->customer_name,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->state(fn (): array => ['lines' => $lines]);
    }
}
