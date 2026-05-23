<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AtlasWorkspaceIntelligenceSnapshot extends Model
{
    use HasUuids;

    protected $table = 'atlas_workspace_intelligence_snapshots';

    protected $fillable = [
        'schema_version',
        'workspace_id',
        'workspace_hash',
        'runtime_hash',
        'status',
        'checks_total',
        'checks_passed',
        'checks_failed',
        'artifacts_count',
        'family_status',
        'payload',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'family_status' => 'array',
            'payload' => 'array',
            'captured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
