<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OperatorProfileSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'operator_id',
        'snapshot_kind',
        'summary',
        'profile_hash',
        'included_item_ids',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'included_item_ids' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
