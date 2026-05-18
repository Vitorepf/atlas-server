<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiBugBountyIntake extends Model
{
    use HasUuids;

    protected $table = 'ai_bug_bounty_intakes';

    protected $fillable = [
        'schema_version',
        'uuid',
        'engagement_id',
        'intake_id',
        'program',
        'program_url',
        'authorization_present',
        'authorization_doc',
        'scope_parsed',
        'roe_documented',
        'legal_gate_passed',
        'privacy_gate_passed',
        'handoff_plan',
        'status',
        'blockers',
        'intake_hash',
    ];

    protected function casts(): array
    {
        return [
            'authorization_present' => 'boolean',
            'authorization_doc' => 'array',
            'scope_parsed' => 'boolean',
            'roe_documented' => 'boolean',
            'legal_gate_passed' => 'boolean',
            'privacy_gate_passed' => 'boolean',
            'handoff_plan' => 'array',
            'blockers' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
