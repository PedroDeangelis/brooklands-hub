<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\SalesOrders\SalesOrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    /**
     * A released order with one line that has not shipped yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'number' => mb_strtoupper(fake()->unique()->bothify('SO######')),
            'customer_bc_id' => fake()->uuid(),
            'customer_name' => fake()->company(),
            'order_date' => fake()->dateTimeBetween('-1 month'),
            'bc_status' => 'Released',
            'website_status' => SalesOrderStatus::Processing->value,
            'external_document_number' => '',
            'total_amount_excluding_tax' => 100,
            'total_tax_amount' => 15,
            'total_amount_including_tax' => 115,
            'fully_shipped' => false,
            'ship_to_address_1' => fake()->streetAddress(),
            'ship_to_address_2' => '',
            'ship_to_city' => fake()->city(),
            'ship_to_state' => '',
            'ship_to_post_code' => (string) fake()->numberBetween(1000, 9999),
            'work_description' => '',
            'lines' => [self::line()],
            'shipments' => [],
            'invoices' => [],
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
            'quantity_to_ship' => 2.0,
            'quantity_shipped' => 0.0,
            'quantity_invoiced' => 0.0,
        ], $overrides);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'customer_bc_id' => $customer->bc_id,
            'customer_name' => $customer->display_name,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->state(fn (array $attributes): array => [
            'lines' => $lines,
            'website_status' => SalesOrderStatus::derive((string) ($attributes['bc_status'] ?? 'Released'), $lines)->value,
        ]);
    }

    public function withStatus(string $bcStatus): static
    {
        return $this->state(fn (array $attributes): array => [
            'bc_status' => $bcStatus,
            'website_status' => SalesOrderStatus::derive($bcStatus, $attributes['lines'] ?? [])->value,
        ]);
    }

    public function completed(): static
    {
        return $this->withLines([self::line(['quantity_shipped' => 2.0, 'quantity_invoiced' => 2.0])]);
    }
}
