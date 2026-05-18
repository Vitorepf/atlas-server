<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiForbiddenAction extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'policy_profile_id',
        'action_key',
        'description',
        'scope_type',
        'scope_ref',
        'severity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function policyProfile(): BelongsTo
    {
        return $this->belongsTo(AiPolicyProfile::class, 'policy_profile_id');
    }
}
