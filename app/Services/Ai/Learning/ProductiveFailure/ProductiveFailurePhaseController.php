<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

class ProductiveFailurePhaseController
{
    private const ORDER = ['phase_1', 'phase_2', 'phase_3', 'complete'];

    /**
     * @param  array<string,mixed>  $session
     */
    public function currentPhase(array $session): string
    {
        if (($session['completion_status'] ?? '') === 'complete') {
            return 'complete';
        }

        if (empty($session['phase_1_attempt'])) {
            return 'phase_1';
        }

        if (empty($session['phase_2_comparison'])) {
            return 'phase_2';
        }

        if (empty($session['phase_3_articulation'])) {
            return 'phase_3';
        }

        return 'complete';
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function evaluate(array $session, string $requestedPhase): array
    {
        $requestedPhase = $this->normalize($requestedPhase);
        $current = $this->currentPhase($session);
        $currentIndex = array_search($current, self::ORDER, true);
        $requestedIndex = array_search($requestedPhase, self::ORDER, true);

        if ($requestedIndex === false) {
            return $this->result('blocked', 'productive_failure_unknown_phase', $current, $requestedPhase);
        }

        if ($current === 'complete' && $requestedPhase !== 'complete') {
            return $this->result('blocked', 'productive_failure_session_already_complete', $current, $requestedPhase);
        }

        if ($requestedIndex < $currentIndex) {
            return $this->result('blocked', 'productive_failure_phase_already_completed', $current, $requestedPhase);
        }

        if ($requestedIndex > $currentIndex + 1) {
            return $this->result('blocked', 'productive_failure_phase_skipped', $current, $requestedPhase);
        }

        return $this->result('passed', 'productive_failure_phase_appropriate', $current, $requestedPhase);
    }

    private function normalize(string $phase): string
    {
        return match ($phase) {
            'attempt', 'generation' => 'phase_1',
            'compare', 'comparison' => 'phase_2',
            'articulate', 'integration' => 'phase_3',
            default => $phase,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason, string $current, string $requested): array
    {
        return [
            'schema_version' => 'atlas.cognitive.productive_failure_phase_transition.v1',
            'status' => $status,
            'reason' => $reason,
            'current_phase' => $current,
            'requested_phase' => $requested,
        ];
    }
}
