<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'sku' => mb_strtoupper(fake()->bothify('??##')),
            'name' => fake()->words(3, true),
            'name_2' => '',
            'type' => 'Inventory',
            'price' => fake()->randomFloat(2, 1, 500),
            'inventory' => fake()->numberBetween(0, 100),
            'weight' => 0,
            'blocked' => false,
            'sales_blocked' => false,
            'gtin' => '',
            'item_category_id' => '',
            'gppg' => 'FINISHED GOODS',
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'bc_payload' => [],
        ];
    }
}
