<?php

namespace Tests\Feature\Products\Website;

use App\Products\Website\LocationSelector;
use Tests\TestCase;

class LocationSelectorTest extends TestCase
{
    private function unit(string $code, mixed $inventory = 0): array
    {
        return ['locationCode' => $code, 'inventory' => $inventory];
    }

    public function test_no_stockkeeping_units_resolves_no_location(): void
    {
        $location = app(LocationSelector::class)->select([]);

        $this->assertFalse($location->isResolved());
        $this->assertNull($location->code);
        $this->assertSame(0, $location->inventory);
    }

    public function test_brooklands_wins_even_when_livestock_holds_more_stock(): void
    {
        // Precedence, not quantity: selecting by stock made the location flip
        // between syncs, which moves shop visibility and customer gating.
        $location = app(LocationSelector::class)->select([
            $this->unit('LIVESTOCK', 500),
            $this->unit('BROOKLANDS', 1),
        ]);

        $this->assertSame('BROOKLANDS', $location->code);
        $this->assertSame(1, $location->inventory);
    }

    public function test_brooklands_wins_even_when_it_holds_no_stock(): void
    {
        $location = app(LocationSelector::class)->select([
            $this->unit('LIVESTOCK', 42),
            $this->unit('BROOKLANDS', 0),
        ]);

        $this->assertSame('BROOKLANDS', $location->code);
        $this->assertSame(0, $location->inventory);
    }

    public function test_livestock_is_selected_only_when_it_is_the_sole_location(): void
    {
        $location = app(LocationSelector::class)->select([$this->unit('LIVESTOCK', 7)]);

        $this->assertSame('LIVESTOCK', $location->code);
        $this->assertSame(7, $location->inventory);
    }

    public function test_other_locations_are_ignored_entirely(): void
    {
        // Stock parked in non-sellable locations must never become the website figure.
        $location = app(LocationSelector::class)->select([
            $this->unit('WRITE OFF', 900),
            $this->unit('QUARANTINE', 900),
        ]);

        $this->assertFalse($location->isResolved());
        $this->assertSame(0, $location->inventory);
    }

    public function test_the_highest_figure_wins_when_a_location_repeats(): void
    {
        $location = app(LocationSelector::class)->select([
            $this->unit('BROOKLANDS', 3),
            $this->unit('BROOKLANDS', 11),
            $this->unit('BROOKLANDS', 5),
        ]);

        $this->assertSame(11, $location->inventory);
    }

    public function test_location_codes_are_matched_ignoring_case_and_padding(): void
    {
        $location = app(LocationSelector::class)->select([$this->unit('  brooklands  ', 4)]);

        $this->assertSame('BROOKLANDS', $location->code);
        $this->assertSame(4, $location->inventory);
    }

    public function test_negative_inventory_is_reported_as_zero(): void
    {
        $location = app(LocationSelector::class)->select([$this->unit('BROOKLANDS', -6)]);

        $this->assertSame('BROOKLANDS', $location->code);
        $this->assertSame(0, $location->inventory);
    }

    public function test_missing_or_non_numeric_inventory_counts_as_zero(): void
    {
        $location = app(LocationSelector::class)->select([
            ['locationCode' => 'BROOKLANDS'],
            $this->unit('BROOKLANDS', 'not a number'),
        ]);

        $this->assertSame('BROOKLANDS', $location->code);
        $this->assertSame(0, $location->inventory);
    }

    public function test_malformed_rows_are_skipped(): void
    {
        $location = app(LocationSelector::class)->select([
            'not an array',
            ['no location code' => true],
            $this->unit('BROOKLANDS', 2),
        ]);

        $this->assertSame('BROOKLANDS', $location->code);
        $this->assertSame(2, $location->inventory);
    }
}
