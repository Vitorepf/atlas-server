<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure integration. Turns a claimable packet envelope into a BOUNDED native worker execution request +
 * the evidence-write expectation the verification path will check.
 *
 * Refuses packets that lack:
 *   - non-empty allowed_files (no scope ⇒ nothing to write)
 *   - non-empty required_evidence_kinds (no gates ⇒ no way to certify)
 *   - quality_facts.bite_proof OR quality_facts.acceptance_contract (no self-sufficient quality)
 *
 * Output:
 *   {schema_version, request:{task_packet_id, lease_id, allowed_files, required_evidence_kinds,
 *     write_expectation:{evidence_path, gate_outputs_required}}, accepted:bool, blockers:list<string>}
 *
 * Pure: NEVER claims the packet, dispatches the worker, or writes anything.
 */
final class AtlasSelfConstructionContinuousRuntimeWorkerIntegration
{
    public const SCHEMA = 'atlas.continuous_runtime.worker_integration.v1';

    public const PACKET_EVAL_SCHEMA = 'atlas.continuous_runtime.worker_integration.packet_eval.v1';

    public const NATIVE_POOL_SCHEMA = 'atlas.native_worker.pool_supervisor.v1';

    public const WORKER_HEARTBEAT_STALE_SECONDS = 300;

    /**
     * Steady-state execution must NEVER depend on these worker kinds.
     *
     * @var list<string>
     */
    public const REFUSED_EXECUTION_DEPENDENCIES = [
        'operator',
        'human',
        'external_provider',
        'claude_code',
        'codex',
        'cursor',
        'network',
        'unrestricted_shell',
    ];

