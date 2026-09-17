<?php

namespace Tests\Feature\Sync\Payload;

use App\Sync\Payload\PayloadDiff;
use Tests\TestCase;

/**
 * Comparing two finished website payloads.
 */
class PayloadDiffTest extends TestCase
{
    public function test_an_identical_payload_produces_no_changes(): void
    {
        $payload = ['price' => 10, 'name' => 'Product A', 'stock_quantity' => 5];

        $diff = PayloadDiff::between($payload, $payload);

        $this->assertTrue($diff->isEmpty());
        $this->assertSame([], $diff->changes);
    }

    public function test_a_price_only_change_produces_only_the_price(): void
    {
        $diff = PayloadDiff::between(
            ['price' => 12, 'name' => 'Product A', 'stock_quantity' => 5],
            ['price' => 10, 'name' => 'Product A', 'stock_quantity' => 5],
        );

        $this->assertSame(['price' => 12], $diff->changes);
        $this->assertSame(['price'], $diff->changedFields);
    }

    public function test_a_name_only_change_produces_only_the_name(): void
    {
        $diff = PayloadDiff::between(
            ['price' => 10, 'name' => 'Product B'],
            ['price' => 10, 'name' => 'Product A'],
        );

        $this->assertSame(['name' => 'Product B'], $diff->changes);
    }

    /**
     * Key order is formatting, not data.
     */
    public function test_reordered_keys_are_not_a_change(): void
    {
        $diff = PayloadDiff::between(
            ['name' => 'A', 'price' => 10],
            ['price' => 10, 'name' => 'A'],
        );

        $this->assertTrue($diff->isEmpty());
    }

    public function test_reordered_nested_keys_are_not_a_change(): void
    {
        $diff = PayloadDiff::between(
            ['location' => ['code' => 'BROOKLANDS', 'inventory' => 4]],
            ['location' => ['inventory' => 4, 'code' => 'BROOKLANDS']],
        );

        $this->assertTrue($diff->isEmpty());
    }

    /**
     * Nested structures are sent whole: see PayloadDiff::ATOMIC_FIELDS.
     */
    public function test_a_nested_change_sends_the_whole_structure(): void
    {
        $desired = ['location' => ['code' => 'BROOKLANDS', 'inventory' => 9]];
        $delivered = ['location' => ['code' => 'BROOKLANDS', 'inventory' => 4]];

        $diff = PayloadDiff::between($desired, $delivered);

        $this->assertSame(['location' => ['code' => 'BROOKLANDS', 'inventory' => 9]], $diff->changes);
    }

    public function test_a_change_inside_one_list_entry_resends_the_whole_list(): void
    {
        $desired = ['group_prices' => [['id' => 'a', 'price' => 5], ['id' => 'b', 'price' => 8]]];
        $delivered = ['group_prices' => [['id' => 'a', 'price' => 5], ['id' => 'b', 'price' => 7]]];

        $diff = PayloadDiff::between($desired, $delivered);

        $this->assertSame($desired['group_prices'], $diff->changes['group_prices']);
    }

    /**
     * List order is data: a reordered price list is a real change.
     */
    public function test_reordering_a_list_is_a_change(): void
    {
        $diff = PayloadDiff::between(
            ['attributes' => [['name' => 'B'], ['name' => 'A']]],
            ['attributes' => [['name' => 'A'], ['name' => 'B']]],
        );

        $this->assertFalse($diff->isEmpty());
    }

    public function test_a_field_the_website_no_longer_wants_is_cleared_explicitly(): void
    {
        $diff = PayloadDiff::between(['price' => 10], ['price' => 10, 'barcode' => '123']);

        $this->assertSame(['barcode' => null], $diff->changes);
    }

    public function test_a_new_field_appears_in_the_diff(): void
    {
        $diff = PayloadDiff::between(['price' => 10, 'barcode' => '123'], ['price' => 10]);

        $this->assertSame(['barcode' => '123'], $diff->changes);
    }

    /**
     * Null and absent are different claims, so a value becoming null is a change.
     */
    public function test_a_value_becoming_null_is_a_change(): void
    {
        $diff = PayloadDiff::between(['rrp' => null], ['rrp' => 19.95]);

        $this->assertSame(['rrp' => null], $diff->changes);
    }

    public function test_everything_marks_the_whole_payload_as_changed(): void
    {
        $diff = PayloadDiff::everything(['price' => 10, 'name' => 'A']);

        $this->assertSame(['name' => 'A', 'price' => 10], $diff->changes);
        $this->assertSame(['name', 'price'], $diff->changedFields);
    }

    public function test_changes_are_returned_in_a_stable_order(): void
    {
        $first = PayloadDiff::between(['price' => 1, 'name' => 'A'], []);
        $second = PayloadDiff::between(['name' => 'A', 'price' => 1], []);

        $this->assertSame($first->changes, $second->changes);
    }

    public function test_the_atomic_fields_are_documented(): void
    {
        foreach (['location', 'brand', 'categories', 'attributes', 'group_prices'] as $field) {
            $this->assertTrue(PayloadDiff::isAtomic($field), "{$field} should be atomic");
        }

        $this->assertFalse(PayloadDiff::isAtomic('price'));
    }
}
