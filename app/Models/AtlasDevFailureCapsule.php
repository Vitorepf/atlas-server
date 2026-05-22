<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasDevFailureCapsule extends Model
{
    use HasUuids;

    protected $table = 'atlas_dev_failure_capsules';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'task_id',
        'task_packet_id',
        'failing_gate',
        'failure_class',
        'error_excerpt',
        'changed_files',
        'suggested_repair',
        'retry_budget',
        'escalate_to_forge',
        'failure_hash',
    ];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
            'retry_budget' => 'integer',
            'escalate_to_forge' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function taskPacket(): BelongsTo
    {
        return $this->belongsTo(AtlasDevTaskPacket::class, 'task_packet_id');
    }
}
