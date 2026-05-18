<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `atlas_vox_rivals_cases` table.
 *
 * Each row is one human-evaluated rivals comparison:
 *   - kind: how the baseline was produced (wispr_baseline, provider_direct, manual)
 *   - mode: which Vox mode is under test (dictation .. governed_execute)
 *   - preference: which won according to Vitor (vox / baseline / tie)
 *
 * No raw audio, no transcript text, no provider payload is persisted.
 * `metadata` is free-form JSON for short audit context only.
 */
final class AtlasVoxRivalsCase extends Model
{
    protected $table = 'atlas_vox_rivals_cases';

    protected $fillable = [
        'case_id',
        'kind',
        'mode',
        'vox_session_id',
        'vox_intent_id',
        'baseline_label',
        'baseline_duration_ms',
        'vox_duration_ms',
        'baseline_score',
        'vox_score',
        'preference',
        'prompt_quality_vote',
        'regret_flag',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'baseline_duration_ms' => 'integer',
        'vox_duration_ms' => 'integer',
        'baseline_score' => 'integer',
        'vox_score' => 'integer',
        'prompt_quality_vote' => 'integer',
        'regret_flag' => 'boolean',
        'metadata' => 'array',
    ];
}
