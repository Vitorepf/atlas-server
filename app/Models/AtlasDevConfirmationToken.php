<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
 */
class AtlasDevConfirmationToken extends Model
{
    use HasUuids;

    protected $table = 'atlas_dev_confirmation_tokens';

    protected $fillable = [
        'run_id',
        'token_hash',
        'surface_id',
        'task_contract_hash',
        'compact_sdd_hash',
        'issued_at',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
