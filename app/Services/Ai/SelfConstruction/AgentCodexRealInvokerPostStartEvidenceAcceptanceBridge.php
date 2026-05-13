<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge
{
    /**
     * @var list<string>
     */
    private const CHAIN_FIELDS = [
        'codex_execution_id',
        'real_invoker_process_starter_readiness_gate_id',
        'real_invoker_start_execution_gate_id',
        'manual_start_executor_receipt_id',
        'operator_start_handoff_id',
    ];

    /**
     * @var list<string>
     */
    private const HANDOFF_HASH_FIELDS = [
        'handoff_packet_hash',
        'operator_runbook_hash',
        'external_terminal_handoff_hash',
        'post_start_liveness_probe_contract_hash',
        'post_start_receipt_contract_hash',
        'failure_escalation_contract_hash',
    ];

    /**
     * @var list<string>
     */
    private const CONTRACT_HASH_FIELDS = [
        'external_process_identity_contract_hash',
        'startup_evidence_contract_hash',
        'terminal_pid_capture_contract_hash',
        'post_start_cost_meter_contract_hash',
    ];

    /**
     * @var list<string>
     */
    private const EVIDENCE_HASH_FIELDS = [
        'external_process_identity_evidence_hash',
        'startup_evidence_hash',
        'terminal_pid_capture_hash',
        'post_start_liveness_probe_hash',
        'post_start_cost_meter_evidence_hash',
        'operator_external_start_attestation_hash',
        'no_atlas_process_spawn_attestation_hash',
    ];

    public function __construct(
        private readonly AgentCodexRealInvokerPostStartReceiptContractBuilder $receiptContractBuilder,
        private readonly AgentCodexRealInvokerPostStartEvidenceReceiptWriter $evidenceReceiptWriter,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function acceptPostStartEvidence(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_ledger_events'] as $table) {
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
            $existingBridgeId = (string) data_get($metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_evidence_acceptance_bridge_id', '');

            if ($existingBridgeId !== '') {
                if ($existingBridgeId !== $normalized['post_start_evidence_acceptance_bridge_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_acceptance_bridge_already_recorded');
                }

                return $this->result($run, idempotent: true, contractResult: null, evidenceResult: null);
            }

            $this->assertPostStartOperatorHandoff($run, $metadata, $normalized);

            $contractResult = $this->receiptContractBuilder->buildPostStartReceiptContract(
                $this->receiptContractInput($normalized)
            );

            if ((string) data_get($contractResult, 'status') !== 'codex_real_invoker_post_start_receipt_contract_built') {
                throw new InvalidArgumentException('codex_real_invoker_post_start_receipt_contract_not_built');
            }

            $evidenceResult = $this->evidenceReceiptWriter->writePostStartEvidenceReceipt(
                $this->evidenceReceiptInput($normalized)
            );

            if ((string) data_get($evidenceResult, 'status') !== 'codex_real_invoker_post_start_evidence_receipt_recorded') {
                throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_receipt_not_recorded');
            }

            foreach (['actual_process_start_allowed', 'token_spend_allowed', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($evidenceResult, $field, false)) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_evidence_receipt_'.$field.'_unexpectedly_true');
                }
            }

            $run->refresh();
            $metadata = (array) $run->metadata;
            $metadata['codex_real_invoker_post_start_evidence_acceptance_bridge'] = array_merge(
                Arr::only($normalized, array_merge(
                    [
                        'post_start_evidence_acceptance_bridge_id',
                        'post_start_operator_start_handoff_id',
                        'post_start_receipt_contract_id',
                        'post_start_evidence_receipt_id',
                    ],
                    self::CHAIN_FIELDS,
                    self::HANDOFF_HASH_FIELDS,
                    self::CONTRACT_HASH_FIELDS,
                    self::EVIDENCE_HASH_FIELDS,
                )),
                [
                    'provider' => 'codex',
                    'adapter' => 'codex',
                    'status' => 'post_start_evidence_acceptance_recorded_pending_liveness_monitoring',
                    'post_start_evidence_acceptance_recorded' => true,
                    'post_start_receipt_contract_built' => true,
                    'post_start_evidence_receipt_recorded' => true,
                    'operator_external_start_attested' => true,
                    'atlas_process_spawned' => false,
                    'actual_process_start_allowed' => false,
                    'external_process_started' => true,
                    'token_spend_allowed' => false,
                    'provider_started' => true,
                    'dispatch_allowed' => false,
                    'codex_real_invoker_post_start_receipt_contract_result' => $contractResult,
                    'codex_real_invoker_post_start_evidence_receipt_result' => $evidenceResult,
                    'recorded_by' => $normalized['actor'],
                    'recorded_session' => $normalized['session'],
                    'reason' => $normalized['reason'],
                ],
            );

            $run->forceFill([
                'summary' => 'Codex real invoker post-start evidence accepted; liveness monitoring remains separate and dispatch disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_post_start_evidence_acceptance.recorded',
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'post_start_operator_start_handoff_id' => $normalized['post_start_operator_start_handoff_id'],
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'post_start_evidence_receipt_id' => $normalized['post_start_evidence_receipt_id'],
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
                'next_required_stage' => 'post_start_liveness_monitor',
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_post_start_evidence_acceptance',
                'receipt_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-post-start-evidence-acceptance.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            $run->refresh();

            return $this->result($run, idempotent: false, contractResult: $contractResult, evidenceResult: $evidenceResult);
        });
    }

    /**
     * @param  array<string,string>  $normalized
     * @return array<string,string>
     */
    private function receiptContractInput(array $normalized): array
    {
        return Arr::only($normalized, array_merge(
            ['run_key', 'post_start_evidence_acceptance_bridge_id', 'post_start_receipt_contract_id'],
            self::CHAIN_FIELDS,
            self::HANDOFF_HASH_FIELDS,
            self::CONTRACT_HASH_FIELDS,
            ['actor', 'session', 'reason'],
        ));
    }

    /**
     * @param  array<string,string>  $normalized
     * @return array<string,string>
     */
    private function evidenceReceiptInput(array $normalized): array
    {
        return Arr::only($normalized, array_merge(
            ['run_key', 'post_start_evidence_acceptance_bridge_id', 'post_start_receipt_contract_id', 'post_start_evidence_receipt_id'],
            self::CHAIN_FIELDS,
            self::CONTRACT_HASH_FIELDS,
            self::EVIDENCE_HASH_FIELDS,
            ['actor', 'session', 'reason'],
        ));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function normalize(array $input): array
    {
        $required = array_merge(
            [
                'run_key',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_operator_start_handoff_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_receipt_id',
            ],
            self::CHAIN_FIELDS,
            self::HANDOFF_HASH_FIELDS,
            self::CONTRACT_HASH_FIELDS,
            self::EVIDENCE_HASH_FIELDS,
            ['actor', 'session', 'reason'],
        );

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = strtolower(trim((string) $input[$field]));
        }

        foreach (array_merge(self::HANDOFF_HASH_FIELDS, self::CONTRACT_HASH_FIELDS, self::EVIDENCE_HASH_FIELDS) as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/', $normalized[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        foreach (array_diff($required, array_merge(self::HANDOFF_HASH_FIELDS, self::CONTRACT_HASH_FIELDS, self::EVIDENCE_HASH_FIELDS)) as $field) {
            $normalized[$field] = (string) $input[$field];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,string>  $normalized
     */
    private function assertPostStartOperatorHandoff(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_operator_start_handoff.';

        if ((string) data_get($metadata, $prefix.'post_start_operator_start_handoff_id') !== $normalized['post_start_operator_start_handoff_id']) {
            throw new InvalidArgumentException('post_start_operator_start_handoff_id_mismatch');
        }

        foreach (array_merge(self::CHAIN_FIELDS, self::HANDOFF_HASH_FIELDS) as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_operator_start_handoff_ready_pending_post_start_receipt_contract') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_operator_start_handoff_not_ready_for_evidence_acceptance');
        }

        foreach (['post_start_operator_start_handoff_built', 'operator_start_handoff_built', 'manual_operator_start_required', 'observed_external_process_started', 'observed_provider_started'] as $field) {
            if (! (bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_process_call_allowed', 'provider_started', 'adapter_invocation_allowed', 'adapter_execution_allowed', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $contractResult
     * @param  array<string,mixed>|null  $evidenceResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $contractResult, ?array $evidenceResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_evidence_acceptance_recorded',
            'idempotent' => $idempotent,
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_evidence_acceptance_bridge_id'),
            'post_start_operator_start_handoff_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_operator_start_handoff_id'),
            'post_start_receipt_contract_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_receipt_contract_id'),
            'post_start_evidence_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.post_start_evidence_receipt_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'post_start_receipt_contract_built' => true,
            'post_start_evidence_receipt_recorded' => true,
            'operator_external_start_attested' => true,
            'atlas_process_spawned' => false,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'token_spend_allowed' => false,
            'provider_started' => true,
            'dispatch_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_result' => $contractResult,
            'codex_real_invoker_post_start_evidence_receipt_result' => $evidenceResult,
        ];
    }
}