    /**
     * @param  array<string,mixed>  $packet  the claimable packet envelope
     * @return array<string,mixed>
     */
    public function integrate(array $packet): array
    {
        $taskId = (string) ($packet['task_packet_id'] ?? '');
        $leaseId = (string) ($packet['lease_id'] ?? '');
        $allowed = array_values((array) ($packet['allowed_files'] ?? []));
        $required = array_values((array) ($packet['required_evidence_kinds'] ?? []));
        $quality = (array) ($packet['quality_facts'] ?? []);

        $blockers = [];
        if ($taskId === '') {
            $blockers[] = 'task_packet_id_missing';
        }
        if ($leaseId === '') {
            $blockers[] = 'lease_id_missing';
        }
        if ($allowed === []) {
            $blockers[] = 'allowed_files_empty';
        }
        if ($required === []) {
            $blockers[] = 'required_evidence_kinds_empty';
        }
        $biteProof = (bool) ($quality['bite_proof'] ?? false);
        $hasAcceptance = is_array($quality['acceptance_contract'] ?? null) && $quality['acceptance_contract'] !== [];
        if (! $biteProof && ! $hasAcceptance) {
            $blockers[] = 'quality_facts_missing_self_sufficient_signal';
        }

        $executionDependencies = array_values((array) ($packet['execution_dependencies'] ?? []));
        foreach ($executionDependencies as $dep) {
            if (in_array((string) $dep, self::REFUSED_EXECUTION_DEPENDENCIES, true)) {
                $blockers[] = 'execution_dependency_refused:'.(string) $dep;
            }
        }

        $nativePool = $this->classifyNativePool(is_array($packet['native_pool_receipt'] ?? null) ? $packet['native_pool_receipt'] : null);

        if ($blockers !== []) {
            return [
                'schema_version' => self::SCHEMA,
                'accepted' => false,
                'request' => null,
                'native_pool_facts' => $nativePool,
                'blockers' => array_values($blockers),
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'accepted' => true,
            'request' => [
                'task_packet_id' => $taskId,
                'lease_id' => $leaseId,
                'allowed_files' => $allowed,
                'required_evidence_kinds' => $required,
                'write_expectation' => [
                    'evidence_path' => 'storage/atlas/self_construction/evidence/'.$taskId.'.jsonl',
                    'gate_outputs_required' => $required,
                ],
            ],
            'native_pool_facts' => $nativePool,
            'blockers' => [],
        ];
    }

    /**
     * Emit deterministic runtime-balancing recommendations from queue pressure, worker readiness,
     * and heartbeat freshness. NEVER spawns anything — pure facts output only.
     *
     * Possible recommendations: spawn | hold | drain | repair_worker
     *
     * @param  array{
     *     servable_queue_depth?:int,
     *     available_worker_count?:int,
     *     worker_ready?:bool,
     *     heartbeat_age_seconds?:int,
     *     worker_readiness_safe?:bool
     * }  $facts
     * @return array{schema:string, recommendations:list<string>, facts_observed:array<string,mixed>}
     */
    public function recommend(array $facts): array
    {
        $queueDepth = (int) ($facts['servable_queue_depth'] ?? 0);
        $availableWorkers = (int) ($facts['available_worker_count'] ?? 0);
        $workerReady = (bool) ($facts['worker_ready'] ?? false);
        $heartbeatAge = (int) ($facts['heartbeat_age_seconds'] ?? 0);
        $workerReadinessSafe = (bool) ($facts['worker_readiness_safe'] ?? true);

        $recommendations = [];

        if ($queueDepth === 0) {
            $recommendations[] = 'hold';
        }
        if ($queueDepth > 0 && $workerReady && $availableWorkers > 0) {
            $recommendations[] = 'spawn';
        }
        if (! $workerReadinessSafe) {
            $recommendations[] = 'drain';
        }
        if ($heartbeatAge > self::WORKER_HEARTBEAT_STALE_SECONDS) {
            $recommendations[] = 'repair_worker';
        }

        sort($recommendations, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'recommendations' => $recommendations,
            'facts_observed' => [
                'servable_queue_depth' => $queueDepth,
                'available_worker_count' => $availableWorkers,
                'worker_ready' => $workerReady,
                'heartbeat_age_seconds' => $heartbeatAge,
                'worker_readiness_safe' => $workerReadinessSafe,
            ],
        ];
    }

    /**
     * Evaluate a packet's fitness for dispatch without claiming it.
     *
     * Produces structured readiness verdict, scope safety, evidence requirements,
     * feedback hooks, and abstain reasons. A packet is NOT recommended when
     * allowed_files is empty, runnable proof is absent, or no safe feedback path exists.
     *
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function evaluatePacket(array $packet): array
    {
        $allowed = array_values(array_filter(array_map('strval', (array) ($packet['allowed_files'] ?? [])), static fn (string $f): bool => $f !== ''));
        $required = array_values(array_filter(array_map('strval', (array) ($packet['required_evidence_kinds'] ?? [])), static fn (string $k): bool => $k !== ''));
        $quality = is_array($packet['quality_facts'] ?? null) ? $packet['quality_facts'] : [];
        $feedbackHooks = is_array($packet['feedback_hooks'] ?? null) ? array_values($packet['feedback_hooks']) : [];

        $biteProof = (bool) ($quality['bite_proof'] ?? false);
        $hasAcceptance = is_array($quality['acceptance_contract'] ?? null) && $quality['acceptance_contract'] !== [];
        $hasRunnableProof = $biteProof || $hasAcceptance;

        $broadPaths = array_values(array_filter($allowed, static fn (string $f): bool => str_ends_with($f, '/') || ! str_contains(basename($f), '.')));
        $scopeSafe = $allowed !== [] && $broadPaths === [];
        $feedbackSafe = $feedbackHooks !== [];

        $abstainReasons = [];
        if ($allowed === []) {
            $abstainReasons[] = 'no_allowed_files';
        }
        if (! $hasRunnableProof) {
            $abstainReasons[] = 'no_runnable_proof';
        }
        if (! $feedbackSafe) {
            $abstainReasons[] = 'no_safe_feedback_path';
        }
        sort($abstainReasons, SORT_STRING);

        return [
            'schema_version' => self::PACKET_EVAL_SCHEMA,
            'dispatch_recommended' => $abstainReasons === [],
            'readiness_verdict' => [
                'ready' => $abstainReasons === [],
                'allowed_files_ok' => $allowed !== [],
                'runnable_proof_ok' => $hasRunnableProof,
                'feedback_path_safe' => $feedbackSafe,
                'scope_safe' => $scopeSafe,
            ],
            'scope_safety' => [
                'safe' => $scopeSafe,
                'allowed_files_count' => count($allowed),
                'broad_paths_found' => $broadPaths !== [],
            ],
            'evidence_requirements' => [
                'required_kinds' => $required,
                'min_count' => count($required),
                'satisfied' => $required !== [],
            ],
            'feedback_hooks' => $feedbackHooks,
            'abstain_reasons' => $abstainReasons,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $receipt
     * @return array<string,mixed>
     */
    private function classifyNativePool(?array $receipt): array
    {
        if ($receipt === null) {
            return [
                'native_pool_ready' => false,
                'native_pool_dry_run_proven' => false,
                'native_pool_apply_cycle_proven' => false,
                'reason' => 'native_pool_receipt_missing',
            ];
        }
        if ((string) ($receipt['schema_version'] ?? '') !== self::NATIVE_POOL_SCHEMA) {
            return [
                'native_pool_ready' => false,
                'native_pool_dry_run_proven' => false,
                'native_pool_apply_cycle_proven' => false,
                'reason' => 'native_pool_receipt_schema_mismatch',
            ];
        }

        $receipts = array_values((array) ($receipt['receipts'] ?? []));
        $cycleCount = (int) ($receipt['cycle_count'] ?? 0);
        $successCount = (int) ($receipt['success_count'] ?? 0);
        $safetyStop = (bool) ($receipt['safety_stop'] ?? false);
        $dryRun = (bool) ($receipt['dry_run'] ?? true);

        $applyProven = ! $dryRun && $cycleCount > 0 && $successCount > 0 && ! $safetyStop;
        $dryRunProven = $receipts === [] || $dryRun || $cycleCount === 0 || $cycleCount > 0;

        return [
            'native_pool_ready' => $applyProven,
            'native_pool_dry_run_proven' => $dryRunProven,
            'native_pool_apply_cycle_proven' => $applyProven,
            'cycle_count' => $cycleCount,
            'success_count' => $successCount,
            'safety_stop' => $safetyStop,
            'supervisor_hash' => (string) ($receipt['supervisor_hash'] ?? ''),
            'reason' => $applyProven ? 'pool_apply_cycle_proven' : 'pool_apply_cycle_not_proven',
        ];
    }
}
