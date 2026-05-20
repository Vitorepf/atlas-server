<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasAgenticWorkcellEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'workcell_id',
        'schema_version',
        'event_type',
        'status',
        'payload',
        'evidence_refs',
        'event_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workcell(): BelongsTo
    {
        return $this->belongsTo(AtlasAgenticWorkcell::class, 'workcell_id');
    }
}
