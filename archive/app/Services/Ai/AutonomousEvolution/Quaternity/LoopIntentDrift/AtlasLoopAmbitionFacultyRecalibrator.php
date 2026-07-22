<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use Throwable;

final class AtlasLoopAmbitionFacultyRecalibrator
{
    private const DEFAULT_MAX_STEP = 0.15;
    private const DEFAULT_DEADBAND = 0.05;

    /**
     * @return array{target:AtlasLoopAmbitionFacultyTarget,reversal_token:AtlasLoopAmbitionFacultyReversalToken}
     */
    public function recommend(
        AtlasLoopOperatorIntentDriftFact $fact,
        AtlasLoopAmbitionFacultyTarget $current,
    ): array {
        if ($fact->overallL2Magnitude < $this->deadband()) {
            return [
                'target' => $current,
                'reversal_token' => AtlasLoopAmbitionFacultyReversalToken::noop($current),
            ];
        }

        $direction = $this->dominantDirection($fact);
        $step = min($this->maxStep(), max(0.0, $fact->overallL2Magnitude)) * $direction;
        $target = new AtlasLoopAmbitionFacultyTarget(
            ambitionTarget: AtlasLoopAmbitionFacultyTarget::clamp01($current->ambitionTarget + $step),
            axisWeights: $current->axisWeights,
        );

        return [
            'target' => $target,
            'reversal_token' => AtlasLoopAmbitionFacultyReversalToken::recalibration($current),
        ];
    }

    public function apply(
        AtlasLoopAmbitionFacultyReversalToken $token,
        AtlasLoopAmbitionFacultyTarget $current,
    ): AtlasLoopAmbitionFacultyTarget {
        if ($token->mode === AtlasLoopAmbitionFacultyReversalToken::MODE_NOOP) {
            return $current;
        }

        return $token->priorTarget();
    }

    private function dominantDirection(AtlasLoopOperatorIntentDriftFact $fact): int
    {
        $axis = $fact->dominantAxis;
        if ($axis === null || $axis === '') {
            return 0;
        }

        return max(-1, min(1, (int) ($fact->axisDirections[$axis] ?? 0)));
    }

    private function maxStep(): float
    {
        return max(0.0, min(1.0, $this->configuredFloat(
            key: 'atlas.loop.quaternity.intent_drift.max_step',
            fallback: self::DEFAULT_MAX_STEP,
        )));
    }

    private function deadband(): float
    {
        return max(0.0, $this->configuredFloat(
            key: 'atlas.loop.quaternity.intent_drift.deadband',
            fallback: self::DEFAULT_DEADBAND,
        ));
    }

    private function configuredFloat(string $key, float $fallback): float
    {
        if (! function_exists('config')) {
            return $fallback;
        }

        try {
            $configured = config($key, $fallback);
        } catch (Throwable) {
            return $fallback;
        }

        return is_numeric($configured) ? (float) $configured : $fallback;
    }
}
