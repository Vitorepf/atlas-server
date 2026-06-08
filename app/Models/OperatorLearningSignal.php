<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperatorLearningSignal extends Model
{
    use HasUuids;

    public const PRIVACY_CLASSES = ['normal', 'private', 'sensitive', 'secret'];
    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];
    public const SCOPE_TYPES = ['global', 'project', 'workspace', 'session', 'task', 'domain', 'flow'];

    protected $fillable = [
        'operator_id',
        'taxonomy_item_id',
        'signal_kind',
        'source_type',
        'source_ref_type',
        'source_ref_id',
        'trace_id',
        'session_id',
        'raw_excerpt_hash',
        'normalized_claim',
        'evidence_refs',
        'privacy_class',
        'risk_level',
        'confidence',
        'scope_type',
        'scope_id',
        'valid_from',
        'valid_until',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(OperatorLearningCandidate::class, 'signal_id');
    }
}
