<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiResearchClaim extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'research_run_id',
        'statement',
        'source_refs',
        'confidence',
        'claim_status',
        'contradiction_refs',
        'contradiction_status',
        'claim_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_refs' => 'array',
            'contradiction_refs' => 'array',
            'metadata' => 'array',
            'confidence' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AiResearchRun::class, 'research_run_id');
    }
}
