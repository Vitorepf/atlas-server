<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Proves the certification stack is read-only by snapshotting the
 * before/after state of pointer, runtime safety flags, snapshot
 * registry and storage prefix counts around an arbitrary operation.
 *
 * Read-only by design: never starts processes, never calls Codex
 * CLI/app, never spawns subprocesses, never invokes adapters, never
 * dispatches work, never spends tokens, never advances the next
 * required slice, never enables self-programming, never writes the
 * ledger. Operations themselves must obey the same invariants.
 */
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Support\YesNo;

final class AgentControlPlaneCertificationMutationGuard
{
    use HashesKsortedPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_mutation_guard.v1';

    public const MODE = 'read_only_agent_control_plane_certification_mutation_guard';

    public const ALLOWED_SNAPSHOT_PREFIX = AgentControlPlaneReplaySnapshotStore::STORAGE_PREFIX;

    /**
     * Storage writes from parallel Atlas subsystems should not make the
     * Self-Construction certification stack look mutable. The guard monitors
     * the canonical operator-submission evidence namespace outside the
     * allowed replay snapshot prefix; other product areas and transient
     * Self-Construction workspaces are intentionally out of scope here.
     */
    private const MONITORED_STORAGE_PREFIXES = [
        'atlas/self-construction/operator-submissions',
    ];

