<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AtlasWorkspaceProfile extends Model
{
    use HasUuids;

    protected $table = 'atlas_workspace_profiles';

    protected $fillable = [
        'slug',
        'name',
        'kind',
        'workspace_path',
        'repo_root',
        'production_status',
        'stack_summary',
        'commands',
        'test_commands',
        'build_commands',
        'dev_server_command',
        'critical_areas',
        'docs_status',
        'default_risk',
        'deployment_notes',
        'surfaces_enabled',
        'source',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'commands' => 'array',
            'test_commands' => 'array',
            'build_commands' => 'array',
            'critical_areas' => 'array',
            'surfaces_enabled' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
