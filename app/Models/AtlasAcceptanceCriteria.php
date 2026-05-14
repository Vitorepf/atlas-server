<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasAcceptanceCriteria extends Model
{
    use HasUuids;

    protected $table = 'atlas_acceptance_criteria';

    protected $fillable = [
        'requirement_id', 'code', 'given', 'when', 'then', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AtlasRequirement::class, 'requirement_id');
    }
}
