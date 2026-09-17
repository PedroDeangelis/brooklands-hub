<?php

namespace Database\Factories;

use App\DocumentAttachments\AttachmentParentType;
use App\Models\Customer;
use App\Models\DocumentAttachment;
use App\Models\Product;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
{
    protected $model = DocumentAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'parent_bc_id' => fake()->uuid(),
            'parent_type' => AttachmentParentType::Product->value,
            'file_name' => fake()->slug(3).'.pdf',
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'bc_payload' => [],
        ];
    }

    /**
     * An attachment hanging off a product that exists locally.
     */
    public function forProduct(?Product $product = null): static
    {
        $product ??= Product::factory()->create();

        return $this->state(fn (): array => [
            'parent_type' => AttachmentParentType::Product->value,
            'parent_bc_id' => $product->bc_id,
        ]);
    }

    /**
     * An attachment hanging off a customer that exists locally.
     */
    public function forCustomer(?Customer $customer = null): static
    {
        $customer ??= Customer::factory()->create();

        return $this->state(fn (): array => [
            'parent_type' => AttachmentParentType::Customer->value,
            'parent_bc_id' => $customer->bc_id,
        ]);
    }

    /**
     * An attachment hanging off a sales order that exists locally.
     */
    public function forSalesOrder(?SalesOrder $salesOrder = null): static
    {
        $salesOrder ??= SalesOrder::factory()->create();

        return $this->state(fn (): array => [
            'parent_type' => AttachmentParentType::SalesOrder->value,
            'parent_bc_id' => $salesOrder->bc_id,
        ]);
    }
}
