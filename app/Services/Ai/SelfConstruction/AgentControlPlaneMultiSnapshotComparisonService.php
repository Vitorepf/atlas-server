<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\RecursivelyKsortsArrays;

/**
 * Compare N replay snapshots and emit timelines + trend analysis for
 * the Agent Control Plane certification observatory.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneMultiSnapshotComparisonService
{
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_multi_snapshot_comparison.v1';

    public const MODE = 'read_only_agent_control_plane_multi_snapshot_comparison';

    public function __construct(
        private readonly AgentControlPlaneReplaySnapshotStore $store,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function compare(array $options = []): array
    {
        $includeFreshReplay = (bool) ($options['include_fresh_replay'] ?? true);
        $maxSnapshots = isset($options['max_snapshots']) && is_int($options['max_snapshots']) && $options['max_snapshots'] > 0
            ? $options['max_snapshots']
            : null;

        $registry = $this->store->registry();
        $entries = (array) data_get($registry, 'entries', []);
        if ($maxSnapshots !== null && count($entries) > $maxSnapshots) {
            $entries = array_slice($entries, -$maxSnapshots);
        }

        $payloads = [];
        foreach ($entries as $entry) {
            $snapshotId = (string) data_get($entry, 'snapshot_id', '');
            if ($snapshotId === '') {
                continue;
            }
            $snapshot = $this->store->get($snapshotId);
            if ($snapshot === null) {
                continue;
            }
            $payloads[] = [
                'source' => 'snapshot',
                'snapshot_id' => $snapshotId,
                'created_at' => (string) data_get($snapshot, 'created_at', ''),
                'replay' => (array) data_get($snapshot, 'replay_payload', []),
            ];
        }
        if ($includeFreshReplay) {
            $fresh = $this->replay->replay();
            $payloads[] = [
                'source' => 'fresh_replay',
                'snapshot_id' => null,
                'created_at' => (string) data_get($fresh, 'generated_at', ''),
                'replay' => $fresh,
            ];
        }

        $count = count($payloads);
        $hashCounts = [];
        $pointerTimeline = [];
        $violationTimeline = [];
        $warningTimeline = [];
        $runtimeSafetyTimeline = [];
        $coverageTimeline = [];
        $regressionWindows = [];
        $improvementWindows = [];
        $previous = null;
        foreach ($payloads as $index => $payload) {
            $replay = $payload['replay'];
            $detHash = (string) data_get($replay, 'deterministic_replay_hash', '');
            $pointer = (string) data_get($replay, 'current_pointer', '');
            $violations = count((array) data_get($replay, 'violations', []));
            $warnings = count((array) data_get($replay, 'warnings', []));
            $runtimeSafe = (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false);
            $coverage = (int) data_get($replay, 'replayed_slice_count', 0);

            $hashCounts[$detHash] = ($hashCounts[$detHash] ?? 0) + 1;
            $pointerTimeline[] = [
                'index' => $index,
                'source' => $payload['source'],
                'snapshot_id' => $payload['snapshot_id'],
                'pointer' => $pointer,
            ];
            $violationTimeline[] = ['index' => $index, 'count' => $violations];
            $warningTimeline[] = ['index' => $index, 'count' => $warnings];
            $runtimeSafetyTimeline[] = ['index' => $index, 'all_false' => $runtimeSafe];
            $coverageTimeline[] = ['index' => $index, 'replayed_slice_count' => $coverage];

            if ($previous !== null) {
                $prevReplay = $previous['replay'];
                $prevViolations = count((array) data_get($prevReplay, 'violations', []));
                $prevWarnings = count((array) data_get($prevReplay, 'warnings', []));
                $prevRuntimeSafe = (bool) data_get($prevReplay, 'runtime_safety.runtime_safety_all_false', false);
                $prevCoverage = (int) data_get($prevReplay, 'replayed_slice_count', 0);
                if ($violations > $prevViolations || ($prevRuntimeSafe && ! $runtimeSafe) || $coverage < $prevCoverage) {
                    $regressionWindows[] = [
                        'from_index' => $index - 1,
                        'to_index' => $index,
                        'kind' => match (true) {
                            $violations > $prevViolations => 'violation_increase',
                            $prevRuntimeSafe && ! $runtimeSafe => 'runtime_safety_dropped',
                            $coverage < $prevCoverage => 'coverage_decrease',
                            default => 'unknown',
                        },
                        'before' => ['violations' => $prevViolations, 'coverage' => $prevCoverage, 'runtime_safe' => $prevRuntimeSafe],
                        'after' => ['violations' => $violations, 'coverage' => $coverage, 'runtime_safe' => $runtimeSafe],
                    ];
                }
                if (($coverage > $prevCoverage && $violations <= $prevViolations) || ($warnings < $prevWarnings)) {
                    $improvementWindows[] = [
                        'from_index' => $index - 1,
                        'to_index' => $index,
                        'kind' => match (true) {
                            $coverage > $prevCoverage => 'coverage_increase',
                            $warnings < $prevWarnings => 'warning_decrease',
                            default => 'unknown',
                        },
                        'before' => ['warnings' => $prevWarnings, 'coverage' => $prevCoverage],
                        'after' => ['warnings' => $warnings, 'coverage' => $coverage],
                    ];
                }
            }
            $previous = $payload;
        }

        $stableHashCount = 0;
        foreach ($hashCounts as $occurrences) {
            if ($occurrences > 1) {
                $stableHashCount += $occurrences;
            }
        }

        $trendStatus = match (true) {
            $count === 0 => 'no_snapshots',
            $count === 1 => 'single_point',
            $regressionWindows !== [] => 'regression_detected',
            $improvementWindows !== [] => 'improving',
            default => 'stable',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'comparison_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $count === 0 ? 'no_snapshots' : 'available',
            'trend_status' => $trendStatus,
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'snapshot_count' => $count,
            'stable_hash_count' => $stableHashCount,
            'unique_deterministic_hash_count' => count($hashCounts),
            'pointer_timeline' => $pointerTimeline,
            'violation_timeline' => $violationTimeline,
            'warning_timeline' => $warningTimeline,
            'runtime_safety_timeline' => $runtimeSafetyTimeline,
            'coverage_timeline' => $coverageTimeline,
            'regression_windows' => $regressionWindows,
            'improvement_windows' => $improvementWindows,
            'regression_window_count' => count($regressionWindows),
            'improvement_window_count' => count($improvementWindows),
            'options_applied' => [
                'include_fresh_replay' => $includeFreshReplay,
                'max_snapshots' => $maxSnapshots,
            ],
            'non_execution_guarantees' => [
                'multi_snapshot_does_not_start_codex',
                'multi_snapshot_does_not_call_codex_cli_or_app',
                'multi_snapshot_does_not_spawn_subprocess',
                'multi_snapshot_does_not_invoke_adapter',
                'multi_snapshot_does_not_execute_adapter',
                'multi_snapshot_does_not_call_provider',
                'multi_snapshot_does_not_dispatch_work',
                'multi_snapshot_does_not_spend_tokens',
                'multi_snapshot_does_not_enable_self_programming',
                'multi_snapshot_does_not_write_ledger',
                'multi_snapshot_does_not_mutate_pointer',
                'multi_snapshot_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($trendStatus) {
                'no_snapshots' => 'Multi-snapshot comparison has no snapshots to inspect.',
                'single_point' => 'Multi-snapshot comparison has a single point; no trend yet.',
                'regression_detected' => 'Multi-snapshot comparison detected at least one regression window.',
                'improving' => 'Multi-snapshot comparison detected improvement windows without regressions.',
                'stable' => 'Multi-snapshot comparison is stable across captured snapshots.',
                default => 'Multi-snapshot comparison status is unknown.',
            },
        ];

        $payload['trend_hash'] = $this->stableHash($this->normalizeForTrendHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForTrendHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['comparison_id'], $clone['generated_at'], $clone['trend_hash']);

        return $this->recursivelyKsort($clone);
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
