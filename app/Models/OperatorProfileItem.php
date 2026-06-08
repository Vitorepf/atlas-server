<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperatorProfileItem extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    public const VALIDITY_KINDS = ['permanent', 'project', 'session', 'temporary'];
    public const AUTOMATION_LEVELS = ['observe', 'suggest', 'auto_apply_reversible', 'auto_apply_after_report', 'autonomous_gated'];

    protected $fillable = [
        'operator_id',
        'taxonomy_item_id',
        'profile_key',
        'value',
        'summary',
        'scope_type',
        'scope_id',
        'validity_kind',
        'valid_from',
        'valid_until',
        'confidence',
        'privacy_class',
        'automation_level',
        'status',
        'source_candidate_id',
        'source_memory_entry_id',
        'last_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'last_applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $q): void {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
            });
    }

    public function sourceCandidate(): BelongsTo
    {
        return $this->belongsTo(OperatorLearningCandidate::class, 'source_candidate_id');
    }

    public function sourceMemoryEntry(): BelongsTo
    {
        return $this->belongsTo(AtlasMemoryEntry::class, 'source_memory_entry_id');
    }

    public function policyRules(): HasMany
    {
        return $this->hasMany(OperatorProfilePolicyRule::class, 'operator_profile_item_id');
    }

    public function feedbackEvents(): HasMany
    {
        return $this->hasMany(OperatorProfileFeedbackEvent::class, 'operator_profile_item_id');
    }
}
