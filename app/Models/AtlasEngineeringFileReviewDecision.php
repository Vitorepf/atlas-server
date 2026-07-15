<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AtlasEngineeringFileReviewDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'patch_artifact_id',
        'file_path',
        'patch_diff_hash',
        'action',
        'actor',
        'note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRun::class, 'engineering_run_id');
    }

    public function patchArtifact(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringPatchArtifact::class, 'patch_artifact_id');
    }
}
