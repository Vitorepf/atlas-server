<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter
{
    /**
     * @var list<string>
     */
    private const CHAIN_FIELDS = [
        'codex_execution_id',
        'real_invoker_executor_plan_id',
        'real_invoker_executor_fresh_release_id',
        'real_invoker_executor_enablement_id',
        'real_invoker_supervised_start_activation_id',
        'real_invoker_guarded_process_start_id',
        'real_invoker_final_process_start_authorization_id',
        'real_invoker_actual_process_start_rehearsal_id',
        'real_invoker_process_start_envelope_id',
        'real_invoker_start_execution_gate_id',
        'real_invoker_process_starter_readiness_gate_id',
    ];

    /**
     * @var list<string>
     */
    private const PROCESS_STARTER_HASH_FIELDS = [
        'process_starter_manifest_hash',
        'supervisor_binding_hash',
        'liveness_monitor_binding_hash',
        'cancellation_contract_hash',
        'output_capture_contract_hash',
        'cost_meter_contract_hash',
        'start_replay_guard_hash',
        'operator_process_starter_signature_hash',
    ];

    /**
     * @var list<string>
     */
    private const MANUAL_RECEIPT_HASH_FIELDS = [
        'manual_start_command_hash',
        'terminal_session_binding_hash',
        'operator_presence_hash',
        'live_supervisor_ack_hash',
        'initial_liveness_probe_hash',
        'kill_switch_ack_hash',
        'output_stream_capture_hash',
        'cost_meter_initial_hash',
        'no_autostart_attestation_hash',
    ];

    public function __construct(
        private readonly AgentCodexRealInvokerManualStartExecutorReceiptWriter $manualStartExecutorReceiptWriter,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function writePostStartManualStartExecutorReceipt(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_ledger_events'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException($table.'_missing');
            }
        }

        return DB::transaction(function () use ($normalized): array {
            $observedRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $observedRun instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $observedRun->metadata;
            $existingReceiptId = (string) data_get($metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt.post_start_manual_start_executor_receipt_id', '');

            if ($existingReceiptId !== '') {
                if ($existingReceiptId !== $normalized['post_start_manual_start_executor_receipt_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_manual_start_executor_receipt_already_written');
                }

                return $this->result($observedRun, idempotent: true, manualReceiptResult: null);
            }

            $this->assertPostStartProcessStarterReady($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $manualReceiptResult = $this->manualStartExecutorReceiptWriter->writeManualStartExecutorReceipt($this->baseManualReceiptInput($normalized, $providerStartRunKey));

            if ((string) data_get($manualReceiptResult, 'status') !== 'codex_real_invoker_manual_start_executor_receipt_written') {
                throw new InvalidArgumentException('codex_real_invoker_manual_start_executor_receipt_not_written');
            }

            foreach (['manual_start_executor_receipt_written', 'manual_operator_start_required'] as $field) {
                if (! (bool) data_get($manualReceiptResult, $field, false)) {
                    throw new InvalidArgumentException('codex_real_invoker_manual_start_executor_receipt_'.$field.'_missing');
                }
            }

            foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($manualReceiptResult, $field, false)) {
                    throw new InvalidArgumentException('codex_real_invoker_manual_start_executor_receipt_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_manual_start_executor_receipt'] = array_merge(
                Arr::only($normalized, array_merge(
                    ['post_start_manual_start_executor_receipt_id', 'manual_start_executor_receipt_id', 'post_start_process_starter_readiness_gate_id', 'provider_start_attempt_id'],
                    self::CHAIN_FIELDS,
                    self::PROCESS_STARTER_HASH_FIELDS,
                    self::MANUAL_RECEIPT_HASH_FIELDS,
                )),
                [
                    'provider_start_run_key' => $providerStartRunKey,
                    'provider' => 'codex',
                    'adapter' => 'codex',
                    'status' => 'post_start_manual_start_executor_receipt_written_pending_operator_handoff',
                    'post_start_manual_start_executor_receipt_written' => true,
                    'manual_start_executor_receipt_written' => true,
                    'manual_operator_start_required' => true,
                    'process_starter_ready' => true,
                    'observed_external_process_started' => true,
                    'observed_provider_started' => true,
                    'actual_process_start_allowed' => false,
                    'external_process_started' => false,
                    'token_spend_allowed' => false,
                    'provider_process_call_allowed' => false,
                    'provider_started' => false,
                    'adapter_invocation_allowed' => false,
                    'adapter_execution_allowed' => false,
                    'dispatch_allowed' => false,
                    'codex_real_invoker_manual_start_executor_receipt_result' => $manualReceiptResult,
                    'recorded_by' => $normalized['actor'],
                    'recorded_session' => $normalized['session'],
                    'reason' => $normalized['reason'],
                ],
            );

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start manual start executor receipt written; operator handoff still controls external start.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, manualReceiptResult: $manualReceiptResult);
        });
    }

    /**
     * @param  array<string,string>  $normalized
     * @return array<string,string>
     */
    private function baseManualReceiptInput(array $normalized, string $providerStartRunKey): array
    {
        return array_merge(Arr::only($normalized, array_merge(
            self::CHAIN_FIELDS,
            ['manual_start_executor_receipt_id'],
            self::PROCESS_STARTER_HASH_FIELDS,
            self::MANUAL_RECEIPT_HASH_FIELDS,
            ['actor', 'session', 'reason'],
        )), [
            'run_key' => $providerStartRunKey,
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function normalize(array $input): array
    {
        $required = array_merge(
            ['run_key', 'post_start_manual_start_executor_receipt_id', 'manual_start_executor_receipt_id', 'post_start_process_starter_readiness_gate_id', 'provider_start_attempt_id'],
            self::CHAIN_FIELDS,
            self::PROCESS_STARTER_HASH_FIELDS,
            self::MANUAL_RECEIPT_HASH_FIELDS,
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

        foreach (array_merge(self::PROCESS_STARTER_HASH_FIELDS, self::MANUAL_RECEIPT_HASH_FIELDS) as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/', $normalized[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        foreach (array_diff($required, array_merge(self::PROCESS_STARTER_HASH_FIELDS, self::MANUAL_RECEIPT_HASH_FIELDS)) as $field) {
            $normalized[$field] = (string) $input[$field];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,string>  $normalized
     */
    private function assertPostStartProcessStarterReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_process_starter_readiness_gate.';

        if ((string) data_get($metadata, $prefix.'post_start_process_starter_readiness_gate_id') !== $normalized['post_start_process_starter_readiness_gate_id']) {
            throw new InvalidArgumentException('post_start_process_starter_readiness_gate_id_mismatch');
        }

        foreach (array_merge(self::CHAIN_FIELDS, self::PROCESS_STARTER_HASH_FIELDS) as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_process_starter_ready_pending_manual_start_executor') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_process_starter_readiness_gate_not_ready_for_manual_start_executor');
        }

        foreach (['post_start_process_starter_readiness_gate_prepared', 'real_invoker_process_starter_readiness_gate_prepared', 'process_starter_ready', 'observed_external_process_started', 'observed_provider_started'] as $field) {
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
     * @param  array<string,mixed>|null  $manualReceiptResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $manualReceiptResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_manual_start_executor_receipt_written',
            'idempotent' => $idempotent,
            'post_start_manual_start_executor_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt.post_start_manual_start_executor_receipt_id'),
            'manual_start_executor_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt.manual_start_executor_receipt_id'),
            'real_invoker_process_starter_readiness_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt.real_invoker_process_starter_readiness_gate_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'post_start_manual_start_executor_receipt_written' => true,
            'manual_start_executor_receipt_written' => true,
            'manual_operator_start_required' => true,
            'process_starter_ready' => true,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'provider_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'dispatch_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_result' => $manualReceiptResult,
        ];
    }
}
