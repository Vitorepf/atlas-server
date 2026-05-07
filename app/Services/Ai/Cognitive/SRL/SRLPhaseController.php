<?php

namespace App\Services\Ai\Cognitive\SRL;

class SRLPhaseController
{
    private const ORDER = ['forethought', 'performance', 'self_reflection'];

    /**
     * @param  array<string,mixed>  $episode
     */
    public function currentPhase(array $episode): string
    {
        if (empty($episode['forethought'])) {
            return 'forethought';
        }

        if (($episode['completion_status'] ?? '') === 'complete' || ! empty($episode['self_reflection'])) {
            return 'complete';
        }

        if (! empty($episode['performance_observations'])) {
            return 'self_reflection';
        }

        return 'performance';
    }

    /**
     * @param  array<string,mixed>  $episode
     * @return array<string,mixed>
     */
    public function evaluate(array $episode, string $requestedPhase): array
    {
        $requestedPhase = $this->normalize($requestedPhase);
        $current = $this->currentPhase($episode);

        if ($current === 'complete') {
            return $this->result('blocked', 'srl_episode_already_complete', $current, $requestedPhase);
        }

        $currentIndex = array_search($current, self::ORDER, true);
        $requestedIndex = array_search($requestedPhase, self::ORDER, true);

        if ($requestedIndex === false) {
            return $this->result('blocked', 'srl_unknown_phase', $current, $requestedPhase);
        }

        if ($requestedIndex < $currentIndex) {
            return $this->result('blocked', 'srl_phase_already_completed', $current, $requestedPhase);
        }

        if ($requestedIndex > $currentIndex + 1) {
            return $this->result('blocked', 'srl_phase_skipped', $current, $requestedPhase);
        }

        return $this->result('passed', 'srl_phase_appropriate', $current, $requestedPhase);
    }

    private function normalize(string $phase): string
    {
        return match ($phase) {
            'reflection' => 'self_reflection',
            default => $phase,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason, string $current, string $requested): array
    {
        return [
            'schema_version' => 'atlas.cognitive.srl_phase_transition.v1',
            'status' => $status,
            'reason' => $reason,
            'current_phase' => $current,
            'requested_phase' => $requested,
        ];
    }
}
