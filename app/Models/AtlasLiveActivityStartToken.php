<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Token ActivityKit para iniciar uma Live Activity enquanto o Atlas iOS não
 * está aberto. É por instalação, rotativo e nunca é devolvido pela API.
 */
class AtlasLiveActivityStartToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'installation_id',
        'push_token',
        'push_token_hash',
        'environment',
        'last_seen_at',
        'last_started_trace_id',
    ];

    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
