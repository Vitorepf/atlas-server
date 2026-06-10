<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureIdea extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_ideas';

    protected $fillable = [
        'schema_version',
        'uuid',
        'idea_id',
        'title',
        'problem',
        'icp',
        'pain',
        'urgency',
        'source',
        'opportunity_id',
        'market_size_usd',
        'pain_severity',
        'founder_fit',
        'sovereignty_fit',
        'score',
        'score_breakdown',
        'generation_meta',
        'status',
        'promoted_venture_id',
        'idea_hash',
    ];

    protected function casts(): array
    {
        return [
            'market_size_usd' => 'float',
            'pain_severity' => 'integer',
            'founder_fit' => 'integer',
            'sovereignty_fit' => 'integer',
            'score' => 'float',
            'score_breakdown' => 'array',
            'generation_meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
