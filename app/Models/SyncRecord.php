<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Sync\WebsiteAction;
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
    'action',
    'changed_fields',
    'payload_hash',
    'payload',
    'delivered_action',
    'delivered_hash',
    'delivered_payload',
    'bc_modified_at',
    'last_error',
    'conflict_details',
    'conflicted_at',
    'dispatched_at',
    'started_at',
    'synced_at',
    'failed_at',
    'attempts',
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
            'action' => WebsiteAction::class,
            'delivered_action' => WebsiteAction::class,
            'changed_fields' => 'array',
            'conflict_details' => 'array',
            'payload' => 'array',
            'delivered_payload' => 'array',
            'bc_modified_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'conflicted_at' => 'immutable_datetime',
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

    #[Scope]
    protected function withAction(Builder $query, WebsiteAction $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Whether the website is known to already hold what this record wants.
     *
     * Both the action and the payload must match what was last delivered: a
     * record whose action flipped still needs delivering even though none of
     * its Business Central fields moved.
     */
    public function isDelivered(): bool
    {
        return $this->status === SyncStatus::Synced
            && $this->delivered_action === $this->action
            && $this->delivered_hash === $this->payload_hash;
    }
}
