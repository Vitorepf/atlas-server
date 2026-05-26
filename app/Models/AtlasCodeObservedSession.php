<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 */
class AtlasCodeObservedSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'atlas_code_observed_sessions';

    protected $fillable = [
        'id',
        'obra_id',
        'obra_title',
        'work_packet_id',
        'provider_id',
        'provider_name',
        'provider_family',
        'invocation_mode',
        'role_slot',
        'workspace_slug',
        'workspace_path',
        'packet_md_path',
        'packet_md_status',
        'packet_md_excerpt',
        'prompt',
        'prompt_hash',
        'terminal_command_hint',
        'state',
        'state_history',
        'operator_opened_terminal_at',
        'operator_marked_running_at',
        'result_imported_at',
        'report_text',
        'report_files',
        'diff_excerpt',
        'diff_hash',
        'gates',
        'gates_summary',
        'gates_evaluated_at',
        'scope_guard',
        'git_snapshot',
        'human_decision',
        'decision_signature',
        'decision_public_key',
        'decision_signing_status',
        'decision_signed_at',
        'blocker_reason',
        'governance',
    ];

    protected function casts(): array
    {
        return [
            'state_history' => 'array',
            'report_files' => 'array',
            'gates' => 'array',
            'gates_summary' => 'array',
            'scope_guard' => 'array',
            'git_snapshot' => 'array',
            'human_decision' => 'array',
            'governance' => 'array',
            'operator_opened_terminal_at' => 'immutable_datetime',
            'operator_marked_running_at' => 'immutable_datetime',
            'result_imported_at' => 'immutable_datetime',
            'gates_evaluated_at' => 'immutable_datetime',
            'decision_signed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
