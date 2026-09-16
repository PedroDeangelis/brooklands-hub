<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Database\Factories\SyncRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks whether one Business Central record still needs delivering to a channel.
 *
 * Unique on (channel, bc_id): one row per record per destination.
 */
#[Fillable([
    'channel',
    'bc_id',
    'status',
    'changed_fields',
    'payload_hash',
    'bc_modified_at',
    'last_error',
    'dispatched_at',
    'synced_at',
])]
class SyncRecord extends Model
{
    /** @use HasFactory<SyncRecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'changed_fields' => 'array',
            'bc_modified_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    #[Scope]
    protected function forChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    #[Scope]
    protected function withStatus(Builder $query, SyncStatus $status): Builder
    {
        return $query->where('status', $status);
    }
}
