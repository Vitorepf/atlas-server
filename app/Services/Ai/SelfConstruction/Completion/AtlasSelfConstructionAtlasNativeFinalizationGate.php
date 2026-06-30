<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Composite finalization gate. Pure, facts-only.
 *
 * Composes:
 *   - {@see AtlasSelfConstructionAtlasNativeEvidenceVerifier}::verify($facts['evidence_facts'])
 *   - {@see AtlasSelfConstructionHumanDependencyRegressionGate}::check($facts['dependency_facts'])
 *   - $facts['autonomy_verdict']  : runtime autonomy level snapshot {level: 'atlas_native_bounded'|'atlas_native_24_7'|..}
 *   - $facts['ledger']            : runtime autonomy ledger snapshot
 *                                   {queue_blocker, rollback_blocker, code_index_blocker, docs_sync_blocker,
 *                                    release_blocker, learning_blocker, unsafe_release}
 *
 * Final states:
 *   - ready   — all green
 *   - hold    — refreshable proof missing (stale code index / docs / learning)
 *   - blocked — autonomy contract violation or unsafe release
 */
final class AtlasSelfConstructionAtlasNativeFinalizationGate
{
    public const SCHEMA = 'atlas.self_construction.atlas_native_finalization_gate.v1';

    public const FINAL_READY = 'ready';

    public const FINAL_HOLD = 'hold';

    public const FINAL_BLOCKED = 'blocked';

    /** @var list<string> */
    private const LEDGER_BLOCKING_KEYS = [
        'queue_blocker',
        'rollback_blocker',
        'release_blocker',
        'unsafe_release',
    ];

    /** @var list<string> */
    private const LEDGER_HOLD_KEYS = [
        'code_index_blocker',
        'docs_sync_blocker',
        'learning_blocker',
    ];

    /** @var list<string> */
    private const REQUIRED_AUTONOMY_LEVELS = ['atlas_native_bounded', 'atlas_native_24_7'];

    public const MIN_SOAK_HOURS = 24;

    public const MIN_UNATTENDED_RECOVERY_EVENTS = 1;

    public const MIN_QUEUE_DRAIN_CYCLES = 1;

