<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasDevOutcomeMemory extends Model
{
    use HasUuids;

    protected $table = 'atlas_dev_outcome_memories';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'task_id',
        'task_packet_id',
        'failure_capsule_id',
        'outcome_status',
        'evidence_kinds',
        'selected_tests',
        'changed_files',
        'learning_candidates',
        'should_promote_to_aemor',
        'human_review_required',
        'outcome_memory_hash',
    ];

    protected function casts(): array
    {
        return [
            'evidence_kinds' => 'array',
            'selected_tests' => 'array',
            'changed_files' => 'array',
            'learning_candidates' => 'array',
            'should_promote_to_aemor' => 'boolean',
            'human_review_required' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function taskPacket(): BelongsTo
    {
        return $this->belongsTo(AtlasDevTaskPacket::class, 'task_packet_id');
    }

    public function failureCapsule(): BelongsTo
    {
        return $this->belongsTo(AtlasDevFailureCapsule::class, 'failure_capsule_id');
    }
}
