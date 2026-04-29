<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemanticNoteActivation extends Model
{
    use HasUuids;

    protected $fillable = [
        'note_id',
        'activation_type',
        'context_type',
        'context_payload',
        'prompt',
        'shown_at',
        'acted_at',
        'dismissed_at',
        'usefulness_score',
        'operator_feedback',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'context_payload' => 'array',
            'shown_at' => 'immutable_datetime',
            'acted_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'usefulness_score' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(SemanticNote::class, 'note_id');
    }
}
