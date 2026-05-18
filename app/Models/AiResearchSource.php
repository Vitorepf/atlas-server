<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiResearchSource extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'research_run_id',
        'source_type',
        'source_ref',
        'title',
        'author',
        'published_at',
        'status',
        'source_quality',
        'quality_factors',
        'reason_rejected',
        'citation_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quality_factors' => 'array',
            'metadata' => 'array',
            'published_at' => 'immutable_date',
            'source_quality' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AiResearchRun::class, 'research_run_id');
    }
}
