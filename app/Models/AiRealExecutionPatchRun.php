<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRealExecutionPatchRun extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'worktree_record_id', 'schema_version', 'patch_run_id', 'status', 'execution_mode', 'changed_files', 'scope_guard', 'diff_summary', 'evidence_refs', 'receipt', 'patch_hash'];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
            'scope_guard' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
