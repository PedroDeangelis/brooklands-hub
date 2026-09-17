<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'number' => mb_strtoupper(fake()->unique()->bothify('CUST####')),
            'display_name' => fake()->company(),
            'type' => 'Company',
            'address_1' => fake()->streetAddress(),
            'address_2' => '',
            'city' => fake()->city(),
            'state' => '',
            'postal_code' => (string) fake()->numberBetween(1000, 9999),
            'country' => 'NZ',
            'phone' => '',
            'email' => fake()->companyEmail(),
            'shipment_method_code' => '',
            'shipping_location_code' => 'LOCAL',
            'blocked' => 'none',
            'customer_price_group' => 'LIST PRICE',
            'customer_disc_group' => 'LIST',
            'salesperson_code' => '',
            'shipping_addresses' => [],
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'bc_payload' => [],
        ];
    }

    public function blocked(string $how = 'All'): static
    {
        return $this->state(fn (): array => ['blocked' => $how]);
    }

    public function livestock(): static
    {
        return $this->state(fn (): array => ['shipping_location_code' => 'LIVESTOCK']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $addresses
     */
    public function withShippingAddresses(array $addresses): static
    {
        return $this->state(fn (): array => ['shipping_addresses' => $addresses]);
    }
}
