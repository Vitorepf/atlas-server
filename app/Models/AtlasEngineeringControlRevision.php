<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringControlRevision extends Model
{
    use HasUuids;

    protected $fillable = [
        'control_id',
        'slug',
        'version',
        'definition_hash',
        'definition_json',
        'changed_by',
        'first_seen_at',
        'last_seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'definition_json' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringControl::class, 'control_id');
    }
}
