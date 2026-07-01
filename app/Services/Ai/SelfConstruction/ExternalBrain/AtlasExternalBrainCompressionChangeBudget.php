<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof-backed change-size budget: autonomous simplification needs waves large enough to matter
 * but small enough to prove and recover from. This gate turns risk level, proof coverage,
 * rollback-path presence and worker capacity into a concrete max_files / max_actions budget —
 * never a static constant. A missing rollback path or severely eroded proof coverage holds the
 * wave entirely; high risk or moderately degraded proof coverage shrinks the budget instead of
 * blocking it outright.
 *
 * Input shape:
 *   { facts: {
 *       risk_level?:         string,  // 'low'|'medium'|'high', default 'low'
 *       proof_coverage?:     float,   // 0.0-1.0, default 1.0 (healthy)
 *       has_rollback_path?:  bool,
 *       worker_capacity?:    int,     // concurrent workers available, default 1
 *   } }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionChangeBudget
{
    public const SCHEMA = 'atlas.self_construction.external_brain.compression_change_budget.v1';

    public const DECISION_PROCEED = 'proceed';

    public const DECISION_HOLD = 'hold';

    private const BASE_MAX_FILES = [
        'high' => 3,
        'medium' => 10,
        'low' => 20,
    ];

    private const PROOF_COVERAGE_CRITICAL_CEILING = 0.30;

    private const PROOF_COVERAGE_DEGRADED_CEILING = 0.50;

    private const MAX_ACTIONS_PER_WORKER = 5;

    /**
     * @param  array{facts?: array<string,mixed>}  $facts
     * @return array{schema:string, decision:string, max_files:int, max_actions:int, required_prework:list<string>}
     */
    public function budget(array $facts): array
    {
        $f = is_array($facts['facts'] ?? null) ? $facts['facts'] : [];

        $riskLevel = strtolower(trim((string) ($f['risk_level'] ?? 'low')));
        $proofCoverage = (float) ($f['proof_coverage'] ?? 1.0);
        $hasRollbackPath = (bool) ($f['has_rollback_path'] ?? false);
        $workerCapacity = max(0, (int) ($f['worker_capacity'] ?? 1));

        $requiredPrework = [];
        if (! $hasRollbackPath) {
            $requiredPrework[] = 'rollback_path_proof';
        }
        if ($proofCoverage < self::PROOF_COVERAGE_DEGRADED_CEILING) {
            $requiredPrework[] = 'proof_coverage_improvement';
        }

        if (! $hasRollbackPath || $proofCoverage < self::PROOF_COVERAGE_CRITICAL_CEILING) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_HOLD,
                'max_files' => 0,
                'max_actions' => 0,
                'required_prework' => $requiredPrework,
            ];
        }

        $maxFiles = self::BASE_MAX_FILES[$riskLevel] ?? self::BASE_MAX_FILES['low'];
        if ($proofCoverage < self::PROOF_COVERAGE_DEGRADED_CEILING) {
            $maxFiles = (int) floor($maxFiles * 0.5);
        }

        $maxActions = min($maxFiles, max(1, $workerCapacity) * self::MAX_ACTIONS_PER_WORKER);

        return [
            'schema' => self::SCHEMA,
            'decision' => self::DECISION_PROCEED,
            'max_files' => $maxFiles,
            'max_actions' => $maxActions,
            'required_prework' => $requiredPrework,
        ];
    }
}
