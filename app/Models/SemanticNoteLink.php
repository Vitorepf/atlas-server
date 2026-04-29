<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemanticNoteLink extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_note_id',
        'target_note_id',
        'link_type',
        'explanation',
        'created_by',
        'confidence',
        'confirmed_by_operator',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'confirmed_by_operator' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function sourceNote(): BelongsTo
    {
        return $this->belongsTo(SemanticNote::class, 'source_note_id');
    }

    public function targetNote(): BelongsTo
    {
        return $this->belongsTo(SemanticNote::class, 'target_note_id');
    }
}
