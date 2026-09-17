<?php

namespace Database\Factories;

use App\Models\ProductMarketingText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductMarketingText>
 */
class ProductMarketingTextFactory extends Factory
{
    protected $model = ProductMarketingText::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->sentence().' '.fake()->sentence();

        return [
            'bc_id' => fake()->uuid(),
            'sku' => mb_strtoupper(fake()->bothify('??##')),
            'marketing_text' => $text,
            'short_description' => mb_substr($text, 0, mb_strpos($text, '.') + 1),
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'bc_payload' => [],
        ];
    }

    /**
     * An item whose copy has been cleared in Business Central.
     */
    public function empty(): static
    {
        return $this->state(fn (): array => [
            'marketing_text' => '',
            'short_description' => '',
        ]);
    }
}