    public function __construct(
        private readonly ?AtlasSelfConstructionAtlasNativeEvidenceVerifier $evidenceVerifier = null,
        private readonly ?AtlasSelfConstructionHumanDependencyRegressionGate $dependencyGate = null,
    ) {}

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function finalize(array $facts): array
    {
        $evidenceVerifier = $this->evidenceVerifier ?? new AtlasSelfConstructionAtlasNativeEvidenceVerifier();
        $dependencyGate = $this->dependencyGate ?? new AtlasSelfConstructionHumanDependencyRegressionGate();

        $evidenceFacts = is_array($facts['evidence_facts'] ?? null) ? $facts['evidence_facts'] : [];
        $dependencyFacts = is_array($facts['dependency_facts'] ?? null) ? $facts['dependency_facts'] : [];
        $autonomyVerdict = is_array($facts['autonomy_verdict'] ?? null) ? $facts['autonomy_verdict'] : [];
        $ledger = is_array($facts['ledger'] ?? null) ? $facts['ledger'] : [];

        $evidence = $evidenceVerifier->verify($evidenceFacts);
        $dependency = $dependencyGate->check($dependencyFacts);

        $sourceCoverage = $this->sourceCoverage($evidence);

        $blockers = [];
        $nextActions = [];

        if (! (bool) $evidence['passed']) {
            foreach ((array) $evidence['blockers'] as $blocker) {
                $blockers[] = 'evidence:'.$blocker;
            }
            $nextActions[] = 'refresh_evidence_facts';
        }
        if (! (bool) $dependency['passed']) {
            foreach ((array) $dependency['blockers'] as $blocker) {
                $blockers[] = 'dependency:'.$blocker;
            }
            $nextActions[] = 'remove_non_atlas_dependencies_or_label_as_advisory_exception';
        }

        $level = (string) ($autonomyVerdict['level'] ?? '');
        if (! in_array($level, self::REQUIRED_AUTONOMY_LEVELS, true)) {
            $blockers[] = 'autonomy_level_below_floor:'.$level;
            $nextActions[] = 'promote_runtime_autonomy_level_to_at_least_atlas_native_bounded';
        }

        $autonomyContractBlocked = false;
        foreach (self::LEDGER_BLOCKING_KEYS as $key) {
            if ((bool) ($ledger[$key] ?? false)) {
                $blockers[] = 'ledger_blocker:'.$key;
                $autonomyContractBlocked = true;
            }
        }

        $holdRequested = false;
        foreach (self::LEDGER_HOLD_KEYS as $key) {
            if ((bool) ($ledger[$key] ?? false)) {
                $holdRequested = true;
                $nextActions[] = 'refresh_'.$key;
            }
        }

        // 24/7 soak proof checks — missing or insufficient → hold (refreshable by running more soak).
        $soakFacts = is_array($facts['soak_proof'] ?? null) ? $facts['soak_proof'] : [];
        $soakHours = isset($soakFacts['runtime_soak_hours']) ? (int) $soakFacts['runtime_soak_hours'] : null;
        $recoveryEvents = isset($soakFacts['unattended_recovery_events']) ? (int) $soakFacts['unattended_recovery_events'] : null;
        $drainCycles = isset($soakFacts['queue_drain_cycles']) ? (int) $soakFacts['queue_drain_cycles'] : null;
        if ($soakHours === null || $soakHours < self::MIN_SOAK_HOURS) {
            $holdRequested = true;
            $nextActions[] = 'extend_runtime_soak_to_minimum_hours';
        }
        if ($recoveryEvents === null || $recoveryEvents < self::MIN_UNATTENDED_RECOVERY_EVENTS) {
            $holdRequested = true;
            $nextActions[] = 'record_unattended_recovery_events';
        }
        if ($drainCycles === null || $drainCycles < self::MIN_QUEUE_DRAIN_CYCLES) {
            $holdRequested = true;
            $nextActions[] = 'complete_queue_drain_cycles';
        }

        // No-human dependency proof — any status other than 'pass' → blocked.
        $humanDepRegression = is_array($facts['human_dependency_regression'] ?? null) ? $facts['human_dependency_regression'] : [];
        $humanDepStatus = (string) ($humanDepRegression['status'] ?? '');
        $humanDepRegressionFailed = $humanDepStatus !== 'pass';
        if ($humanDepRegressionFailed) {
            $blockers[] = 'human_dependency_regression_not_passed:'.($humanDepStatus === '' ? 'missing' : $humanDepStatus);
        }

        $finalState = self::FINAL_READY;
        if ($blockers !== []) {
            // If only refreshable-style blockers (none from ledger blocking keys, none dependency contract),
            // surface HOLD; otherwise BLOCKED. We classify as BLOCKED when there's any autonomy/dependency
            // contract failure or any LEDGER_BLOCKING_KEYS trigger.
            $hasContractBlocker = $autonomyContractBlocked
                || ! (bool) $dependency['passed']
                || $humanDepRegressionFailed
                || in_array('autonomy_level_below_floor:'.$level, $blockers, true)
                || ((string) ($evidence['status'] ?? '') === AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED)
                || $sourceCoverage['blocked_sources'] !== [];
            $finalState = $hasContractBlocker ? self::FINAL_BLOCKED : self::FINAL_HOLD;
        } elseif ($holdRequested) {
            $finalState = self::FINAL_HOLD;
        }

        $passed = $finalState === self::FINAL_READY;

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'final_state' => $finalState,
            'passed' => $passed,
            'blockers' => $blockers,
            'source_coverage' => $sourceCoverage,
            'readiness_sections' => [
                'evidence_verifier' => [
                    'passed' => (bool) $evidence['passed'],
                    'status' => (string) ($evidence['status'] ?? ''),
                ],
                'dependency_gate' => [
                    'passed' => (bool) $dependency['passed'],
                    'status' => (string) ($dependency['status'] ?? ''),
                ],
                'autonomy_level' => $level,
                'ledger' => $ledger,
            ],
            'next_atlas_actions' => array_values(array_unique($nextActions)),
        ];
    }

    /**
     * Project the evidence verifier's per-source signals into the gate's
     * source_coverage shape so callers can read missing/hold/blocked source
     * counts directly without re-deriving them.
     *
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function sourceCoverage(array $evidence): array
    {
        $observed = is_array($evidence['sources_observed'] ?? null) ? $evidence['sources_observed'] : [];
        $sourceBlockers = is_array($evidence['source_blockers'] ?? null) ? $evidence['source_blockers'] : [];

        $missing = [];
        $hold = [];
        $blocked = [];

        foreach ($sourceBlockers as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $sourceId = (string) ($blocker['source_id'] ?? '');
            $kind = (string) ($blocker['kind'] ?? '');
            $refreshable = (bool) ($blocker['refreshable'] ?? false);

            if (in_array($kind, ['source_missing', 'source_stale'], true)) {
                $missing[] = $sourceId;
                if ($refreshable) {
                    $hold[] = $sourceId;
                } else {
                    $blocked[] = $sourceId;
                }

                continue;
            }
            // source_failed / source_contradictory / source_unknown_status are always unsafe.
            $blocked[] = $sourceId;
        }

        sort($missing, SORT_STRING);
        sort($hold, SORT_STRING);
        sort($blocked, SORT_STRING);

        $required = count($observed);
        $observedPass = 0;
        foreach ($observed as $status) {
            if ((string) $status === AtlasSelfConstructionAtlasNativeEvidenceVerifier::SOURCE_STATUS_PASS) {
                $observedPass++;
            }
        }

        return [
            'required_count' => $required,
            'observed_count' => $observedPass,
            'missing_sources' => array_values(array_unique($missing)),
            'hold_sources' => array_values(array_unique($hold)),
            'blocked_sources' => array_values(array_unique($blocked)),
        ];
    }
}
