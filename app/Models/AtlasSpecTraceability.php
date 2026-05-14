<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasSpecTraceability extends Model
{
    use HasUuids;

    protected $table = 'atlas_spec_traceability';

    protected $fillable = [
        'spec_id', 'requirement_id', 'acceptance_criteria_id',
        'task_id', 'file_path', 'test_path', 'evidence_event_id',
        'link_type', 'confidence', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(AtlasSpec::class, 'spec_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AtlasRequirement::class, 'requirement_id');
    }

    public function acceptanceCriterion(): BelongsTo
    {
        return $this->belongsTo(AtlasAcceptanceCriteria::class, 'acceptance_criteria_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasSddTask::class, 'task_id');
    }
}
