<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How far a Business Central entity has been fetched.
 *
 * One row per entity. Each entity changes at its own rate and is fetched by its
 * own command, so sharing a single position would make a slow entity hold back
 * a fast one, or a fast one skip past a slow one's unfetched records.
 *
 * last_modified_at is only ever advanced after a run has fetched its entire
 * result set. A partial fetch leaves it alone, so the next run re-reads from
 * the same place rather than stepping over whatever was missed.
 */
#[Fillable([
    'entity',
    'last_modified_at',
    'last_run_at',
    'last_run_rows',
    'last_run_pages',
    'last_full_sync_at',
])]
class SyncCheckpoint extends Model
{
    /**
     * Business Central entities that keep a checkpoint.
     *
     * Others (promotions, customers, sales documents) will add their own as
     * they arrive; nothing here is specific to products.
     */
    public const ENTITY_ITEMS = 'items';

    public const ENTITY_ITEM_QUANTITIES = 'item_quantities';

    /**
     * Written with milliseconds, so the checkpoint keeps the precision
     * Business Central sent.
     *
     * A format suffix on the cast cannot do this. isDateCastable() matches
     * only the bare cast names, so a suffixed cast stops being date-castable,
     * and prepareBindings() then reformats the Carbon instance with the query
     * grammar's own hardcoded "Y-m-d H:i:s" regardless. Flooring the value
     * that way would park the checkpoint before the record it came from, and
     * the strict "gt" filter would keep re-fetching that record forever.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_modified_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'last_full_sync_at' => 'immutable_datetime',
        ];
    }

    /**
     * The checkpoint for an entity, created unset if it does not exist yet.
     */
    public static function forEntity(string $entity): self
    {
        return static::firstOrCreate(['entity' => $entity], ['last_modified_at' => null]);
    }

    /**
     * Whether this entity has ever been fetched completely.
     */
    public function isSet(): bool
    {
        return $this->last_modified_at !== null;
    }
}
