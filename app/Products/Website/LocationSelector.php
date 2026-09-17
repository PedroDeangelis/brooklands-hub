<?php

namespace App\Products\Website;

/**
 * Chooses which stock location represents a product on the website.
 *
 * Selection is by precedence, not by quantity. Picking the highest-inventory
 * location made the selected location flip between syncs as relative stock
 * shifted, which moves shop visibility, livestock customer gating, and the
 * location sent back to Business Central on order lines. BROOKLANDS wins
 * whenever the item is stocked there at all; LIVESTOCK only when it is the
 * sole location. Any other location is ignored entirely.
 */
class LocationSelector
{
    public const string BROOKLANDS = 'BROOKLANDS';

    public const string LIVESTOCK = 'LIVESTOCK';

    /**
     * In preference order: the first location present wins.
     *
     * @var list<string>
     */
    private const SELECTABLE = [self::BROOKLANDS, self::LIVESTOCK];

    /**
     * @param  array<int, mixed>  $stockKeepingUnits
     */
    public function select(array $stockKeepingUnits): ProductLocation
    {
        $inventoryByLocation = [];

        foreach ($stockKeepingUnits as $unit) {
            if (! is_array($unit)) {
                continue;
            }

            $code = mb_strtoupper(trim((string) ($unit['locationCode'] ?? '')));

            if (! in_array($code, self::SELECTABLE, true)) {
                continue;
            }

            $inventory = is_numeric($unit['inventory'] ?? null) ? (int) $unit['inventory'] : 0;

            // Business Central can return more than one stockkeeping unit per
            // location code; keep the highest figure for that location.
            if (! isset($inventoryByLocation[$code]) || $inventory > $inventoryByLocation[$code]) {
                $inventoryByLocation[$code] = $inventory;
            }
        }

        foreach (self::SELECTABLE as $code) {
            if (isset($inventoryByLocation[$code])) {
                return new ProductLocation($code, max($inventoryByLocation[$code], 0));
            }
        }

        return ProductLocation::none();
    }
}
