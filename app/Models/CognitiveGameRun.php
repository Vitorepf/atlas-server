<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CognitiveGameRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'game_key',
        'title',
        'input_note_ids',
        'prompt',
        'operator_answer',
        'atlas_feedback',
        'score',
        'duration_seconds',
        'promoted_note_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'input_note_ids' => 'array',
            'score' => 'float',
            'duration_seconds' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function promotedNote(): BelongsTo
    {
        return $this->belongsTo(SemanticNote::class, 'promoted_note_id');
    }
}
