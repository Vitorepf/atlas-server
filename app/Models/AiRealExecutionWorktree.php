<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRealExecutionWorktree extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'schema_version', 'worktree_id', 'status', 'base_path', 'branch_name', 'isolation_mode', 'allowed_paths', 'forbidden_paths', 'evidence_refs', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'allowed_paths' => 'array',
            'forbidden_paths' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
