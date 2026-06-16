<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ACDE B4b — one PROVEN, provider-safe delivery contract per certified+merged delivery (the brain-feedback
 * write-end). Pure telemetry; nothing in the never-merge layer depends on it.
 */
class AtlasLoopDeliveryContract extends Model
{
    use HasUuids;

    protected $fillable = [
        'candidate_hash',
        'target_path',
        'changed_symbols',
        'consumer_count',
        'consumers',
        'risk_band',
        'canary',
        'mutation_kill_ratio',
        'completeness',
        'confidence',
        'commit_sha',
    ];

    protected function casts(): array
    {
        return [
            'changed_symbols' => 'array',
            'consumers' => 'array',
            'consumer_count' => 'integer',
            'mutation_kill_ratio' => 'float',
            'completeness' => 'float',
            'confidence' => 'float',
        ];
    }
}
