<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um token APNs por Live Activity em execução.
 *
 * Ele não é um token de dispositivo: ActivityKit o troca ao longo da vida da
 * atividade. O valor permanece cifrado em repouso e só o transportador APNs
 * pode lê-lo para atualizar/encerrar aquela execução específica.
 */
class AtlasLiveActivityPushToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'activity_id',
        'installation_id',
        'push_token',
        'push_token_hash',
        'environment',
        'status',
        'started_at',
        'invalidated_at',
        'last_seen_at',
        'frequent_updates_enabled',
    ];

    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'started_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_pushed_at' => 'immutable_datetime',
            'frequent_updates_enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }
}
