<?php

namespace Database\Factories;

use App\Models\ProductQuantity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductQuantity>
 */
class ProductQuantityFactory extends Factory
{
    protected $model = ProductQuantity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'sku' => mb_strtoupper(fake()->bothify('??##')),
            'type' => 'Inventory',
            'inventory' => 0,
            'qty_on_purchase_order' => 0,
            'qty_on_sales_order' => 0,
            'qty_on_transfer_order' => 0,
            'next_purchase_receipt_date' => null,
            'next_transfer_receipt_date' => null,
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'bc_payload' => [],
        ];
    }

    /**
     * Figures for an item held in stock with open orders against it.
     */
    public function withStock(int $inventory = 42, int $onSalesOrder = 8): static
    {
        return $this->state(fn (): array => [
            'inventory' => $inventory,
            'qty_on_sales_order' => $onSalesOrder,
        ]);
    }
}
