<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartLivenessMonitor
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    private const ALLOWED_STATES = ['alive', 'silent', 'stale', 'orphaned'];

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordPostStartLiveness(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', self::LEDGER_TABLE] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException($table.'_missing');
            }
        }

        return DB::transaction(function () use ($normalized): array {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $run->metadata;
            $existingMonitorId = (string) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.post_start_liveness_monitor_id', '');

            if ($existingMonitorId !== '') {
                if ($existingMonitorId !== $normalized['post_start_liveness_monitor_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_liveness_monitor_already_recorded');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertPostStartEvidenceReceipt($run, $metadata, $normalized);

            $metadata['codex_real_invoker_post_start_liveness_monitor'] = [
                'post_start_liveness_monitor_id' => $normalized['post_start_liveness_monitor_id'],
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'operator_start_handoff_id' => $normalized['operator_start_handoff_id'],
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'observed_liveness_state' => $normalized['observed_liveness_state'],
                'liveness_observation_hash' => $normalized['liveness_observation_hash'],
                'heartbeat_observation_hash' => $normalized['heartbeat_observation_hash'],
                'progress_observation_hash' => $normalized['progress_observation_hash'],
                'operator_visibility_attestation_hash' => $normalized['operator_visibility_attestation_hash'],
                'no_provider_call_attestation_hash' => $normalized['no_provider_call_attestation_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_liveness_recorded_pending_dispatch_release',
                'post_start_liveness_recorded' => true,
                'liveness_source' => 'external_operator_observation',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'dispatch_allowed' => false,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'liveness' => $normalized['observed_liveness_state'],
                'summary' => 'Codex real invoker post-start liveness recorded from external observation; dispatch remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_post_start_liveness.recorded',
                'post_start_liveness_monitor_id' => $normalized['post_start_liveness_monitor_id'],
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'observed_liveness_state' => $normalized['observed_liveness_state'],
                'liveness_observation_hash' => $normalized['liveness_observation_hash'],
                'heartbeat_observation_hash' => $normalized['heartbeat_observation_hash'],
                'progress_observation_hash' => $normalized['progress_observation_hash'],
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_post_start_liveness',
                'receipt_id' => $normalized['post_start_liveness_monitor_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-post-start-liveness.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            $run->refresh();

            return $this->result($run, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    public const LIVENESS_PRODUCTIVE  = 'productive';
    public const LIVENESS_IDLE        = 'idle';
    public const LIVENESS_STALE       = 'stale';
    public const LIVENESS_STUCK       = 'stuck';
    public const LIVENESS_FAKE_ALIVE  = 'fake_alive';

    public const RECOMMENDATION_CONTINUE   = 'continue';
    public const RECOMMENDATION_WAIT       = 'wait';
    public const RECOMMENDATION_RECOVER    = 'recover';
    public const RECOMMENDATION_QUARANTINE = 'quarantine';

    /** Minutes since last heartbeat beyond which a process is considered stale. */
    private const STALE_HEARTBEAT_MINUTES = 10;

    /** Minutes with no progress signal (but recent heartbeat) before "stuck". */
    private const STUCK_NO_PROGRESS_MINUTES = 20;

    /**
     * Pure liveness assessment: an alive process or fresh heartbeat alone does
     * NOT mean liveness — it must be accompanied by actual task progress
     * evidence (receipts, file changes, output growth). Never mutates run
     * state, never writes the ledger.
     *
     * @param  array{
     *   process_alive?: bool,
     *   minutes_since_heartbeat?: float,
     *   minutes_since_last_progress?: ?float,
     *   has_progress_evidence?: bool,
     *   receipt_count?: int,
     *   stuck_failure_streak?: int,
     * }  $input
     * @return array{liveness_status:string, progress_signal:string, recommendation:string, recovery_hint:?string}
     */
    public function assessLiveness(array $input): array
    {
        $processAlive = (bool) ($input['process_alive'] ?? false);
        $minutesSinceHeartbeat = (float) ($input['minutes_since_heartbeat'] ?? 0.0);
        $minutesSinceProgress = isset($input['minutes_since_last_progress']) ? (float) $input['minutes_since_last_progress'] : null;
        $hasProgressEvidence = (bool) ($input['has_progress_evidence'] ?? false);
        $receiptCount = max(0, (int) ($input['receipt_count'] ?? 0));
        $stuckFailureStreak = max(0, (int) ($input['stuck_failure_streak'] ?? 0));

        $heartbeatFresh = $minutesSinceHeartbeat < self::STALE_HEARTBEAT_MINUTES;

        // fake_alive: heartbeat looks healthy but there is zero progress evidence at all
        // (no receipts ever) — the process is "alive" but never actually did task work.
        if ($processAlive && $heartbeatFresh && ! $hasProgressEvidence && $receiptCount === 0) {
            return [
                'liveness_status'  => self::LIVENESS_FAKE_ALIVE,
                'progress_signal'  => 'no_progress_evidence_despite_fresh_heartbeat',
                'recommendation'   => self::RECOMMENDATION_QUARANTINE,
                'recovery_hint'    => 'process reports alive but has never produced a progress receipt; quarantine and require a real start receipt before retry',
            ];
        }

        if (! $processAlive || ! $heartbeatFresh) {
            return [
                'liveness_status'  => self::LIVENESS_STALE,
                'progress_signal'  => 'heartbeat_stale_or_process_not_alive',
                'recommendation'   => self::RECOMMENDATION_RECOVER,
                'recovery_hint'    => 'restart the process or reclaim the lease; heartbeat is stale or process is not alive',
            ];
        }

        if ($minutesSinceProgress !== null && $minutesSinceProgress >= self::STUCK_NO_PROGRESS_MINUTES) {
            return [
                'liveness_status'  => self::LIVENESS_STUCK,
                'progress_signal'  => sprintf('no_progress_for_%d_minutes', (int) $minutesSinceProgress),
                'recommendation'   => $stuckFailureStreak >= 2 ? self::RECOMMENDATION_QUARANTINE : self::RECOMMENDATION_RECOVER,
                'recovery_hint'    => $stuckFailureStreak >= 2
                    ? 'repeated stuck episodes for this worker/task; quarantine pending review'
                    : 'process alive and heartbeat fresh but no progress in a long time; consider restarting the work item',
            ];
        }

        if ($hasProgressEvidence) {
            return [
                'liveness_status'  => self::LIVENESS_PRODUCTIVE,
                'progress_signal'  => 'recent_progress_evidence_present',
                'recommendation'   => self::RECOMMENDATION_CONTINUE,
                'recovery_hint'    => null,
            ];
        }

        return [
            'liveness_status'  => self::LIVENESS_IDLE,
            'progress_signal'  => 'alive_and_fresh_heartbeat_but_no_recent_progress_evidence_yet',
            'recommendation'   => self::RECOMMENDATION_WAIT,
            'recovery_hint'    => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'run_key',
            'codex_execution_id',
            'manual_start_executor_receipt_id',
            'operator_start_handoff_id',
            'post_start_receipt_contract_id',
            'post_start_evidence_acceptance_bridge_id',
            'post_start_evidence_receipt_id',
            'post_start_liveness_monitor_id',
            'observed_liveness_state',
            'liveness_observation_hash',
            'heartbeat_observation_hash',
            'progress_observation_hash',
            'operator_visibility_attestation_hash',
            'no_provider_call_attestation_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $state = strtolower(trim((string) $input['observed_liveness_state']));
        if (! in_array($state, self::ALLOWED_STATES, true)) {
            throw new InvalidArgumentException('invalid_observed_liveness_state');
        }

        $hashFields = [
            'liveness_observation_hash',
            'heartbeat_observation_hash',
            'progress_observation_hash',
            'operator_visibility_attestation_hash',
            'no_provider_call_attestation_hash',
        ];

        $hashes = [];
        foreach ($hashFields as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));
            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        return array_merge([
            'run_key' => (string) $input['run_key'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'manual_start_executor_receipt_id' => (string) $input['manual_start_executor_receipt_id'],
            'operator_start_handoff_id' => (string) $input['operator_start_handoff_id'],
            'post_start_receipt_contract_id' => (string) $input['post_start_receipt_contract_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'post_start_evidence_receipt_id' => (string) $input['post_start_evidence_receipt_id'],
            'post_start_liveness_monitor_id' => (string) $input['post_start_liveness_monitor_id'],
            'observed_liveness_state' => $state,
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertPostStartEvidenceReceipt(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_evidence_receipt.post_start_evidence_receipt_id') !== $normalized['post_start_evidence_receipt_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_receipt_missing_or_mismatch');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_evidence_acceptance_bridge_id') !== $normalized['post_start_evidence_acceptance_bridge_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_acceptance_bridge_missing_or_mismatch');
        }

        foreach (['post_start_receipt_contract_id', 'operator_start_handoff_id', 'manual_start_executor_receipt_id', 'codex_execution_id'] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_evidence_receipt.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }

            if ((string) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException('post_start_evidence_acceptance_bridge_'.$field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_evidence_receipt_id') !== $normalized['post_start_evidence_receipt_id']) {
            throw new InvalidArgumentException('post_start_evidence_acceptance_bridge_post_start_evidence_receipt_id_mismatch');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.status') !== 'post_start_evidence_acceptance_recorded_pending_liveness_monitoring') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_acceptance_bridge_not_ready_for_liveness');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_evidence_acceptance_recorded', false)) {
            throw new InvalidArgumentException('post_start_evidence_acceptance_not_recorded');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_evidence_receipt.status') !== 'post_start_evidence_receipt_recorded_pending_liveness_monitoring') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_receipt_not_ready_for_liveness');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_evidence_receipt.post_start_evidence_receipt_recorded', false)) {
            throw new InvalidArgumentException('post_start_evidence_receipt_not_recorded');
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_post_start_evidence_receipt.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }

            if ((bool) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.'.$field, false)) {
                throw new InvalidArgumentException('post_start_evidence_acceptance_bridge_'.$field.'_already_true');
            }
        }

        foreach (['external_process_started', 'provider_started'] as $field) {
            if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_evidence_receipt.'.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }

            if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.'.$field, false)) {
                throw new InvalidArgumentException('post_start_evidence_acceptance_bridge_'.$field.'_not_true');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_liveness_recorded',
            'idempotent' => $idempotent,
            'post_start_liveness_monitor_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_liveness_monitor.post_start_liveness_monitor_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_liveness_monitor.post_start_evidence_acceptance_bridge_id'),
            'post_start_evidence_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_liveness_monitor.post_start_evidence_receipt_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_liveness_monitor.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'observed_liveness_state' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_liveness_monitor.observed_liveness_state'),
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'token_spend_allowed' => false,
            'provider_started' => true,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
