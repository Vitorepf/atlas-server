<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SemanticNote extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'note_key',
        'path',
        'title',
        'type',
        'status',
        'confidence',
        'maturity',
        'domains',
        'summary',
        'body_excerpt',
        'frontmatter',
        'when_to_use',
        'trigger_signals',
        'do_not_use_when',
        'postgres_refs',
        'content_hash',
        'embedding',
        'indexed_at',
        'last_seen_at',
        'last_activated_at',
        'last_practiced_at',
        'activation_count',
        'usefulness_avg',
        'validation_errors',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'frontmatter' => 'array',
            'when_to_use' => 'array',
            'trigger_signals' => 'array',
            'do_not_use_when' => 'array',
            'postgres_refs' => 'array',
            'indexed_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_activated_at' => 'immutable_datetime',
            'last_practiced_at' => 'immutable_datetime',
            'activation_count' => 'integer',
            'usefulness_avg' => 'float',
            'validation_errors' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function activations(): HasMany
    {
        return $this->hasMany(SemanticNoteActivation::class, 'note_id');
    }

    public function sourceLinks(): HasMany
    {
        return $this->hasMany(SemanticNoteLink::class, 'source_note_id');
    }

    public function targetLinks(): HasMany
    {
        return $this->hasMany(SemanticNoteLink::class, 'target_note_id');
    }
}
