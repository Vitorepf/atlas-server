<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Pure composer that summarizes the readiness of every Self-Construction organ into one FACTS-only
 * Control-Plane envelope.
 *
 * Output: {schema_version, all_ready, ready_organs, blocked_organs, missing_organs, next_required_organs}
 * NO scalar score, NO ranking. Deterministic order: organs always appear in CANONICAL_ORGANS order.
 *
 * CRITICAL organs (required for continuous 24/7 autonomy) — their absence FAILS CLOSED into missing_organs:
 *   - cortex, goal_value, strategy, architecture, task_fabric, maestro, native_worker,
 *     verification_court, merge_governor, knowledge_sync, learning_transfer.
 *
 * Per-organ status fact shape: {status: 'ready'|'blocked'|'degraded', reason?:string, blockers?:list<string>}.
 */
final class AtlasSelfConstructionOrganReadinessComposer
{
    public const SCHEMA = 'atlas.self_construction.organ_readiness.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_MISSING = 'missing';

    public const CANONICAL_ORGANS = [
        'cortex',
        'goal_value',
        'strategy',
        'architecture',
        'task_fabric',
        'maestro',
        'native_worker',
        'verification_court',
        'merge_governor',
        'knowledge_sync',
        'learning_transfer',
    ];

    public const CRITICAL_ORGANS = self::CANONICAL_ORGANS; // every canonical organ is critical for continuous autonomy

    /**
     * Canonical circuit edges — the integrated path from Brain to Outcome Learning.
     * Each edge connects two consecutive organs in the CANONICAL_ORGANS chain plus
     * the extra edges that close the learning loop.
     */
    public const CIRCUIT_EDGES = [
        'cortex_to_goal_value',
        'goal_value_to_strategy',
        'strategy_to_architecture',
        'architecture_to_task_fabric',
        'task_fabric_to_maestro',
        'maestro_to_native_worker',
        'native_worker_to_verification_court',
        'verification_court_to_merge_governor',
        'merge_governor_to_knowledge_sync',
        'knowledge_sync_to_learning_transfer',
        'learning_transfer_to_cortex',
        // Extra domain edges for the full circuit
        'proof_to_outcome_learning',
    ];

    /**
     * @param  array<string,array<string,mixed>>  $organFacts  organ_id => {status, reason?, blockers?}
     * @param  array<string,array<string,mixed>>  $circuitEdges  edge_id => {connected:bool}
     * @return array<string,mixed>
     */
    public function compose(array $organFacts, array $circuitEdges = []): array
    {
        $ready = [];
        $blocked = [];
        $degraded = [];
        $missing = [];
        $nextRequired = [];

        foreach (self::CANONICAL_ORGANS as $organ) {
            $row = $organFacts[$organ] ?? null;
            if (! is_array($row) || ! isset($row['status'])) {
                $missing[] = $organ;
                if (in_array($organ, self::CRITICAL_ORGANS, true)) {
                    $nextRequired[] = $organ;
                }

                continue;
            }
            $status = (string) $row['status'];
            switch ($status) {
                case self::STATUS_READY:
                    $evidenceBlockers = $this->evidenceFloorBlockers($row);
                    if ($evidenceBlockers !== []) {
                        $blocked[] = [
                            'organ' => $organ,
                            'reason' => 'evidence_floor_not_met',
                            'blockers' => $evidenceBlockers,
                        ];
                        if (in_array($organ, self::CRITICAL_ORGANS, true)) {
                            $nextRequired[] = $organ;
                        }
                        break;
                    }
                    if ((string) ($row['freshness_status'] ?? '') === 'stale') {
                        $degraded[] = ['organ' => $organ, 'reason' => 'evidence_stale'];
                        $nextRequired[] = $organ;
                        break;
                    }
                    $ready[] = $organ;
                    break;
                case self::STATUS_BLOCKED:
                    $blocked[] = [
                        'organ' => $organ,
                        'reason' => (string) ($row['reason'] ?? 'blocked_no_reason'),
                        'blockers' => array_values((array) ($row['blockers'] ?? [])),
                    ];
                    if (in_array($organ, self::CRITICAL_ORGANS, true)) {
                        $nextRequired[] = $organ;
                    }
                    break;
                case self::STATUS_DEGRADED:
                    $degraded[] = [
                        'organ' => $organ,
                        'reason' => (string) ($row['reason'] ?? 'degraded_no_reason'),
                    ];
                    $nextRequired[] = $organ;
                    break;
                default:
                    $missing[] = $organ;
                    $nextRequired[] = $organ;
            }
        }

        $total = count(self::CANONICAL_ORGANS);

        $readinessRatio = $total > 0 ? round(count($ready) / $total, 4) : 0.0;

        $topBlockers = [];
        foreach ($blocked as $b) {
            foreach ((array) $b['blockers'] as $blocker) {
                $topBlockers[] = (string) $blocker;
            }
        }
        $topBlockers = array_values(array_unique($topBlockers));
        sort($topBlockers, SORT_STRING);

        // ── Integrated circuit-path validation ──────────────────────────────
        $missingEdges = [];
        $firstMissingEdge = null;
        foreach (self::CIRCUIT_EDGES as $edge) {
            $edgeRow = $circuitEdges[$edge] ?? null;
            $connected = is_array($edgeRow) && ($edgeRow['connected'] ?? false) === true;
            if (! $connected) {
                $missingEdges[] = $edge;
                if ($firstMissingEdge === null) {
                    $firstMissingEdge = $edge;
                }
            }
        }

        // Circuit-path validation is opt-in: only gates all_ready when the caller explicitly
        // supplies circuit_edges. Callers that never pass it keep the original organ-only verdict.
        $circuitChecked = $circuitEdges !== [];
        $circuitComplete = ! $circuitChecked || $missingEdges === [];
        $organsReady = $blocked === [] && $missing === [] && $degraded === [];
        $allReady = $organsReady && $circuitComplete;

        return [
            'schema_version' => self::SCHEMA,
            'all_ready' => $allReady,
            'ready_organs' => $ready,
            'blocked_organs' => $blocked,
            'degraded_organs' => $degraded,
            'missing_organs' => $missing,
            'next_required_organs' => array_values(array_unique($nextRequired)),
            'readiness_ratio' => $readinessRatio,
            'top_blockers' => $topBlockers,
            'circuit_complete' => $circuitComplete,
            'missing_edges' => $missingEdges,
            'missing_edge' => $firstMissingEdge,
            'circuit_path' => self::CANONICAL_ORGANS,
        ];
    }

