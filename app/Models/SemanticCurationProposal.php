<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SemanticCurationProposal extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_type',
        'source_refs',
        'proposed_note_type',
        'proposed_title',
        'proposed_summary',
        'proposed_path',
        'proposed_frontmatter',
        'proposed_body',
        'score',
        'reason',
        'status',
        'shown_at',
        'resolved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_refs' => 'array',
            'proposed_frontmatter' => 'array',
            'score' => 'float',
            'shown_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
