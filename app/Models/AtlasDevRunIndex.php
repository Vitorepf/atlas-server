<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Index of Atlas Dev runs surfaced to Desktop/CLI.
 *
 * Receipts continue to be the source of truth for artifacts; this table
 * only mirrors routing + completion shape so callers can list/resume runs
 * without scanning the receipts storage tree.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
 */
class AtlasDevRunIndex extends Model
{
    protected $table = 'atlas_dev_run_index';

    protected $primaryKey = 'run_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'run_id',
        'surface_id',
        'workspace_hash',
        'thread_id',
        'routing_decision',
        'task_kind',
        'risk_level',
        'completion_state',
        'last_receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
