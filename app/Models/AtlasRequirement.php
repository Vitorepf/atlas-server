<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasRequirement extends Model
{
    use HasUuids;

    protected $fillable = [
        'spec_id', 'code', 'text', 'priority', 'status', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(AtlasSpec::class, 'spec_id');
    }

    public function acceptanceCriteria(): HasMany
    {
        return $this->hasMany(AtlasAcceptanceCriteria::class, 'requirement_id');
    }
}
