<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasDevContextGate extends Model
{
    use HasUuids;

    protected $table = 'atlas_dev_context_gates';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'task_id',
        'task_packet_id',
        'status',
        'missing',
        'remediation',
        'provider_safe',
        'context_gate_hash',
    ];

    protected function casts(): array
    {
        return [
            'missing' => 'array',
            'remediation' => 'array',
            'provider_safe' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function taskPacket(): BelongsTo
    {
        return $this->belongsTo(AtlasDevTaskPacket::class, 'task_packet_id');
    }
}
