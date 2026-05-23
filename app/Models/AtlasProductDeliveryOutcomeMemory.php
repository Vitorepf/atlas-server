<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasProductDeliveryOutcomeMemory extends Model
{
    use HasUuids;

    protected $table = 'atlas_product_delivery_outcome_memories';

    protected $fillable = [
        'schema_version',
        'uuid',
        'delivery_hash',
        'truth_hash',
        'proof_hash',
        'route',
        'outcome_status',
        'evidence_kinds',
        'required_repairs',
        'learning_candidates',
        'delivery_summary',
        'proof_summary',
        'should_promote_to_aemor',
        'human_review_required',
        'outcome_memory_hash',
    ];

    protected function casts(): array
    {
        return [
            'evidence_kinds' => 'array',
            'required_repairs' => 'array',
            'learning_candidates' => 'array',
            'delivery_summary' => 'array',
            'proof_summary' => 'array',
            'should_promote_to_aemor' => 'boolean',
            'human_review_required' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
