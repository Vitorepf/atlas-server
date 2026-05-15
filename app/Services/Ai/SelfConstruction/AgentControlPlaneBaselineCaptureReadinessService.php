<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Builds a read-only plan for recording the replay snapshot that promotion and
 * release-dossier layers need as their baseline. This service deliberately
 * does not persist the snapshot; AgentControlPlaneReplaySnapshotStore::put()
 * remains the explicit write boundary.
 */
final class AgentControlPlaneBaselineCaptureReadinessService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_baseline_capture_readiness.v1';

    public const MODE = 'read_only_agent_control_plane_baseline_capture_readiness';

    public function __construct(
        private readonly AgentControlPlaneReplaySnapshotStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $replay
     * @param  array<string, mixed>  $diff
     * @param  array<string, mixed>  $gate
     * @return array<string, mixed>
     */
    public function assess(array $baseline, array $replay, array $diff, array $gate): array
    {
        $registry = $this->store->registry();
        $latestSnapshot = $this->store->latest();
        $currentReplayHash = (string) data_get($replay, 'deterministic_replay_hash', '');
        $latestReplayHash = (string) data_get($latestSnapshot, 'deterministic_replay_hash', '');

        $blockers = [];
        $warnings = [];

        $baselineStatus = (string) data_get($baseline, 'status', 'unknown');
        if ($baselineStatus === 'blocked' || $baselineStatus === '') {
            $blockers[] = 'certification_baseline_status_is_'.$baselineStatus;
        } elseif ($baselineStatus !== 'available') {
            $warnings[] = 'certification_baseline_status_is_'.$baselineStatus;
        }
        $replayStatus = (string) data_get($replay, 'status', 'unknown');
        if ($replayStatus === 'blocked' || $replayStatus === '') {
            $blockers[] = 'deterministic_replay_status_is_'.$replayStatus;
        } elseif ($replayStatus !== 'available') {
            $warnings[] = 'deterministic_replay_status_is_'.$replayStatus;
        }
        if (! (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false)) {
            $blockers[] = 'runtime_safety_not_all_false';
        }
        if (count((array) data_get($replay, 'violations', [])) > 0) {
            $warnings[] = 'deterministic_replay_has_violations';
        }
        if ((bool) data_get($registry, 'corrupt', false)) {
            $blockers[] = 'snapshot_registry_corrupt';
        }

        $snapshotState = match (true) {
            $latestSnapshot === null => 'missing',
            $latestReplayHash === $currentReplayHash && $currentReplayHash !== '' => 'current',
            default => 'stale',
        };

        if ($snapshotState === 'missing') {
            $warnings[] = 'baseline_snapshot_missing';
        } elseif ($snapshotState === 'stale') {
            $warnings[] = 'baseline_snapshot_stale';
        }

        $canCapture = $blockers === [];
        $snapshotCaptureRequired = $snapshotState !== 'current';

        $status = match (true) {
            $blockers !== [] => 'blocked',
            $snapshotState === 'current' => 'current_snapshot_present',
            $snapshotState === 'stale' => 'ready_to_refresh_snapshot',
            default => 'ready_to_capture_snapshot',
        };

        $capturePlan = [
            'write_boundary' => AgentControlPlaneReplaySnapshotStore::class.'::put',
            'recommended_label' => 'completion-baseline-'.$this->fingerprint((string) data_get($baseline, 'baseline_hash', '')),
            'recommended_keep' => AgentControlPlaneReplaySnapshotStore::DEFAULT_KEEP,
            'expected_snapshot_schema_version' => AgentControlPlaneReplaySnapshotStore::SCHEMA_VERSION,
            'expected_snapshot_storage_prefix' => AgentControlPlaneReplaySnapshotStore::STORAGE_PREFIX,
            'requires_explicit_operator_action' => true,
            'automatic_capture_allowed' => false,
            'runtime_write_allowed' => false,
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'assessed_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'provider_call_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'baseline_ready' => ! in_array($baselineStatus, ['blocked', ''], true),
            'replay_ready' => ! in_array($replayStatus, ['blocked', ''], true),
            'can_capture_snapshot' => $canCapture,
            'snapshot_capture_required' => $snapshotCaptureRequired,
            'snapshot_state' => $snapshotState,
            'latest_snapshot_id' => (string) data_get($latestSnapshot, 'snapshot_id', ''),
            'latest_snapshot_hash' => $latestReplayHash,
            'current_deterministic_replay_hash' => $currentReplayHash,
            'baseline_hash' => (string) data_get($baseline, 'baseline_hash', ''),
            'baseline_fingerprint' => (string) data_get($baseline, 'baseline_fingerprint', ''),
            'diff_status' => (string) data_get($diff, 'status', ''),
            'diff_hash' => (string) data_get($diff, 'diff_hash', ''),
            'promotion_gate_status' => (string) data_get($gate, 'status', ''),
            'promotion_gate_hash' => (string) data_get($gate, 'gate_hash', ''),
            'registry_entry_count' => (int) data_get($registry, 'entry_count', 0),
            'blockers' => array_values(array_unique($blockers)),
            'blocker_count' => count(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'warning_count' => count(array_unique($warnings)),
            'capture_plan' => $capturePlan,
            'next_action' => match ($status) {
                'blocked' => 'resolve_baseline_capture_blockers_before_recording_snapshot',
                'current_snapshot_present' => 'use_current_snapshot_for_replay_diff_and_promotion_gate',
                'ready_to_refresh_snapshot' => 'record_explicit_replay_snapshot_to_refresh_completion_baseline',
                default => 'record_explicit_replay_snapshot_before_evaluating_completion_promotion',
            },
            'non_execution_guarantees' => [
                'baseline_capture_readiness_does_not_start_codex',
                'baseline_capture_readiness_does_not_call_codex_cli_or_app',
                'baseline_capture_readiness_does_not_spawn_subprocess',
                'baseline_capture_readiness_does_not_invoke_adapter',
                'baseline_capture_readiness_does_not_execute_adapter',
                'baseline_capture_readiness_does_not_call_provider',
                'baseline_capture_readiness_does_not_dispatch_work',
                'baseline_capture_readiness_does_not_spend_tokens',
                'baseline_capture_readiness_does_not_write_snapshot',
                'baseline_capture_readiness_does_not_write_ledger',
                'baseline_capture_readiness_does_not_mutate_pointer',
                'baseline_capture_readiness_does_not_promote_completion_claim',
            ],
        ];
        $payload['baseline_capture_readiness_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function fingerprint(string $hash): string
    {
        return $hash === '' ? 'pending' : substr($hash, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        unset(
            $payload['assessed_at'],
            $payload['baseline_capture_readiness_hash'],
            // The gate hash includes the gate's volatile id/timestamp. The
            // stable readiness fingerprint keeps the gate status and next
            // action, but strips the volatile aggregate hash.
            $payload['promotion_gate_hash'],
        );

        return hash('sha256', (string) json_encode($this->recursivelyKsort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
}
