<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartDispatchReleaseGate
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preparePostStartDispatchRelease(array $input): array
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
            $existingGateId = (string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.dispatch_release_gate_id', '');

            if ($existingGateId !== '') {
                if ($existingGateId !== $normalized['dispatch_release_gate_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_release_gate_already_ready');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertPostStartLivenessMonitor($run, $metadata, $normalized);

            $metadata['codex_real_invoker_post_start_dispatch_release_gate'] = [
                'dispatch_release_gate_id' => $normalized['dispatch_release_gate_id'],
                'post_start_liveness_monitor_id' => $normalized['post_start_liveness_monitor_id'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'operator_start_handoff_id' => $normalized['operator_start_handoff_id'],
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'dispatch_scope_hash' => $normalized['dispatch_scope_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'signed_dispatch_policy_hash' => $normalized['signed_dispatch_policy_hash'],
                'no_direct_provider_call_attestation_hash' => $normalized['no_direct_provider_call_attestation_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_dispatch_release_gate_ready_pending_signed_dispatch_authorization',
                'dispatch_release_gate_ready' => true,
                'future_dispatch_release_candidate' => true,
                'liveness_source' => 'external_operator_observation',
                'observed_liveness_state' => 'alive',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex real invoker post-start dispatch release gate prepared; dispatch remains disabled pending signed authorization.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_post_start_dispatch_release_gate.ready',
                'dispatch_release_gate_id' => $normalized['dispatch_release_gate_id'],
                'post_start_liveness_monitor_id' => $normalized['post_start_liveness_monitor_id'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'dispatch_scope_hash' => $normalized['dispatch_scope_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'signed_dispatch_policy_hash' => $normalized['signed_dispatch_policy_hash'],
                'observed_liveness_state' => 'alive',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_post_start_dispatch_release_gate',
                'receipt_id' => $normalized['dispatch_release_gate_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-post-start-dispatch-release-gate.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            $run->refresh();

            return $this->result($run, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
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
            'post_start_evidence_receipt_id',
            'post_start_liveness_monitor_id',
            'dispatch_release_gate_id',
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'signed_dispatch_policy_hash',
            'no_direct_provider_call_attestation_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashFields = [
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'signed_dispatch_policy_hash',
            'no_direct_provider_call_attestation_hash',
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
            'post_start_evidence_receipt_id' => (string) $input['post_start_evidence_receipt_id'],
            'post_start_liveness_monitor_id' => (string) $input['post_start_liveness_monitor_id'],
            'dispatch_release_gate_id' => (string) $input['dispatch_release_gate_id'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertPostStartLivenessMonitor(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.post_start_liveness_monitor_id') !== $normalized['post_start_liveness_monitor_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_liveness_monitor_missing_or_mismatch');
        }

        foreach (['post_start_evidence_receipt_id', 'post_start_receipt_contract_id', 'operator_start_handoff_id', 'manual_start_executor_receipt_id', 'codex_execution_id'] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.status') !== 'post_start_liveness_recorded_pending_dispatch_release') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_liveness_not_ready_for_dispatch_release');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.post_start_liveness_recorded', false)) {
            throw new InvalidArgumentException('post_start_liveness_not_recorded');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.observed_liveness_state') !== 'alive') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_liveness_not_alive');
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'dispatch_allowed', 'provider_process_call_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['external_process_started', 'provider_started'] as $field) {
            if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_liveness_monitor.'.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_dispatch_release_gate_ready',
            'idempotent' => $idempotent,
            'dispatch_release_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_release_gate.dispatch_release_gate_id'),
            'post_start_liveness_monitor_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_release_gate.post_start_liveness_monitor_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_release_gate.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'dispatch_release_gate_ready' => true,
            'future_dispatch_release_candidate' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'token_spend_allowed' => false,
            'provider_started' => true,
            'provider_process_call_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
