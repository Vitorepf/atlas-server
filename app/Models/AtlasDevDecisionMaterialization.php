<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasDevDecisionMaterialization extends Model
{
    use HasUuids;

    protected $table = 'atlas_dev_decision_materializations';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'task_id',
        'task_packet_id',
        'decision_kind',
        'status',
        'payload',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function taskPacket(): BelongsTo
    {
        return $this->belongsTo(AtlasDevTaskPacket::class, 'task_packet_id');
    }
}