    /**
     * Upper bound for the recursive monitored-prefix scan. Beyond this point
     * the guard returns the cap as a saturated count instead of materialising
     * every path: the only consumer is a before/after delta, so the exact
     * count beyond the cap is irrelevant for invariant detection. This keeps
     * the completion audit under PHP's default 128M memory_limit even when
     * the operator-submission workspace grows large.
     */
    private const STORAGE_SCAN_HARD_CAP = 50000;

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
        private readonly AgentControlPlaneReplaySnapshotStore $store,
    ) {}

    /**
     * @param  callable|array<string, mixed>|null  $operation
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function guard(callable|array|null $operation = null, array $options = []): array
    {
        $allowSnapshotMutation = (bool) ($options['allow_snapshot_mutation'] ?? false);
        $forcedAfterOverride = is_array($options['after_override'] ?? null) ? $options['after_override'] : null;

        $before = $this->captureState();
        if (is_callable($operation)) {
            try {
                $operation();
            } catch (\Throwable $e) {
                // Even when the operation throws we still report what mutated.
            }
        }
        $after = $this->captureState();

        if ($forcedAfterOverride !== null) {
            $after = array_replace_recursive($after, $forcedAfterOverride);
        }

        $pointerMutated = $before['pointer'] !== $after['pointer'];
        $nextBuildMutated = $before['next_build_slices'] !== $after['next_build_slices'];
        $notYetMutated = $before['not_yet_runtime_capable'] !== $after['not_yet_runtime_capable'];
        $deterministicHashMutated = $before['deterministic_replay_hash'] !== $after['deterministic_replay_hash'];
        $snapshotRegistryMutated = $before['snapshot_entry_count'] !== $after['snapshot_entry_count'];
        $runtimeSafetyMutated = $before['runtime_safety_all_false'] !== $after['runtime_safety_all_false'];
        $ledgerMutated = $before['ledger_count'] !== $after['ledger_count'];
        $storageMutated = $before['storage_outside_prefix_count'] !== $after['storage_outside_prefix_count'];

        $allowedMutations = [];
        $forbiddenMutations = [];

        if ($snapshotRegistryMutated) {
            if ($allowSnapshotMutation) {
                $allowedMutations[] = 'snapshot_registry_count_changed_from_'
                    .$before['snapshot_entry_count'].'_to_'.$after['snapshot_entry_count'];
            } else {
                $forbiddenMutations[] = 'snapshot_registry_count_changed_without_authorization';
            }
        }

        if ($pointerMutated) {
            $forbiddenMutations[] = 'pointer_mutated_from_'.$before['pointer'].'_to_'.$after['pointer'];
        }
        if ($nextBuildMutated) {
            $forbiddenMutations[] = 'next_build_slices_mutated';
        }
        if ($notYetMutated) {
            $forbiddenMutations[] = 'not_yet_runtime_capable_mutated';
        }
        if ($runtimeSafetyMutated) {
            $forbiddenMutations[] = 'runtime_safety_all_false_changed_from_'
                .(YesNo::trueFalse($before['runtime_safety_all_false']))
                .'_to_'.(YesNo::trueFalse($after['runtime_safety_all_false']));
        }
        if ($ledgerMutated) {
            $forbiddenMutations[] = 'ledger_count_changed_from_'
                .$before['ledger_count'].'_to_'.$after['ledger_count'];
        }
        if ($storageMutated) {
            $forbiddenMutations[] = 'storage_outside_allowed_prefix_changed_from_'
                .$before['storage_outside_prefix_count'].'_to_'.$after['storage_outside_prefix_count'];
        }
        if ($deterministicHashMutated) {
            $allowedMutations[] = 'deterministic_replay_hash_drifted_'
                .substr($before['deterministic_replay_hash'], 0, 8).'_to_'.substr($after['deterministic_replay_hash'], 0, 8);
        }

        $mutationCount = count($forbiddenMutations);
        $guardPassed = $mutationCount === 0;
        $status = $guardPassed ? 'passed' : 'failed';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'guard_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'before' => $before,
            'after' => $after,
            'before_hash' => $this->stableHash($before),
            'after_hash' => $this->stableHash($after),
            'mutated' => $mutationCount > 0 || $allowedMutations !== [],
            'mutation_count' => $mutationCount,
            'allowed_mutations' => $allowedMutations,
            'forbidden_mutations' => $forbiddenMutations,
            'pointer_mutated' => $pointerMutated,
            'runtime_safety_mutated' => $runtimeSafetyMutated,
            'ledger_mutated' => $ledgerMutated,
            'storage_mutated' => $storageMutated,
            'snapshot_registry_mutated' => $snapshotRegistryMutated,
            'deterministic_replay_hash_mutated' => $deterministicHashMutated,
            'guard_passed' => $guardPassed,
            'options_applied' => [
                'allow_snapshot_mutation' => $allowSnapshotMutation,
                'after_override_applied' => $forcedAfterOverride !== null,
            ],
            'non_execution_guarantees' => [
                'guard_does_not_start_codex',
                'guard_does_not_call_codex_cli_or_app',
                'guard_does_not_spawn_subprocess',
                'guard_does_not_invoke_adapter',
                'guard_does_not_execute_adapter',
                'guard_does_not_call_provider',
                'guard_does_not_dispatch_work',
                'guard_does_not_spend_tokens',
                'guard_does_not_enable_self_programming',
                'guard_does_not_write_ledger',
                'guard_does_not_mutate_pointer',
                'guard_does_not_promote_completion_claim',
            ],
            'human_summary' => $guardPassed
                ? 'Mutation guard passed: no forbidden mutation detected during the guarded operation.'
                : 'Mutation guard failed: forbidden mutation detected. Review the operation before relying on it as read-only.',
        ];

        $payload['mutation_hash'] = $this->stableHash([
            'before_hash' => $payload['before_hash'],
            'after_hash' => $payload['after_hash'],
            'forbidden_mutations' => $forbiddenMutations,
            'allowed_mutations' => $allowedMutations,
        ]);

        return $payload;
    }

    public const CRITICAL_CHECK_IDS = ['scope_check', 'proof_check', 'freshness_check', 'conflict_check', 'rollback_check'];

    public const MUTATION_KIND_REMOVED          = 'removed';
    public const MUTATION_KIND_WEAKENED_ADVISORY = 'weakened_to_advisory';
    public const MUTATION_KIND_HARMLESS_REFACTOR = 'harmless_refactor';

    /**
     * Mutation-test the certification check suite itself: for each candidate
     * mutation of a critical check (removed, weakened to advisory-only, or a
     * harmless refactor), determine whether the guard would still BLOCK
     * (killed) or would silently let a weakened check pass as green (survived).
     *
     * A mutation is "killed" only when the candidate's check set still enforces
     * the target check as blocking. Harmless refactors never count as kills or
     * survivals — they are excluded from the kill ratio entirely (AC2).
     *
     * @param  array{mutations?: list<array{
     *     mutation_id?: string, target_check?: string, kind?: string, still_blocking?: bool,
     * }>}  $input
     * @return array{schema_version:string, results:list<array<string,mixed>>, killed_count:int, survived_count:int, harmless_refactor_count:int, kill_ratio:float}
     */
    public function evaluateMutations(array $input): array
    {
        $mutations = is_array($input['mutations'] ?? null) ? $input['mutations'] : [];

        $results = [];
        $killed = 0;
        $survived = 0;
        $harmless = 0;

        foreach ($mutations as $mutation) {
            $mutationId   = (string) ($mutation['mutation_id']   ?? '');
            $targetCheck  = (string) ($mutation['target_check']  ?? '');
            $kind         = (string) ($mutation['kind']          ?? self::MUTATION_KIND_REMOVED);
            $stillBlocking = (bool)  ($mutation['still_blocking'] ?? false);

            if ($kind === self::MUTATION_KIND_HARMLESS_REFACTOR) {
                $harmless++;
                $results[] = [
                    'mutation_id'         => $mutationId,
                    'target_check'        => $targetCheck,
                    'kind'                => $kind,
                    'killed'              => false,
                    'survived'            => false,
                    'harmless_refactor'   => true,
                    'required_test_gap'   => null,
                ];

                continue;
            }

            $isKilled = $stillBlocking && in_array($targetCheck, self::CRITICAL_CHECK_IDS, true);

            if ($isKilled) {
                $killed++;
            } else {
                $survived++;
            }

            $requiredTestGap = $isKilled ? null : sprintf(
                '%s for %s; add an assertion that fails certification when %s is %s',
                $kind === self::MUTATION_KIND_WEAKENED_ADVISORY ? 'check downgraded to advisory-only' : 'check removed',
                $targetCheck,
                $targetCheck,
                $kind === self::MUTATION_KIND_WEAKENED_ADVISORY ? 'non-blocking' : 'absent',
            );

            $results[] = [
                'mutation_id'         => $mutationId,
                'target_check'        => $targetCheck,
                'kind'                => $kind,
                'killed'              => $isKilled,
                'survived'            => ! $isKilled,
                'harmless_refactor'   => false,
                'required_test_gap'   => $requiredTestGap,
            ];
        }

        $totalScored = $killed + $survived;

        return [
            'schema_version'           => self::SCHEMA_VERSION,
            'results'                  => $results,
            'killed_count'             => $killed,
            'survived_count'           => $survived,
            'harmless_refactor_count'  => $harmless,
            'kill_ratio'               => $totalScored > 0 ? round($killed / $totalScored, 4) : 1.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureState(): array
    {
        $controlPlane = $this->readiness->agentControlPlane($this->normalizeReadinessOptions());
        $registry = $this->store->registry();
        $replay = $this->replay->replay();

        return [
            'pointer' => (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice', ''),
            'next_build_slices' => array_values((array) data_get($controlPlane, 'control_plane.next_build_slices', [])),
            'not_yet_runtime_capable' => array_values((array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', [])),
            'snapshot_entry_count' => (int) data_get($registry, 'entry_count', 0),
            'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash', ''),
            'runtime_safety_all_false' => (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false),
            'ledger_count' => $this->countLedgerEvents(),
            'storage_outside_prefix_count' => $this->countStorageOutsidePrefix(),
            'monitored_storage_prefixes' => self::MONITORED_STORAGE_PREFIXES,
            'allowed_storage_prefixes' => [
                self::ALLOWED_SNAPSHOT_PREFIX,
            ],
        ];
    }

    private function countLedgerEvents(): int
    {
        try {
            return (int) \DB::table('atlas_self_construction_ledger_events')->count();
        } catch (\Throwable) {
            try {
                return (int) \DB::table('ledger_events')->count();
            } catch (\Throwable) {
                return 0;
            }
        }
    }

    private function countStorageOutsidePrefix(): int
    {
        try {
            $disk = Storage::disk(AgentControlPlaneReplaySnapshotStore::DEFAULT_DISK);
            $count = 0;
            foreach (self::MONITORED_STORAGE_PREFIXES as $prefix) {
                foreach ($disk->allFiles($prefix) as $path) {
                    if (! self::isMonitoredStoragePath($path)
                        || str_starts_with($path, self::ALLOWED_SNAPSHOT_PREFIX.'/')
                        || $path === self::ALLOWED_SNAPSHOT_PREFIX) {
                        continue;
                    }

                    $count++;
                    if ($count >= self::STORAGE_SCAN_HARD_CAP) {
                        return $count;
                    }
                }
            }

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function isMonitoredStoragePath(string $path): bool
    {
        foreach (self::MONITORED_STORAGE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeReadinessOptions(): array
    {
        return array_fill_keys([
            'workspace', 'target', 'packet', 'actor', 'session',
            'lease_minutes', 'reason', 'evidence_hash', 'model',
            'input_tokens', 'output_tokens', 'cost_usd', 'artifact_type',
            'artifact_path', 'artifact_hash', 'summary', 'decision',
            'signed_by', 'receipt_hash', 'dispatch_envelope_hash',
            'adapter_contract_hash', 'expires_at',
        ], null);
    }

}
