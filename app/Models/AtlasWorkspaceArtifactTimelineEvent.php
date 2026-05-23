<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AtlasWorkspaceArtifactTimelineEvent extends Model
{
    use HasUuids;

    protected $table = 'atlas_workspace_artifact_timeline_events';

    protected $fillable = [
        'workspace_id',
        'artifact_hash',
        'artifact_type',
        'event_type',
        'event_status',
        'route_target',
        'consumer',
        'payload',
        'event_hash',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
