<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\GoalValue;

/**
 * Pure mapper that maps broad self-construction goals to concrete capability
 * traces so originators choose tasks that improve a named capability, not a slogan.
 *
 * A valid capability trace links:
 *   - objective: what the task does
 *   - capability: which named capability it improves
 *   - touched_organ: which organ/module it touches
 *   - runnable_proof: concrete test command proving the capability
 *
 * Slogan-only candidates (missing any of the above) fail.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionGoalToCapabilityTraceMapper
{
    public const SCHEMA = 'atlas.self_construction.goal_to_capability_trace_mapper.v1';

    private const MIN_OBJECTIVE_LENGTH = 10;

    private const RUNNABLE_PROOF_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function map(array $candidate): array
    {
        $objective = trim((string) ($candidate['objective'] ?? ''));
        $capability = trim((string) ($candidate['capability'] ?? ''));
        $touchedOrgan = trim((string) ($candidate['touched_organ'] ?? ''));
        $runnableProof = trim((string) ($candidate['runnable_proof'] ?? ''));

        $failures = [];

        if ($objective === '' || mb_strlen($objective) < self::MIN_OBJECTIVE_LENGTH) {
            $failures[] = 'objective_missing_or_too_short';
        }

        if ($capability === '') {
            $failures[] = 'capability_not_named';
        }

        if ($touchedOrgan === '') {
            $failures[] = 'touched_organ_not_named';
        }

        if ($runnableProof === '') {
            $failures[] = 'runnable_proof_missing';
        } else {
            $hasMarker = false;
            $lower = strtolower($runnableProof);
            foreach (self::RUNNABLE_PROOF_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    $hasMarker = true;
                    break;
                }
            }
            if (! $hasMarker) {
                $failures[] = 'runnable_proof_not_recognized';
            }
        }

        $passed = $failures === [];

        return [
            'schema_version' => self::SCHEMA,
            'passed' => $passed,
            'failures' => $failures,
            'objective' => $objective,
            'capability' => $capability,
            'touched_organ' => $touchedOrgan,
            'runnable_proof' => $runnableProof,
            'is_slogan_only' => ! $passed,
        ];
    }

    /**
     * Map a batch of candidates.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function mapBatch(array $candidates): array
    {
        $results = [];
        $passedCount = 0;

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $result = $this->map($candidate);
            $results[] = $result;
            if ($result['passed']) {
                $passedCount++;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'results' => $results,
            'total' => count($results),
            'passed' => $passedCount,
            'failed' => count($results) - $passedCount,
        ];
    }
}
