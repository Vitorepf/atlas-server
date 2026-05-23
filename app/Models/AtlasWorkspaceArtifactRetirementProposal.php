<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AtlasWorkspaceArtifactRetirementProposal extends Model
{
    use HasUuids;

    protected $table = 'atlas_workspace_artifact_retirement_proposals';

    protected $fillable = [
        'workspace_id',
        'artifact_hash',
        'artifact_type',
        'reason',
        'status',
        'replacement_required',
        'payload',
        'proposal_hash',
        'proposed_at',
    ];

    protected function casts(): array
    {
        return [
            'replacement_required' => 'boolean',
            'payload' => 'array',
            'proposed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
