<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|string|null $id
 * @property string|null $milestone_id
 * @property int|null $position
 * @property array<int,mixed>|null $required_evidence
 * @property array<int,mixed>|null $expected_artifacts
 * @property array<int,mixed>|null $required_gates
 */
class AiForgeMilestone extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_milestones';

    protected $fillable = [
        'schema_version',
        'uuid',
        'intake_id',
        'position',
        'milestone_id',
        'title',
        'description',
        'expected_artifacts',
        'required_gates',
        'required_evidence',
        'status',
        'blocker_reason',
        'milestone_hash',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'expected_artifacts' => 'array',
            'required_gates' => 'array',
            'required_evidence' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AiForgeIntake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(AiForgeIntake::class, 'intake_id');
    }
}
