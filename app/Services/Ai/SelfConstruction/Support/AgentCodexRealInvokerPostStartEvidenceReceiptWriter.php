<?php

namespace App\Services\Ai\SelfConstruction\Support;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use function hash;
use function json_encode;

class AgentCodexRealInvokerPostStartEvidenceReceiptWriter
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function writePostStartEvidenceReceipt(array $input): array
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
            $existingReceiptId = (string) data_get($metadata, 'codex_real_invoker_post_start_evidence_receipt.post_start_evidence_receipt_id', '');

            if ($existingReceiptId !== '') {
                if ($existingReceiptId !== $normalized['post_start_evidence_receipt_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_receipt_already_written');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertPostStartReceiptContract($run, $metadata, $normalized);

            $proofReceipt = $this->buildProofReceipt($run, $normalized);

            $metadata['codex_real_invoker_post_start_evidence_receipt'] = [
                'receipt_id' => $proofReceipt['receipt_id'],
                'receipt_digest' => $proofReceipt['receipt_digest'],
                'task_id' => $proofReceipt['task_id'],
                'lease_id' => $proofReceipt['lease_id'],
                'proof_command' => $proofReceipt['proof_command'],
                'evidence_status' => $proofReceipt['evidence_status'],
                'outcome_class' => $proofReceipt['outcome_class'],
                'learning_payload' => $proofReceipt['learning_payload'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'operator_start_handoff_id' => $normalized['operator_start_handoff_id'],
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'real_invoker_process_starter_readiness_gate_id' => $normalized['real_invoker_process_starter_readiness_gate_id'],
                'real_invoker_start_execution_gate_id' => $normalized['real_invoker_start_execution_gate_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'external_process_identity_contract_hash' => $normalized['external_process_identity_contract_hash'],
                'startup_evidence_contract_hash' => $normalized['startup_evidence_contract_hash'],
                'terminal_pid_capture_contract_hash' => $normalized['terminal_pid_capture_contract_hash'],
                'post_start_cost_meter_contract_hash' => $normalized['post_start_cost_meter_contract_hash'],
                'external_process_identity_evidence_hash' => $normalized['external_process_identity_evidence_hash'],
                'startup_evidence_hash' => $normalized['startup_evidence_hash'],
                'terminal_pid_capture_hash' => $normalized['terminal_pid_capture_hash'],
                'post_start_liveness_probe_hash' => $normalized['post_start_liveness_probe_hash'],
                'post_start_cost_meter_evidence_hash' => $normalized['post_start_cost_meter_evidence_hash'],
                'operator_external_start_attestation_hash' => $normalized['operator_external_start_attestation_hash'],
                'no_atlas_process_spawn_attestation_hash' => $normalized['no_atlas_process_spawn_attestation_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_evidence_receipt_recorded_pending_liveness_monitoring',
                'post_start_evidence_receipt_recorded' => true,
                'operator_external_start_attested' => true,
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
                'summary' => 'Codex real invoker post-start evidence receipt recorded; dispatch remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_post_start_evidence_receipt.recorded',
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'operator_start_handoff_id' => $normalized['operator_start_handoff_id'],
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'external_process_identity_evidence_hash' => $normalized['external_process_identity_evidence_hash'],
                'startup_evidence_hash' => $normalized['startup_evidence_hash'],
                'terminal_pid_capture_hash' => $normalized['terminal_pid_capture_hash'],
                'post_start_liveness_probe_hash' => $normalized['post_start_liveness_probe_hash'],
                'post_start_cost_meter_evidence_hash' => $normalized['post_start_cost_meter_evidence_hash'],
                'operator_external_start_attested' => true,
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_post_start_evidence_receipt',
                'receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-post-start-evidence-receipt.v1',
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
            'real_invoker_process_starter_readiness_gate_id',
            'real_invoker_start_execution_gate_id',
            'manual_start_executor_receipt_id',
            'operator_start_handoff_id',
            'post_start_receipt_contract_id',
            'post_start_evidence_receipt_id',
            'external_process_identity_contract_hash',
            'startup_evidence_contract_hash',
            'terminal_pid_capture_contract_hash',
            'post_start_cost_meter_contract_hash',
            'external_process_identity_evidence_hash',
            'startup_evidence_hash',
            'terminal_pid_capture_hash',
            'post_start_liveness_probe_hash',
            'post_start_cost_meter_evidence_hash',
            'operator_external_start_attestation_hash',
            'no_atlas_process_spawn_attestation_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach (['actor', 'session', 'reason'] as $structuredField) {
            if (! preg_match('/^[a-z0-9_\-:]{1,120}$/', (string) $input[$structuredField])) {
                throw new InvalidArgumentException('unstructured_or_provider_private_'.$structuredField);
            }
        }

        $hashFields = [
            'external_process_identity_contract_hash',
            'startup_evidence_contract_hash',
            'terminal_pid_capture_contract_hash',
            'post_start_cost_meter_contract_hash',
            'external_process_identity_evidence_hash',
            'startup_evidence_hash',
            'terminal_pid_capture_hash',
            'post_start_liveness_probe_hash',
            'post_start_cost_meter_evidence_hash',
            'operator_external_start_attestation_hash',
            'no_atlas_process_spawn_attestation_hash',
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
            'real_invoker_process_starter_readiness_gate_id' => (string) $input['real_invoker_process_starter_readiness_gate_id'],
            'real_invoker_start_execution_gate_id' => (string) $input['real_invoker_start_execution_gate_id'],
            'manual_start_executor_receipt_id' => (string) $input['manual_start_executor_receipt_id'],
            'operator_start_handoff_id' => (string) $input['operator_start_handoff_id'],
            'post_start_receipt_contract_id' => (string) $input['post_start_receipt_contract_id'],
            'post_start_evidence_receipt_id' => (string) $input['post_start_evidence_receipt_id'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertPostStartReceiptContract(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_receipt_contract.post_start_receipt_contract_id') !== $normalized['post_start_receipt_contract_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_receipt_contract_missing_or_mismatch');
        }

        foreach ([
            'operator_start_handoff_id',
            'manual_start_executor_receipt_id',
            'real_invoker_process_starter_readiness_gate_id',
            'real_invoker_start_execution_gate_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_receipt_contract.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_receipt_contract.status') !== 'post_start_receipt_contract_ready_pending_external_start_evidence') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_receipt_contract_not_ready_for_evidence');
        }

        foreach ([
            'external_process_identity_contract_hash',
            'startup_evidence_contract_hash',
            'terminal_pid_capture_contract_hash',
            'post_start_cost_meter_contract_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_receipt_contract.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_receipt_contract.post_start_receipt_contract_built', false)) {
            throw new InvalidArgumentException('post_start_receipt_contract_not_built');
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_post_start_receipt_contract.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array<string,mixed>
     */
    private function buildProofReceipt(AtlasSelfConstructionAgentRun $run, array $normalized): array
    {
        $taskId = (string) $run->packet_id;
        $leaseId = (string) ($run->reservation_id ?? '');
        $proofCommand = 'operator_external_start_attestation_no_atlas_spawn';
        $evidenceStatus = 'accepted';
        $outcomeClass = 'external_process_started_dispatch_disabled';

        $receiptDigest = hash('sha256', (string) json_encode([
            'receipt_id' => $normalized['post_start_evidence_receipt_id'],
            'task_id' => $taskId,
            'lease_id' => $leaseId,
            'proof_command' => $proofCommand,
            'evidence_status' => $evidenceStatus,
            'outcome_class' => $outcomeClass,
            'codex_execution_id' => $normalized['codex_execution_id'],
            'run_key' => $run->run_key,
        ]));

        return [
            'receipt_id' => $normalized['post_start_evidence_receipt_id'],
            'receipt_digest' => $receiptDigest,
            'task_id' => $taskId,
            'lease_id' => $leaseId,
            'proof_command' => $proofCommand,
            'evidence_status' => $evidenceStatus,
            'outcome_class' => $outcomeClass,
            'learning_payload' => [
                'receipt_id' => $normalized['post_start_evidence_receipt_id'],
                'receipt_digest' => $receiptDigest,
                'task_id' => $taskId,
                'lease_id' => $leaseId,
                'proof_command' => $proofCommand,
                'evidence_status' => $evidenceStatus,
                'outcome_class' => $outcomeClass,
                'provider' => 'codex',
                'codex_execution_id' => $normalized['codex_execution_id'],
                'run_key' => $run->run_key,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_evidence_receipt_recorded',
            'idempotent' => $idempotent,
            'receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.receipt_id'),
            'receipt_digest' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.receipt_digest'),
            'task_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.task_id'),
            'lease_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.lease_id'),
            'proof_command' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.proof_command'),
            'evidence_status' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.evidence_status'),
            'outcome_class' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.outcome_class'),
            'learning_payload' => (array) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.learning_payload', []),
            'post_start_evidence_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.post_start_evidence_receipt_id'),
            'post_start_receipt_contract_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.post_start_receipt_contract_id'),
            'operator_start_handoff_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.operator_start_handoff_id'),
            'manual_start_executor_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.manual_start_executor_receipt_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.adapter'),
            'post_start_evidence_receipt_recorded' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'token_spend_allowed' => false,
            'provider_started' => true,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
