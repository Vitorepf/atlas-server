<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AtlasWorkspaceRuntimeProjectionSnapshot extends Model
{
    use HasUuids;

    protected $table = 'atlas_workspace_runtime_projection_snapshots';

    protected $fillable = [
        'workspace_id',
        'family',
        'schema_version',
        'runtime_hash',
        'projection_hash',
        'status',
        'payload',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'captured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
