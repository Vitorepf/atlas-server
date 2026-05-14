<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Deterministic diff between two Agent Control Plane chain replays.
 *
 * Comparator inputs:
 *  - snapshot ids (resolved via AgentControlPlaneReplaySnapshotStore)
 *  - direct replay arrays
 *  - null => latest snapshot for `before`, fresh replay for `after`
 *
 * The diff is fully read-only: it never starts processes, never calls Codex
 * CLI/app, never spawns subprocesses, never invokes adapters, never
 * dispatches work, never spends tokens, never advances the next required
 * slice, never enables self-programming and never writes the ledger.
 */
class AgentControlPlaneReplayDiffService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_replay_diff.v1';

    public const MODE = 'read_only_agent_control_plane_replay_diff';

    public function __construct(
        private readonly AgentControlPlaneReplaySnapshotStore $store,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
    ) {}

    /**
     * @param  array<string, mixed>|string|null  $before
     * @param  array<string, mixed>|string|null  $after
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function diff(array|string|null $before = null, array|string|null $after = null, array $options = []): array
    {
        $beforeResolution = $this->resolveSide($before, 'before');
        $afterResolution = $this->resolveSide($after, 'after');

        $beforeReplay = $beforeResolution['replay'];
        $afterReplay = $afterResolution['replay'];

        $diffId = (string) Str::uuid();
        $generatedAt = CarbonImmutable::now()->toIso8601String();

        if ($beforeReplay === null) {
            $payload = $this->emptyDiffPayload('no_baseline', $diffId, $generatedAt, $beforeResolution, $afterResolution);
            $payload['diff_hash'] = $this->stableHash($this->normalizeForDiffHash($payload));

            return $payload;
        }

        if ($afterReplay === null) {
            $payload = $this->emptyDiffPayload('no_target', $diffId, $generatedAt, $beforeResolution, $afterResolution);
            $payload['diff_hash'] = $this->stableHash($this->normalizeForDiffHash($payload));

            return $payload;
        }

        $beforeSlices = (array) data_get($beforeReplay, 'replayed_slices', []);
        $afterSlices = (array) data_get($afterReplay, 'replayed_slices', []);
        $beforeSliceKeys = $this->sliceKeys($beforeSlices);
        $afterSliceKeys = $this->sliceKeys($afterSlices);

        $beforeEdges = (array) data_get($beforeReplay, 'replayed_edges', []);
        $afterEdges = (array) data_get($afterReplay, 'replayed_edges', []);

        $beforePointer = (string) data_get($beforeReplay, 'current_pointer', '');
        $afterPointer = (string) data_get($afterReplay, 'current_pointer', '');

        $beforeViolations = count((array) data_get($beforeReplay, 'violations', []));
        $afterViolations = count((array) data_get($afterReplay, 'violations', []));
        $beforeWarnings = count((array) data_get($beforeReplay, 'warnings', []));
        $afterWarnings = count((array) data_get($afterReplay, 'warnings', []));

        $beforeRuntimeSafety = (bool) data_get($beforeReplay, 'runtime_safety.runtime_safety_all_false', false);
        $afterRuntimeSafety = (bool) data_get($afterReplay, 'runtime_safety.runtime_safety_all_false', false);

        $beforeDeterministicHash = (string) data_get($beforeReplay, 'deterministic_replay_hash', '');
        $afterDeterministicHash = (string) data_get($afterReplay, 'deterministic_replay_hash', '');

        $beforeReplayHash = (string) data_get($beforeReplay, 'replay_hash', '');
        $afterReplayHash = (string) data_get($afterReplay, 'replay_hash', '');

        $beforeProofBundleHash = (string) data_get($beforeReplay, 'proof_bundle_hash', '');
        $afterProofBundleHash = (string) data_get($afterReplay, 'proof_bundle_hash', '');

        $addedSlices = array_values(array_diff($afterSliceKeys, $beforeSliceKeys));
        $removedSlices = array_values(array_diff($beforeSliceKeys, $afterSliceKeys));
        sort($addedSlices);
        sort($removedSlices);

        $beforeEdgeMap = $this->edgeMap($beforeEdges);
        $afterEdgeMap = $this->edgeMap($afterEdges);
        $addedEdgeKeys = array_values(array_diff(array_keys($afterEdgeMap), array_keys($beforeEdgeMap)));
        $removedEdgeKeys = array_values(array_diff(array_keys($beforeEdgeMap), array_keys($afterEdgeMap)));
        sort($addedEdgeKeys);
        sort($removedEdgeKeys);

        $changedEdges = [];
        foreach ($beforeEdgeMap as $key => $edge) {
            if (! isset($afterEdgeMap[$key])) {
                continue;
            }
            $beforeEdge = $edge;
            $afterEdge = $afterEdgeMap[$key];
            if ($this->stableHash($this->recursivelyKsort($beforeEdge))
                !== $this->stableHash($this->recursivelyKsort($afterEdge))) {
                $changedEdges[] = [
                    'from' => $key,
                    'before' => $beforeEdge,
                    'after' => $afterEdge,
                ];
            }
        }
        usort($changedEdges, static fn ($a, $b) => strcmp((string) $a['from'], (string) $b['from']));

        $capabilityChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'proof_bundle.capability_summary'),
            data_get($afterReplay, 'proof_bundle.capability_summary'),
        );
        $cliChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'proof_bundle.cli_summary'),
            data_get($afterReplay, 'proof_bundle.cli_summary'),
        );
        $readinessChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'proof_bundle.readiness_summary'),
            data_get($afterReplay, 'proof_bundle.readiness_summary'),
        );
        $invokerChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'proof_bundle.invoker_summary'),
            data_get($afterReplay, 'proof_bundle.invoker_summary'),
        );
        $docChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'proof_bundle.docs_summary'),
            data_get($afterReplay, 'proof_bundle.docs_summary'),
        );
        $matrixChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'proof_bundle.regression_matrix_summary'),
            data_get($afterReplay, 'proof_bundle.regression_matrix_summary'),
        );
        $cycleChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'cycle_integrity'),
            data_get($afterReplay, 'cycle_integrity'),
        );
        $terminalChanges = $this->summaryCountChange(
            data_get($beforeReplay, 'terminal_horizon_analysis'),
            data_get($afterReplay, 'terminal_horizon_analysis'),
        );

        $pointerChange = [
            'before' => $beforePointer,
            'after' => $afterPointer,
            'changed' => $beforePointer !== $afterPointer,
            'intentional_reentry' => (bool) data_get($afterReplay, 'cycle_integrity.intentional_reentry_detected', false),
        ];

        $regressions = [];
        $improvements = [];

        if ($afterViolations > $beforeViolations) {
            $regressions[] = [
                'kind' => 'violation_increase',
                'before' => $beforeViolations,
                'after' => $afterViolations,
                'delta' => $afterViolations - $beforeViolations,
            ];
        }
        if ($afterViolations < $beforeViolations) {
            $improvements[] = [
                'kind' => 'violation_decrease',
                'before' => $beforeViolations,
                'after' => $afterViolations,
                'delta' => $beforeViolations - $afterViolations,
            ];
        }
        if ($beforeRuntimeSafety && ! $afterRuntimeSafety) {
            $regressions[] = [
                'kind' => 'runtime_safety_dropped_from_all_false',
                'before' => true,
                'after' => false,
            ];
        }
        if (! $beforeRuntimeSafety && $afterRuntimeSafety) {
            $improvements[] = [
                'kind' => 'runtime_safety_restored_to_all_false',
                'before' => false,
                'after' => true,
            ];
        }
        $pointerChanged = $beforePointer !== $afterPointer;
        if ($pointerChanged) {
            $intentionalReentry = (bool) data_get($afterReplay, 'cycle_integrity.intentional_reentry_detected', false);
            $cycleRegressions = (array) data_get($afterReplay, 'cycle_integrity.regressions', []);
            $cycleOk = (bool) data_get($afterReplay, 'cycle_integrity.cycle_ok', false);
            $isRegressionPointer = $cycleRegressions !== [] || (! $cycleOk && ! $intentionalReentry);
            if ($isRegressionPointer) {
                $regressions[] = [
                    'kind' => 'pointer_regression',
                    'before' => $beforePointer,
                    'after' => $afterPointer,
                    'detail' => 'Cycle integrity reports pointer regression without intentional reentry justification.',
                ];
            } elseif (count($afterSliceKeys) > count($beforeSliceKeys) && $afterViolations <= $beforeViolations) {
                $improvements[] = [
                    'kind' => 'pointer_advanced_with_chain_growth',
                    'before' => $beforePointer,
                    'after' => $afterPointer,
                    'slice_count_delta' => count($afterSliceKeys) - count($beforeSliceKeys),
                ];
            }
        }
        if (count($afterSliceKeys) > count($beforeSliceKeys) && $afterViolations <= $beforeViolations) {
            $improvements[] = [
                'kind' => 'slice_count_increase',
                'before' => count($beforeSliceKeys),
                'after' => count($afterSliceKeys),
                'delta' => count($afterSliceKeys) - count($beforeSliceKeys),
            ];
        }
        if (count($afterSliceKeys) < count($beforeSliceKeys)) {
            $regressions[] = [
                'kind' => 'slice_count_decrease',
                'before' => count($beforeSliceKeys),
                'after' => count($afterSliceKeys),
                'delta' => count($beforeSliceKeys) - count($afterSliceKeys),
            ];
        }

        $changed = $beforeDeterministicHash !== $afterDeterministicHash;

        $status = match (true) {
            $regressions !== [] => 'regressed',
            ! $changed => 'unchanged',
            $afterWarnings > $beforeWarnings => 'changed_with_warnings',
            $improvements !== [] => 'improved',
            default => 'changed',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'diff_id' => $diffId,
            'generated_at' => $generatedAt,
            'read_only' => true,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'dispatch_allowed' => false,
            'self_programming_allowed' => false,
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'before_snapshot_id' => (string) ($beforeResolution['snapshot_id'] ?? ''),
            'after_snapshot_id' => (string) ($afterResolution['snapshot_id'] ?? ''),
            'before_source' => (string) $beforeResolution['source'],
            'after_source' => (string) $afterResolution['source'],
            'before_replay_hash' => $beforeReplayHash,
            'after_replay_hash' => $afterReplayHash,
            'before_deterministic_replay_hash' => $beforeDeterministicHash,
            'after_deterministic_replay_hash' => $afterDeterministicHash,
            'before_proof_bundle_hash' => $beforeProofBundleHash,
            'after_proof_bundle_hash' => $afterProofBundleHash,
            'changed' => $changed,
            'pointer_change' => $pointerChange,
            'slice_count_change' => [
                'before' => count($beforeSliceKeys),
                'after' => count($afterSliceKeys),
                'delta' => count($afterSliceKeys) - count($beforeSliceKeys),
            ],
            'edge_count_change' => [
                'before' => count($beforeEdgeMap),
                'after' => count($afterEdgeMap),
                'delta' => count($afterEdgeMap) - count($beforeEdgeMap),
            ],
            'violation_count_change' => [
                'before' => $beforeViolations,
                'after' => $afterViolations,
                'delta' => $afterViolations - $beforeViolations,
            ],
            'warning_count_change' => [
                'before' => $beforeWarnings,
                'after' => $afterWarnings,
                'delta' => $afterWarnings - $beforeWarnings,
            ],
            'runtime_safety_change' => [
                'before' => $beforeRuntimeSafety,
                'after' => $afterRuntimeSafety,
                'changed' => $beforeRuntimeSafety !== $afterRuntimeSafety,
            ],
            'proof_bundle_hash_change' => [
                'before' => $beforeProofBundleHash,
                'after' => $afterProofBundleHash,
                'changed' => $beforeProofBundleHash !== $afterProofBundleHash,
            ],
            'added_slices' => $addedSlices,
            'removed_slices' => $removedSlices,
            'added_edges' => $addedEdgeKeys,
            'removed_edges' => $removedEdgeKeys,
            'changed_edges' => $changedEdges,
            'capability_changes' => $capabilityChanges,
            'cli_changes' => $cliChanges,
            'readiness_changes' => $readinessChanges,
            'invoker_changes' => $invokerChanges,
            'doc_changes' => $docChanges,
            'matrix_changes' => $matrixChanges,
            'cycle_integrity_changes' => $cycleChanges,
            'terminal_horizon_changes' => $terminalChanges,
            'regressions' => $regressions,
            'regression_count' => count($regressions),
            'improvements' => $improvements,
            'improvement_count' => count($improvements),
            'non_execution_guarantees' => [
                'diff_does_not_start_codex',
                'diff_does_not_call_codex_cli_or_app',
                'diff_does_not_spawn_subprocess',
                'diff_does_not_invoke_adapter',
                'diff_does_not_execute_adapter',
                'diff_does_not_call_provider',
                'diff_does_not_dispatch_work',
                'diff_does_not_spend_tokens',
                'diff_does_not_enable_self_programming',
                'diff_does_not_write_ledger',
                'diff_does_not_mutate_pointer',
                'diff_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($status) {
                'no_baseline' => 'Replay diff is not available yet because no baseline snapshot is stored.',
                'no_target' => 'Replay diff is not available because the target replay could not be resolved.',
                'unchanged' => 'Replay diff is unchanged: deterministic hash matched between before and after.',
                'improved' => 'Replay diff is improved: chain grew (or violations dropped) without regressions.',
                'regressed' => 'Replay diff is regressed: violations increased, runtime safety dropped or pointer regressed without intentional reentry.',
                'changed_with_warnings' => 'Replay diff changed and warning count increased; review warnings before promotion.',
                default => 'Replay diff status is unknown.',
            },
        ];

        $payload['diff_hash'] = $this->stableHash($this->normalizeForDiffHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>|string|null  $value
     * @return array{replay: array<string, mixed>|null, source: string, snapshot_id: string|null}
     */
    private function resolveSide(array|string|null $value, string $side): array
    {
        if (is_array($value)) {
            return [
                'replay' => $value,
                'source' => 'array',
                'snapshot_id' => (string) data_get($value, 'replay_id', '') ?: null,
            ];
        }

        if (is_string($value) && $value !== '') {
            $snapshot = $this->store->get($value);
            if ($snapshot === null) {
                return [
                    'replay' => null,
                    'source' => 'snapshot_missing',
                    'snapshot_id' => $value,
                ];
            }

            return [
                'replay' => (array) data_get($snapshot, 'replay_payload', []),
                'source' => 'snapshot',
                'snapshot_id' => (string) data_get($snapshot, 'snapshot_id', $value),
            ];
        }

        if ($side === 'before') {
            $latest = $this->store->latest();
            if ($latest === null) {
                return ['replay' => null, 'source' => 'no_baseline', 'snapshot_id' => null];
            }

            return [
                'replay' => (array) data_get($latest, 'replay_payload', []),
                'source' => 'latest_snapshot',
                'snapshot_id' => (string) data_get($latest, 'snapshot_id', ''),
            ];
        }

        return [
            'replay' => $this->replay->replay(),
            'source' => 'fresh_replay',
            'snapshot_id' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $slices
     * @return array<int, string>
     */
    private function sliceKeys(array $slices): array
    {
        $keys = [];
        foreach ($slices as $slice) {
            $key = (string) data_get($slice, 'slice_key', '');
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<string, array<string, mixed>>
     */
    private function edgeMap(array $edges): array
    {
        $map = [];
        foreach ($edges as $edge) {
            $from = (string) data_get($edge, 'from', '');
            if ($from === '') {
                continue;
            }
            $map[$from] = $edge;
        }
        ksort($map);

        return $map;
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>, changed_keys: array<int, string>, added_keys: array<int, string>, removed_keys: array<int, string>, changed: bool}
     */
    private function summaryCountChange(mixed $before, mixed $after): array
    {
        $beforeArr = is_array($before) ? $before : [];
        $afterArr = is_array($after) ? $after : [];
        $beforeKeys = array_keys($beforeArr);
        $afterKeys = array_keys($afterArr);
        $added = array_values(array_diff($afterKeys, $beforeKeys));
        $removed = array_values(array_diff($beforeKeys, $afterKeys));
        $changedKeys = [];
        foreach (array_intersect($beforeKeys, $afterKeys) as $key) {
            $b = $beforeArr[$key];
            $a = $afterArr[$key];
            if (is_array($b) && is_array($a)) {
                if ($this->stableHash($this->recursivelyKsort($b))
                    !== $this->stableHash($this->recursivelyKsort($a))) {
                    $changedKeys[] = (string) $key;
                }
                continue;
            }
            if ($b !== $a) {
                $changedKeys[] = (string) $key;
            }
        }
        sort($added);
        sort($removed);
        sort($changedKeys);

        return [
            'before' => $beforeArr,
            'after' => $afterArr,
            'changed_keys' => $changedKeys,
            'added_keys' => $added,
            'removed_keys' => $removed,
            'changed' => $changedKeys !== [] || $added !== [] || $removed !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $resolutionBefore
     * @param  array<string, mixed>  $resolutionAfter
     * @return array<string, mixed>
     */
    private function emptyDiffPayload(string $status, string $diffId, string $generatedAt, array $resolutionBefore, array $resolutionAfter): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'diff_id' => $diffId,
            'generated_at' => $generatedAt,
            'read_only' => true,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'dispatch_allowed' => false,
            'self_programming_allowed' => false,
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'before_snapshot_id' => (string) ($resolutionBefore['snapshot_id'] ?? ''),
            'after_snapshot_id' => (string) ($resolutionAfter['snapshot_id'] ?? ''),
            'before_source' => (string) ($resolutionBefore['source'] ?? ''),
            'after_source' => (string) ($resolutionAfter['source'] ?? ''),
            'before_replay_hash' => '',
            'after_replay_hash' => '',
            'before_deterministic_replay_hash' => '',
            'after_deterministic_replay_hash' => '',
            'before_proof_bundle_hash' => '',
            'after_proof_bundle_hash' => '',
            'changed' => false,
            'pointer_change' => ['before' => '', 'after' => '', 'changed' => false, 'intentional_reentry' => false],
            'slice_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'edge_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'warning_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'runtime_safety_change' => ['before' => false, 'after' => false, 'changed' => false],
            'proof_bundle_hash_change' => ['before' => '', 'after' => '', 'changed' => false],
            'added_slices' => [],
            'removed_slices' => [],
            'added_edges' => [],
            'removed_edges' => [],
            'changed_edges' => [],
            'capability_changes' => $this->summaryCountChange([], []),
            'cli_changes' => $this->summaryCountChange([], []),
            'readiness_changes' => $this->summaryCountChange([], []),
            'invoker_changes' => $this->summaryCountChange([], []),
            'doc_changes' => $this->summaryCountChange([], []),
            'matrix_changes' => $this->summaryCountChange([], []),
            'cycle_integrity_changes' => $this->summaryCountChange([], []),
            'terminal_horizon_changes' => $this->summaryCountChange([], []),
            'regressions' => [],
            'regression_count' => 0,
            'improvements' => [],
            'improvement_count' => 0,
            'non_execution_guarantees' => [
                'diff_does_not_start_codex',
                'diff_does_not_call_codex_cli_or_app',
                'diff_does_not_spawn_subprocess',
                'diff_does_not_invoke_adapter',
                'diff_does_not_execute_adapter',
                'diff_does_not_call_provider',
                'diff_does_not_dispatch_work',
                'diff_does_not_spend_tokens',
                'diff_does_not_enable_self_programming',
                'diff_does_not_write_ledger',
                'diff_does_not_mutate_pointer',
                'diff_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($status) {
                'no_baseline' => 'Replay diff is not available yet because no baseline snapshot is stored.',
                'no_target' => 'Replay diff is not available because the target replay could not be resolved.',
                default => 'Replay diff status is unknown.',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForDiffHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['diff_hash'], $clone['diff_id'], $clone['generated_at']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