    public const CIRCUIT_STATUS_RUNTIME_READY   = 'runtime_ready';
    public const CIRCUIT_STATUS_DORMANT_NOT_READY = 'dormant_not_ready';
    public const CIRCUIT_STATUS_NEEDS_REPROOF   = 'needs_reproof';
    public const CIRCUIT_STATUS_NOT_IMPLEMENTED = 'not_implemented';

    private const PROOF_STALE_DAYS_THRESHOLD = 30;

    /**
     * Class-level readiness: a class is runtime_ready only once it has an
     * implementation, tests, at least one real circuit consumer, AND fresh proof —
     * implemented-but-unwired classes are dormant_not_ready, and stale proof on an
     * otherwise-wired class is needs_reproof, never silently counted as ready.
     *
     * @param  array{classes?: list<array{
     *   class_id?: string,
     *   has_implementation?: bool,
     *   has_tests?: bool,
     *   consumer_count?: int,
     *   proof_freshness_days?: int,
     * }>}  $facts
     * @return array{schema:string, entries:list<array<string,mixed>>, runtime_ready_classes:list<string>, dormant_classes:list<string>, needs_reproof_classes:list<string>}
     */
    public function composeCircuitReadiness(array $facts): array
    {
        $classes = is_array($facts['classes'] ?? null) ? $facts['classes'] : [];

        $entries = [];
        $runtimeReady = [];
        $dormant = [];
        $needsReproof = [];

        foreach ($classes as $row) {
            if (! is_array($row) || ! isset($row['class_id'])) {
                continue;
            }
            $classId = (string) $row['class_id'];
            $hasImplementation = (bool) ($row['has_implementation'] ?? false);
            $hasTests = (bool) ($row['has_tests'] ?? false);
            $consumerCount = max(0, (int) ($row['consumer_count'] ?? 0));
            $proofFreshnessDays = array_key_exists('proof_freshness_days', $row)
                ? max(0, (int) $row['proof_freshness_days'])
                : null;

            $reasons = [];
            $status = match (true) {
                ! $hasImplementation => self::CIRCUIT_STATUS_NOT_IMPLEMENTED,
                $consumerCount === 0 || ! $hasTests => self::CIRCUIT_STATUS_DORMANT_NOT_READY,
                $proofFreshnessDays === null || $proofFreshnessDays > self::PROOF_STALE_DAYS_THRESHOLD => self::CIRCUIT_STATUS_NEEDS_REPROOF,
                default => self::CIRCUIT_STATUS_RUNTIME_READY,
            };

            if ($status === self::CIRCUIT_STATUS_NOT_IMPLEMENTED) {
                $reasons[] = 'no_implementation';
            }
            if ($status === self::CIRCUIT_STATUS_DORMANT_NOT_READY) {
                if ($consumerCount === 0) {
                    $reasons[] = 'no_circuit_consumers';
                }
                if (! $hasTests) {
                    $reasons[] = 'no_tests';
                }
            }
            if ($status === self::CIRCUIT_STATUS_NEEDS_REPROOF) {
                $reasons[] = $proofFreshnessDays === null ? 'no_proof_recorded' : "proof_stale:{$proofFreshnessDays}_days";
            }

            $entries[] = [
                'class_id' => $classId,
                'status'   => $status,
                'reasons'  => $reasons,
            ];

            match ($status) {
                self::CIRCUIT_STATUS_RUNTIME_READY => $runtimeReady[] = $classId,
                self::CIRCUIT_STATUS_DORMANT_NOT_READY => $dormant[] = $classId,
                self::CIRCUIT_STATUS_NEEDS_REPROOF => $needsReproof[] = $classId,
                default => null,
            };
        }

        return [
            'schema' => self::SCHEMA,
            'entries' => $entries,
            'runtime_ready_classes' => $runtimeReady,
            'dormant_classes' => $dormant,
            'needs_reproof_classes' => $needsReproof,
        ];
    }

    /** @return list<string> */
    private function evidenceFloorBlockers(array $row): array
    {
        $blockers = [];
        $refs = array_values(array_filter(array_map('strval', (array) ($row['evidence_refs'] ?? []))));
        if ($refs === []) {
            $blockers[] = 'evidence_floor_missing:evidence_refs';
        }
        if (trim((string) ($row['last_verified_at'] ?? '')) === '') {
            $blockers[] = 'evidence_floor_missing:last_verified_at';
        }
        if (! array_key_exists('freshness_status', $row)) {
            $blockers[] = 'evidence_floor_missing:freshness_status';
        }

        return $blockers;
    }
}
