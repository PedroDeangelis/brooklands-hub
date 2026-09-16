<?php

namespace Tests\Unit\Support;

use App\Support\Canonical;
use PHPUnit\Framework\TestCase;

class CanonicalTest extends TestCase
{
    public function test_treats_objects_with_reordered_keys_as_equal(): void
    {
        $this->assertTrue(Canonical::equals(
            ['b' => 2, 'a' => 1],
            ['a' => 1, 'b' => 2],
        ));
    }

    public function test_treats_reordered_keys_in_nested_objects_as_equal(): void
    {
        $this->assertTrue(Canonical::equals(
            [['unitPrice' => 5, 'salesCode' => 'RRP']],
            [['salesCode' => 'RRP', 'unitPrice' => 5]],
        ));
    }

    public function test_treats_a_different_value_as_not_equal(): void
    {
        $this->assertFalse(Canonical::equals(['a' => 1], ['a' => 2]));
    }

    public function test_treats_reordered_list_entries_as_not_equal(): void
    {
        // List position is data: the first stockkeeping unit is not interchangeable
        // with the second.
        $this->assertFalse(Canonical::equals(
            [['locationCode' => 'BROOKLANDS'], ['locationCode' => 'LIVESTOCK']],
            [['locationCode' => 'LIVESTOCK'], ['locationCode' => 'BROOKLANDS']],
        ));
    }

    public function test_treats_a_missing_key_as_not_equal(): void
    {
        $this->assertFalse(Canonical::equals(['a' => 1, 'b' => 2], ['a' => 1]));
    }

    public function test_treats_an_added_list_entry_as_not_equal(): void
    {
        $this->assertFalse(Canonical::equals([['a' => 1]], [['a' => 1], ['a' => 2]]));
    }

    public function test_treats_two_empty_collections_as_equal(): void
    {
        $this->assertTrue(Canonical::equals([], []));
    }

    public function test_sorts_nested_object_keys_while_preserving_list_order(): void
    {
        $sorted = Canonical::sort([
            ['z' => 1, 'a' => 2],
            ['y' => 3, 'b' => 4],
        ]);

        $this->assertSame(['a' => 2, 'z' => 1], $sorted[0]);
        $this->assertSame(['b' => 4, 'y' => 3], $sorted[1]);
    }

    public function test_leaves_scalars_untouched(): void
    {
        $this->assertSame('x', Canonical::sort('x'));
        $this->assertSame(5, Canonical::sort(5));
        $this->assertNull(Canonical::sort(null));
    }

    public function test_distinguishes_numeric_strings_from_numbers(): void
    {
        $this->assertFalse(Canonical::equals(['a' => 1], ['a' => '1']));
    }
}
