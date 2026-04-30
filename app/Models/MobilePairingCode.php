<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MobilePairingCode extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'code_hash',
        'device_label',
        'expires_at',
        'consumed_at',
        'attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
