<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OperatorLearningCandidate extends Model
{
    use HasUuids;

    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_CANDIDATE,
        self::STATUS_NEEDS_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SUPERSEDED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'signal_id',
        'operator_id',
        'taxonomy_item_id',
        'claim',
        'value',
        'status',
        'confidence',
        'conflict_group',
        'supersedes_id',
        'requires_confirmation',
        'auto_apply_eligible',
        'gate_receipt',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'gate_receipt' => 'array',
            'confidence' => 'float',
            'requires_confirmation' => 'boolean',
            'auto_apply_eligible' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CANDIDATE, self::STATUS_NEEDS_REVIEW]);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(OperatorLearningSignal::class, 'signal_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function profileItem(): HasOne
    {
        return $this->hasOne(OperatorProfileItem::class, 'source_candidate_id');
    }
}
