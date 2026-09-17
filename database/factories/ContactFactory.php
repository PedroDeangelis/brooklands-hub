<?php

namespace Database\Factories;

use App\Contacts\ContactEligibility;
use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * A contact that passes every intrinsic website rule. Whether it is
     * eligible overall depends on the customer it is linked to; see
     * linkedTo() and eligible().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'number' => mb_strtoupper(fake()->unique()->bothify('CT######')),
            'display_name' => fake()->name(),
            'type' => 'Person',
            'company_number' => mb_strtoupper(fake()->bothify('CT######')),
            'company_name' => fake()->company(),
            'organisational_level_code' => ContactEligibility::USER_LEVEL,
            'contact_business_relation' => ContactEligibility::CUSTOMER_RELATION,
            'address_1' => '',
            'address_2' => '',
            'city' => '',
            'state' => '',
            'postal_code' => '',
            'country' => '',
            'phone' => '',
            'mobile' => '',
            'email' => mb_strtolower(fake()->unique()->safeEmail()),
            'privacy_blocked' => false,
            'customer_bc_id' => null,
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'bc_payload' => [],
        ];
    }

    /**
     * Link to a customer that already exists.
     */
    public function linkedTo(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_bc_id' => $customer->bc_id]);
    }

    /**
     * Fully eligible: linked to a fresh customer that has a ship-to address.
     */
    public function eligible(): static
    {
        return $this->state(fn (): array => [
            'customer_bc_id' => Customer::factory()->withShippingAddresses([
                ['bc_id' => fake()->uuid(), 'code' => 'MAIN', 'city' => 'New Plymouth'],
            ])->create()->bc_id,
        ]);
    }

    public function notUserLevel(string $level = ''): static
    {
        return $this->state(fn (): array => ['organisational_level_code' => $level]);
    }

    public function notCustomerRelation(string $relation = 'Vendor'): static
    {
        return $this->state(fn (): array => ['contact_business_relation' => $relation]);
    }

    public function privacyBlocked(): static
    {
        return $this->state(fn (): array => ['privacy_blocked' => true]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn (): array => ['email' => '']);
    }
}
