<?php

namespace App\Sync\Payload;

use App\DocumentAttachments\AttachmentParentType;
use App\Models\Customer;

/**
 * Builds the payload the website should be given for a customer.
 *
 * A translation step and nothing more. Customers are mirrored, never withdrawn:
 * whether a blocked customer may log in or order is the website's rule
 * (helpers.php reads `blocked === 'none'`), so `blocked` is a field here, not a
 * reason to stop sending.
 *
 * Deterministic — the same customer always produces the same keys in the same
 * shape — so payloads can be hashed and diffed. shipping_addresses is sent
 * whole and compared whole; PayloadDiff lists it as atomic.
 */
class CustomerWebsitePayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Customer $customer): array
    {
        return [
            'bc_id' => (string) $customer->bc_id,
            'number' => (string) $customer->number,
            'name' => $customer->title(),
            'type' => (string) $customer->type,
            'address_1' => (string) $customer->address_1,
            'address_2' => (string) $customer->address_2,
            'city' => (string) $customer->city,
            'state' => (string) $customer->state,
            'postal_code' => (string) $customer->postal_code,
            'country' => (string) $customer->country,
            'phone' => (string) $customer->phone,
            'email' => (string) $customer->email,
            'shipment_method' => $this->shipmentMethod($customer),
            'shipment_location_code' => $this->shipmentLocation($customer),
            'customer_price_group' => $this->priceGroup($customer),
            'blocked' => (string) $customer->blocked,
            'salesperson_code' => (string) $customer->salesperson_code,
            'shipping_addresses' => $customer->shippingAddresses(),
            // Replaced whole, never merged: the sweep that imports attachments
            // is the only thing that knows a file has been deleted, so a
            // partial list here would leave removed files on the website.
            'attachments' => AttachmentsPayload::for(AttachmentParentType::Customer, (string) $customer->bc_id),
        ];
    }

    /**
     * The website's shipment_method choice.
     *
     * Business Central sends an empty code for every customer today, so this
     * is "std" in practice; a non-empty code is lowercased to match the
     * website's choice keys (std, flat).
     */
    private function shipmentMethod(Customer $customer): string
    {
        $code = mb_strtolower(trim((string) $customer->shipment_method_code));

        return $code === '' ? 'std' : $code;
    }

    /**
     * The website's shipment_location_code choice: livestock or standard.
     */
    private function shipmentLocation(Customer $customer): string
    {
        return mb_strtoupper(trim((string) $customer->shipping_location_code)) === 'LIVESTOCK'
            ? 'livestock'
            : 'standard';
    }

    /**
     * The value the website's pricing reads as the customer's group.
     *
     * The discount group takes precedence over the price group, so a customer
     * carries "LIST" rather than "LIST PRICE". This reproduces the legacy
     * upserter exactly, because price-group.php reads whatever is stored here
     * and every existing customer post was written this way. Whether that
     * precedence is intended is flagged in the plan; it is not decided here.
     */
    private function priceGroup(Customer $customer): string
    {
        $disc = trim((string) $customer->customer_disc_group);

        return $disc !== '' ? $disc : trim((string) $customer->customer_price_group);
    }
}
