<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorProfilePolicyRule extends Model
{
    use HasUuids;

    public const EFFECTS = [
        'context_hint',
        'response_style',
        'approval_gate',
        'autonomy_limit',
        'do_not_do',
        'workflow_preference',
        'tool_preference',
        'handoff_preference',
    ];

    protected $fillable = [
        'operator_profile_item_id',
        'rule_key',
        'rule',
        'applies_to_flow',
        'priority',
        'effect',
        'reverse_handle',
    ];

    protected function casts(): array
    {
        return [
            'rule' => 'array',
            'priority' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function profileItem(): BelongsTo
    {
        return $this->belongsTo(OperatorProfileItem::class, 'operator_profile_item_id');
    }
}
