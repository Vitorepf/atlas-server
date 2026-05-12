<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasSelfConstructionAgentSandboxBinding extends Model
{
    use HasUuids;

    protected $fillable = [
        'binding_key',
        'receipt_hash',
        'receipt_key',
        'packet_id',
        'provider',
        'provider_role',
        'status',
        'workspace_root',
        'worktree_path',
        'branch',
        'executor_contract_hash',
        'executor_release_authorization_hash',
        'allowed_files_hash',
        'forbidden_scope_hash',
        'scope_validator_hash',
        'actor',
        'session',
        'payload',
        'activated_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'activated_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
