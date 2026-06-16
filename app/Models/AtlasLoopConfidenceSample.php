<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A POST-MERGE confidence-calibration sample (ITEM10 — the calibration flywheel).
 *
 * The auto-merge feeder writes one row per merged proposal: `predicted` is the cert-time
 * delivery_confidence (0..1) threaded into the proposal's quality json; `correct` is the
 * canary verdict (ground-truth). atlas:loop:confidence-calibrate reads these as
 * {predicted, correct} samples and AtlasLoopConfidenceCalibrator fits the honest arm
 * threshold from REAL outcomes — never a declared number.
 */
class AtlasLoopConfidenceSample extends Model
{
    use HasUuids;

    protected $table = 'atlas_loop_confidence_samples';

    protected $fillable = [
        'proposal_id',
        'predicted',
        'correct',
    ];

    protected function casts(): array
    {
        return [
            'predicted' => 'float',
            'correct' => 'boolean',
        ];
    }
}
