<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemanticHypothesisTest extends Model
{
    use HasUuids;

    protected $fillable = [
        'note_id',
        'hypothesis',
        'metric_targets',
        'required_sources',
        'started_at',
        'ended_at',
        'status',
        'result_summary',
        'result_payload',
        'decision',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metric_targets' => 'array',
            'required_sources' => 'array',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'result_payload' => 'array',
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
