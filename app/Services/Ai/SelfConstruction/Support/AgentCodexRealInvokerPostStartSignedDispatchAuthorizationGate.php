<?php

namespace App\Services\Ai\SelfConstruction\Support;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use function hash;

class AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizePostStartSignedDispatch(array $input): array
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
            $existingAuthorizationId = (string) data_get($metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.signed_dispatch_authorization_id', '');

            if ($existingAuthorizationId !== '') {
                if ($existingAuthorizationId !== $normalized['signed_dispatch_authorization_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_signed_dispatch_authorization_already_recorded');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertDispatchReleaseGate($run, $metadata, $normalized);

            $signatureCheck = $this->validateSignatureBinding($run, $normalized);

            if (! $signatureCheck['valid']) {
                return $this->rejectedResult($run, $signatureCheck);
            }

            $metadata['codex_real_invoker_post_start_signed_dispatch_authorization'] = [
                'signature_digest' => $signatureCheck['digest'],
                'signed_dispatch_authorization_id' => $normalized['signed_dispatch_authorization_id'],
                'dispatch_release_gate_id' => $normalized['dispatch_release_gate_id'],
                'post_start_liveness_monitor_id' => $normalized['post_start_liveness_monitor_id'],
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'operator_start_handoff_id' => $normalized['operator_start_handoff_id'],
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'human_dispatch_signature_hash' => $normalized['human_dispatch_signature_hash'],
                'signed_dispatch_policy_hash' => $normalized['signed_dispatch_policy_hash'],
                'dispatch_window_hash' => $normalized['dispatch_window_hash'],
                'dispatch_scope_hash' => $normalized['dispatch_scope_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'dispatch_replay_guard_hash' => $normalized['dispatch_replay_guard_hash'],
                'dispatch_kill_switch_hash' => $normalized['dispatch_kill_switch_hash'],
                'no_direct_provider_call_attestation_hash' => $normalized['no_direct_provider_call_attestation_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_signed_dispatch_authorized_pending_dispatch_executor',
                'signed_dispatch_authorization_recorded' => true,
                'future_dispatch_authorized' => true,
                'dispatch_release_gate_ready' => true,
                'observed_liveness_state' => 'alive',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
                'authorized_by' => $normalized['actor'],
                'authorized_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex real invoker post-start signed dispatch authorization recorded; dispatch remains disabled pending executor handoff.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_post_start_signed_dispatch_authorization.recorded',
                'signed_dispatch_authorization_id' => $normalized['signed_dispatch_authorization_id'],
                'dispatch_release_gate_id' => $normalized['dispatch_release_gate_id'],
                'post_start_liveness_monitor_id' => $normalized['post_start_liveness_monitor_id'],
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'human_dispatch_signature_hash' => $normalized['human_dispatch_signature_hash'],
                'signed_dispatch_policy_hash' => $normalized['signed_dispatch_policy_hash'],
                'dispatch_window_hash' => $normalized['dispatch_window_hash'],
                'dispatch_scope_hash' => $normalized['dispatch_scope_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'future_dispatch_authorized' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_post_start_signed_dispatch_authorization',
                'receipt_id' => $normalized['signed_dispatch_authorization_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-post-start-signed-dispatch-authorization-gate.v1',
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
            'post_start_evidence_acceptance_bridge_id',
            'post_start_liveness_monitor_id',
            'dispatch_release_gate_id',
            'signed_dispatch_authorization_id',
            'signed_dispatch_receipt_hash',
            'human_dispatch_signature_hash',
            'signed_dispatch_policy_hash',
            'dispatch_window_hash',
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'dispatch_replay_guard_hash',
            'dispatch_kill_switch_hash',
            'no_direct_provider_call_attestation_hash',
            'task_id',
            'lease_id',
            'worker_id',
            'allowed_scope_hash',
            'signature_issued_at',
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
            'signed_dispatch_receipt_hash',
            'human_dispatch_signature_hash',
            'signed_dispatch_policy_hash',
            'dispatch_window_hash',
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'dispatch_replay_guard_hash',
            'dispatch_kill_switch_hash',
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
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'post_start_liveness_monitor_id' => (string) $input['post_start_liveness_monitor_id'],
            'dispatch_release_gate_id' => (string) $input['dispatch_release_gate_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'task_id' => (string) $input['task_id'],
            'lease_id' => (string) $input['lease_id'],
            'worker_id' => (string) $input['worker_id'],
            'allowed_scope_hash' => strtolower(trim((string) $input['allowed_scope_hash'])),
            'signature_issued_at' => (string) $input['signature_issued_at'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertDispatchReleaseGate(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.dispatch_release_gate_id') !== $normalized['dispatch_release_gate_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_release_gate_missing_or_mismatch');
        }

        foreach ([
            'post_start_liveness_monitor_id',
            'post_start_evidence_acceptance_bridge_id',
            'post_start_evidence_receipt_id',
            'post_start_receipt_contract_id',
            'operator_start_handoff_id',
            'manual_start_executor_receipt_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.status') !== 'post_start_dispatch_release_gate_ready_pending_signed_dispatch_authorization') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_release_gate_not_ready_for_signed_authorization');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.dispatch_release_gate_ready', false)) {
            throw new InvalidArgumentException('dispatch_release_gate_not_ready');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.observed_liveness_state') !== 'alive') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_liveness_not_alive');
        }

        foreach (['dispatch_scope_hash', 'continuation_summary_hash', 'context_pack_hash', 'signed_dispatch_policy_hash', 'no_direct_provider_call_attestation_hash'] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'dispatch_allowed', 'provider_process_call_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['external_process_started', 'provider_started'] as $field) {
            if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_release_gate.'.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array{valid: bool, reason: ?string, digest: string}
     */
    private function validateSignatureBinding(AtlasSelfConstructionAgentRun $run, array $normalized): array
    {
        $digest = hash('sha256', implode('|', [
            $normalized['task_id'],
            $normalized['lease_id'],
            $normalized['worker_id'],
            $normalized['allowed_scope_hash'],
            $normalized['human_dispatch_signature_hash'],
        ]));

        if ($normalized['task_id'] !== (string) $run->packet_id) {
            return ['valid' => false, 'reason' => 'signature_task_id_mismatch', 'digest' => $digest];
        }

        if ($normalized['lease_id'] !== (string) $run->reservation_id) {
            return ['valid' => false, 'reason' => 'signature_lease_id_mismatch', 'digest' => $digest];
        }

        if ($normalized['worker_id'] !== (string) $run->actor) {
            return ['valid' => false, 'reason' => 'signature_worker_id_mismatch', 'digest' => $digest];
        }

        if ($normalized['allowed_scope_hash'] !== (string) $run->allowed_files_hash) {
            return ['valid' => false, 'reason' => 'signature_allowed_scope_mismatch', 'digest' => $digest];
        }

        try {
            $issuedAt = CarbonImmutable::parse($normalized['signature_issued_at']);
        } catch (\Throwable $e) {
            return ['valid' => false, 'reason' => 'signature_parse_failure', 'digest' => $digest];
        }

        if (abs(CarbonImmutable::now()->diffInSeconds($issuedAt)) > 900) {
            return ['valid' => false, 'reason' => 'signature_stale', 'digest' => $digest];
        }

        $reused = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', '!=', $run->run_key)
            ->where('metadata->codex_real_invoker_post_start_signed_dispatch_authorization->human_dispatch_signature_hash', $normalized['human_dispatch_signature_hash'])
            ->exists();

        if ($reused) {
            return ['valid' => false, 'reason' => 'signature_reused_across_leases', 'digest' => $digest];
        }

        return ['valid' => true, 'reason' => null, 'digest' => $digest];
    }

    /**
     * @param  array{valid: bool, reason: ?string, digest: string}  $signatureCheck
     * @return array<string,mixed>
     */
    private function rejectedResult(AtlasSelfConstructionAgentRun $run, array $signatureCheck): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_signed_dispatch_authorization_rejected',
            'idempotent' => false,
            'authorization_valid' => false,
            'rejection_reason' => $signatureCheck['reason'],
            'signature_digest' => $signatureCheck['digest'],
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'signed_dispatch_authorization_recorded' => false,
            'future_dispatch_authorized' => false,
            'actual_process_start_allowed' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'dispatch_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_signed_dispatch_authorization_recorded',
            'idempotent' => $idempotent,
            'authorization_valid' => true,
            'rejection_reason' => null,
            'signature_digest' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.signature_digest'),
            'signed_dispatch_authorization_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.signed_dispatch_authorization_id'),
            'dispatch_release_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.dispatch_release_gate_id'),
            'post_start_liveness_monitor_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.post_start_liveness_monitor_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.post_start_evidence_acceptance_bridge_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'signed_dispatch_authorization_recorded' => true,
            'future_dispatch_authorized' => true,
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
