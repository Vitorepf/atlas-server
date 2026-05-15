<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 */
class AtlasCodeWorkPacket extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'atlas_code_work_packets';

    protected $fillable = [
        'id',
        'obra_id',
        'obra_title',
        'workspace_slug',
        'workspace_path',
        'status',
        'objective',
        'context_summary',
        'allowed_files',
        'forbidden_files',
        'interfaces',
        'constraints',
        'acceptance_criteria',
        'verification_commands',
        'evidence_required',
        'report_format',
        'stop_rule',
        'role_slot',
        'risk_band',
        'task_category',
        'exported_at',
        'packet_md_path',
        'prompt_hash',
    ];

    protected function casts(): array
    {
        return [
            'allowed_files' => 'array',
            'forbidden_files' => 'array',
            'interfaces' => 'array',
            'constraints' => 'array',
            'acceptance_criteria' => 'array',
            'verification_commands' => 'array',
            'evidence_required' => 'array',
            'exported_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
