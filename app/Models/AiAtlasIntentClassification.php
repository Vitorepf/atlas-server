<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAtlasIntentClassification extends Model
{
    use HasUuids;

    protected $table = 'ai_atlas_intent_classifications';

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'raw_input',
        'normalized_intent',
        'intent_type',
        'ambiguity_score',
        'confidence',
        'signals',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'ambiguity_score' => 'decimal:4',
            'confidence' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function routerDecisions(): HasMany
    {
        return $this->hasMany(AiAtlasRouterDecision::class, 'intent_classification_id');
    }
}
