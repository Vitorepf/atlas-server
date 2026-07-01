<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure gate: a simplification candidate (merge/delete/refactor) may only be marked
 * ready_for_simplification once it declares behavior-preserving fixtures, baseline
 * and current observed outputs that match (within any explicitly tolerated deltas),
 * and at least one required replay check.
 *
 * Missing evidence blocks the candidate rather than silently passing it through —
 * a simplification with no proof of behavior equivalence is unsafe by default.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasSelfConstructionBehaviorEquivalenceDossier
{
    public const SCHEMA = 'atlas.self_construction.behavior_equivalence_dossier.v1';

    public const STATUS_READY = 'ready_for_simplification';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array{
     *   candidate_id?: string,
     *   fixtures?: list<string>,
     *   baseline_outputs?: array<string,mixed>,
     *   current_outputs?: array<string,mixed>,
     *   baseline_errors?: array<string,mixed>,
     *   current_errors?: array<string,mixed>,
     *   baseline_side_effects?: array<string,mixed>,
     *   current_side_effects?: array<string,mixed>,
     *   baseline_command_exit?: array<string,mixed>,
     *   current_command_exit?: array<string,mixed>,
     *   baseline_tests?: array<string,mixed>,
     *   current_tests?: array<string,mixed>,
     *   tolerated_deltas?: list<string>,
     *   replay_checks?: list<string>,
     * }  $candidate
     * @return array{schema:string, status:string, equivalence_proven:bool, blocked_reasons:list<string>, dossier_hash:?string}
     */
    public function evaluate(array $candidate): array
    {
        $candidateId = (string) ($candidate['candidate_id'] ?? '');
        $fixtures = array_values((array) ($candidate['fixtures'] ?? []));
        $baseline = (array) ($candidate['baseline_outputs'] ?? []);
        $current = (array) ($candidate['current_outputs'] ?? []);
        $toleratedDeltas = array_values((array) ($candidate['tolerated_deltas'] ?? []));
        $replayChecks = array_values((array) ($candidate['replay_checks'] ?? []));

        $reasons = [];

        if ($candidateId === '') {
            $reasons[] = 'missing_candidate_id';
        }

        if ($fixtures === []) {
            $reasons[] = 'missing_fixtures';
        }

        if ($baseline === []) {
            $reasons[] = 'missing_baseline_outputs';
        }

        if ($current === []) {
            $reasons[] = 'missing_current_outputs';
        }

        if ($replayChecks === []) {
            $reasons[] = 'missing_replay_checks';
        }

        if ($baseline !== [] && $current !== []) {
            $divergent = $this->divergentKeys($baseline, $current, $toleratedDeltas);
            if ($divergent !== []) {
                $reasons[] = 'outputs_diverge:'.implode(',', $divergent);
            }
        }

        foreach ([
            ['baseline_errors', 'current_errors', 'missing_error_expectations', 'error_parity_missing'],
            ['baseline_side_effects', 'current_side_effects', 'missing_side_effect_receipts', 'side_effect_parity_missing'],
            ['baseline_command_exit', 'current_command_exit', 'missing_command_exit_expectations', 'command_exit_parity_missing'],
            ['baseline_tests', 'current_tests', 'missing_test_parity_expectations', 'test_parity_missing'],
        ] as [$baselineKey, $currentKey, $missingReason, $parityReason]) {
            $reasons = [...$reasons, ...$this->parityReasons($candidate, $baselineKey, $currentKey, $toleratedDeltas, $missingReason, $parityReason)];
        }

        if ($reasons !== []) {
            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'equivalence_proven' => false,
                'blocked_reasons' => $reasons,
                'dossier_hash' => null,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_READY,
            'equivalence_proven' => true,
            'blocked_reasons' => [],
            'dossier_hash' => $this->stableHash($candidateId, $fixtures, $baseline, $current, $replayChecks),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $toleratedDeltas
     * @return list<string>
     */
    private function parityReasons(array $candidate, string $baselineKey, string $currentKey, array $toleratedDeltas, string $missingReason, string $parityReason): array
    {
        $baseline = (array) ($candidate[$baselineKey] ?? []);
        $current = (array) ($candidate[$currentKey] ?? []);

        if ($baseline === [] || $current === []) {
            return [$missingReason];
        }

        $divergent = $this->divergentKeys($baseline, $current, $toleratedDeltas);

        return $divergent !== [] ? [$parityReason.':'.implode(',', $divergent)] : [];
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $current
     * @param  list<string>  $toleratedDeltas
     * @return list<string>
     */
    private function divergentKeys(array $baseline, array $current, array $toleratedDeltas): array
    {
        $keys = array_unique(array_merge(array_keys($baseline), array_keys($current)));
        $divergent = [];

        foreach ($keys as $key) {
            if (in_array($key, $toleratedDeltas, true)) {
                continue;
            }

            $baselineValue = $baseline[$key] ?? null;
            $currentValue = $current[$key] ?? null;

            if ($baselineValue !== $currentValue) {
                $divergent[] = (string) $key;
            }
        }

        sort($divergent);

        return $divergent;
    }

    /**
     * @param  list<string>  $fixtures
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $current
     * @param  list<string>  $replayChecks
     */
    private function stableHash(string $candidateId, array $fixtures, array $baseline, array $current, array $replayChecks): string
    {
        ksort($baseline);
        ksort($current);
        sort($fixtures);
        sort($replayChecks);

        return hash('sha256', (string) json_encode([
            'candidate_id' => $candidateId,
            'fixtures' => $fixtures,
            'baseline_outputs' => $baseline,
            'current_outputs' => $current,
            'replay_checks' => $replayChecks,
        ], JSON_THROW_ON_ERROR));
    }
}
