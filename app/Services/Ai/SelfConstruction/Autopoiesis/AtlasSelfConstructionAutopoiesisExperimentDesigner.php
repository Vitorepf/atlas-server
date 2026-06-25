<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autopoiesis;

use RuntimeException;

/**
 * Designs GOVERNED Self-Improvement TRIALS. Pure: converts an accepted HYPOTHESIS into a bounded
 * EXPERIMENT PLAN with scope + gates + rollback + success_evidence. Preserves separation of powers:
 *   - Autopoiesis (this service) PROPOSES the experiment.
 *   - Task Fabric (separate) PACKETIZES it into runnable packets.
 *   - Verification Court (separate) VERIFIES the outcome.
 *
 * INPUT (hypothesis):
 *   { hypothesis_id, claim, scope_paths:list<string>, expected_evidence:list<string>,
 *     gates:list<string>, rollback:{strategy:string, affected_files:list<string>} }
 *
 * OUTPUT:
 *   { schema, experiment_id, scope_paths, gates, rollback, success_evidence, separation_of_powers,
 *     status:'designed', plan_hash }
 *
 * REJECTS (throws RuntimeException) when:
 *   - rollback.strategy is missing/empty
 *   - rollback.affected_files is empty
 *   - expected_evidence is empty
 *   - scope_paths is empty
 *   - any path looks like a broad directory (no extension or trailing '/')
 *
 * INVARIANTS:
 *   - DETERMINISTIC plan_hash = sha256(canonical {hypothesis_id, scope_paths, gates, rollback, success_evidence}).
 *   - PURE — no I/O.
 */
final class AtlasSelfConstructionAutopoiesisExperimentDesigner
{
    public const SCHEMA = 'atlas.autopoiesis.experiment_plan.v1';

    public const SEPARATION_OF_POWERS = [
        'autopoiesis_role' => 'propose',
        'task_fabric_role' => 'packetize',
        'verification_court_role' => 'verify',
    ];

    /**
     * @param  array{
     *     hypothesis_id:string,
     *     claim:string,
     *     scope_paths:list<string>,
     *     expected_evidence:list<string>,
     *     gates:list<string>,
     *     rollback:array{strategy?:string, affected_files?:list<string>}
     * }  $hypothesis
     * @return array{schema:string, experiment_id:string, scope_paths:list<string>, gates:list<string>, rollback:array<string,mixed>, success_evidence:list<string>, separation_of_powers:array<string,string>, status:string, plan_hash:string}
     */
    public function design(array $hypothesis): array
    {
        $hypothesisId = trim((string) ($hypothesis['hypothesis_id'] ?? ''));
        if ($hypothesisId === '') {
            throw new RuntimeException('autopoiesis_designer: missing hypothesis_id');
        }
        $scopePaths = is_array($hypothesis['scope_paths'] ?? null) ? array_values(array_map('strval', $hypothesis['scope_paths'])) : [];
        if ($scopePaths === []) {
            throw new RuntimeException('autopoiesis_designer: empty scope_paths');
        }
        foreach ($scopePaths as $p) {
            if ($p === '' || str_ends_with($p, '/') || ! str_contains(basename($p), '.')) {
                throw new RuntimeException('autopoiesis_designer: broad directory rejected: '.$p);
            }
        }
        $expected = is_array($hypothesis['expected_evidence'] ?? null) ? array_values(array_map('strval', $hypothesis['expected_evidence'])) : [];
        if ($expected === []) {
            throw new RuntimeException('autopoiesis_designer: empty expected_evidence');
        }
        $gates = is_array($hypothesis['gates'] ?? null) ? array_values(array_map('strval', $hypothesis['gates'])) : [];
        $rollback = is_array($hypothesis['rollback'] ?? null) ? $hypothesis['rollback'] : [];
        $strategy = trim((string) ($rollback['strategy'] ?? ''));
        if ($strategy === '') {
            throw new RuntimeException('autopoiesis_designer: rollback.strategy missing');
        }
        $affected = is_array($rollback['affected_files'] ?? null) ? array_values(array_map('strval', $rollback['affected_files'])) : [];
        if ($affected === []) {
            throw new RuntimeException('autopoiesis_designer: rollback.affected_files empty');
        }
        sort($scopePaths, SORT_STRING);
        sort($expected, SORT_STRING);
        sort($gates, SORT_STRING);
        sort($affected, SORT_STRING);

        $experimentId = 'exp:'.$hypothesisId;
        $canonical = [
            'hypothesis_id' => $hypothesisId,
            'scope_paths' => $scopePaths,
            'gates' => $gates,
            'rollback' => ['strategy' => $strategy, 'affected_files' => $affected],
            'success_evidence' => $expected,
        ];
        ksort($canonical);

        return [
            'schema' => self::SCHEMA,
            'experiment_id' => $experimentId,
            'scope_paths' => $scopePaths,
            'gates' => $gates,
            'rollback' => ['strategy' => $strategy, 'affected_files' => $affected],
            'success_evidence' => $expected,
            'separation_of_powers' => self::SEPARATION_OF_POWERS,
            'status' => 'designed',
            'plan_hash' => hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }
}
