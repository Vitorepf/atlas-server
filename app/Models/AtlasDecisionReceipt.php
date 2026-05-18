<?php

namespace App\Models;

use App\Support\TemporalTruth\HasTemporalTruth;
use App\Support\TemporalTruth\TemporalTruthCanon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signed authorization to execute scoped runtime work.
 *
 * Hard law: "No controller may bypass Decision Receipt for SDD execution"
 * (data-model-and-services.md:295). RuntimeExecutor MUST validate the receipt
 * before any file write.
 *
 * TEOS-I1 / M2: carries 8 temporal-truth fields (see {@see TemporalTruthCanon}).
 * All nullable; legacy receipts remain valid.
 */
class AtlasDecisionReceipt extends Model
{
    use HasTemporalTruth;
    use HasUuids;

    protected $fillable = [
        'receipt_id', 'operation_id', 'spec_id', 'plan_id',
        'autonomy_level', 'allowed_actions_json', 'forbidden_actions_json',
        'allowed_files_json', 'forbidden_files_json', 'required_gates_json',
        'task_ids_json', 'context_pack_refs_json',
        'input_hash', 'output_hash', 'signature',
        'signed_at', 'expires_at', 'revoked_at', 'revoked_reason',
        // TEOS-I1 temporal truth fields (all nullable).
        'valid_from', 'valid_until', 'observed_at', 'verified_at',
        'stale_after', 'source_hash', 'superseded_by', 'authority_level',
    ];

    protected function casts(): array
    {
        return array_merge([
            'allowed_actions_json' => 'array',
            'forbidden_actions_json' => 'array',
            'allowed_files_json' => 'array',
            'forbidden_files_json' => 'array',
            'required_gates_json' => 'array',
            'task_ids_json' => 'array',
            'context_pack_refs_json' => 'array',
            'signed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ], TemporalTruthCanon::casts());
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(AtlasOperation::class, 'operation_id');
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(AtlasSpec::class, 'spec_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AtlasPlan::class, 'plan_id');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->signed_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('signed_at')->whereNull('revoked_at');
    }
}
