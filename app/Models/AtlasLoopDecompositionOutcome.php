<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ACDE Leap 5 — one EXECUTED-obra terminal outcome (the decomposition compounding corpus).
 *
 * AtlasLoopDecompositionOutcomeRecorder writes one row per executed obra: `fingerprint_hash` is the
 * deterministic STRUCTURAL plan fingerprint (id-independent); `certified`/`thrashed` mirror the real
 * terminal envelope (frozen-bar certify or post-merge canary GREEN). AtlasLoopDecompositionShapePrior
 * reads these as a Wilson lower-bound certified-rate per fingerprint — the empirical signal the readiness
 * gate uses to bend the engine away from shapes that historically thrash. Anchored on machine-resolved
 * outcomes only, never on model self-report.
 */
class AtlasLoopDecompositionOutcome extends Model
{
    use HasUuids;

    protected $table = 'atlas_loop_decomposition_outcomes';

    protected $fillable = [
        'fingerprint_hash',
        'objective_kind',
        'node_count',
        'certified',
        'thrashed',
        'terminal_reason',
        'rounds',
    ];

    protected function casts(): array
    {
        return [
            'node_count' => 'integer',
            'certified' => 'boolean',
            'thrashed' => 'boolean',
            'rounds' => 'integer',
        ];
    }
}
