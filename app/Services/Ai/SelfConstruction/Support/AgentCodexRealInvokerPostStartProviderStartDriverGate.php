<?php

namespace App\Services\Ai\SelfConstruction\Support;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;

class AgentCodexRealInvokerPostStartProviderStartDriverGate
{
    public const BLOCK_REASON_ADAPTER_READINESS_SELF_DECLARED = 'adapter_readiness_self_declared';
    public const BLOCK_REASON_ADAPTER_READINESS_STALE = 'adapter_readiness_stale';
    public const BLOCK_REASON_SCOPED_COMMAND_MISSING = 'scoped_command_missing';
    public const BLOCK_REASON_OUTPUT_RECEIPT_PATH_MISSING = 'output_receipt_path_missing';

    public const ACCEPTED_READINESS_BASES = ['capability_probe', 'health_check', 'adapter_registry_verified'];

    private const REQUIRED_PROOF = [
        self::BLOCK_REASON_ADAPTER_READINESS_SELF_DECLARED => 'adapter_readiness_probe',
        self::BLOCK_REASON_ADAPTER_READINESS_STALE => 'adapter_readiness_probe',
        self::BLOCK_REASON_SCOPED_COMMAND_MISSING => 'scoped_command',
        self::BLOCK_REASON_OUTPUT_RECEIPT_PATH_MISSING => 'output_receipt_path',
    ];

    public const DEFAULT_ADAPTER_READINESS_STALE_THRESHOLD_SECONDS = 300;

    public function __construct(
        private readonly AgentDispatchExecutorProviderStartDriver $providerStartDriver,
    ) {}

    /**
     * Pure pre-flight evaluator (AC1/AC2/AC3): the provider start driver only runs when adapter
     * readiness is independently proven (never self-declared), the command is scoped, and an
     * output receipt path exists to capture what the driver produces. Does not touch the
     * database; this check runs before `preparePostStartProviderStartDriver()` is ever called.
     *
     * Block-reason priority (first failing check wins):
     *   1. adapter_readiness_self_declared — readiness_basis is 'self_declared' or unrecognized
     *   2. adapter_readiness_stale         — readiness age unknown or older than the threshold
     *   3. scoped_command_missing          — scoped_command_present === false
     *   4. output_receipt_path_missing     — output_receipt_path_present === false
     *
     * INPUT:
     *   adapter_readiness_basis?: string (default 'self_declared')
     *   adapter_readiness_age_seconds?: float|null
     *   adapter_readiness_stale_threshold_seconds?: int (default 300)
     *   scoped_command_present?: bool (default false)
     *   output_receipt_path_present?: bool (default false)
     *
     * OUTPUT:
     *   { driver_allowed, block_reason, block_reasons, required_proof }
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluateDriverStart(array $input): array
    {
        $readinessBasis = (string) ($input['adapter_readiness_basis'] ?? 'self_declared');
        $readinessAge = isset($input['adapter_readiness_age_seconds']) ? (float) $input['adapter_readiness_age_seconds'] : null;
        $staleThreshold = (int) ($input['adapter_readiness_stale_threshold_seconds'] ?? self::DEFAULT_ADAPTER_READINESS_STALE_THRESHOLD_SECONDS);
        $scopedCommandPresent = (bool) ($input['scoped_command_present'] ?? false);
        $outputReceiptPathPresent = (bool) ($input['output_receipt_path_present'] ?? false);

        $blockReasons = [];

        if (! in_array($readinessBasis, self::ACCEPTED_READINESS_BASES, true)) {
            $blockReasons[] = self::BLOCK_REASON_ADAPTER_READINESS_SELF_DECLARED;
        } elseif ($readinessAge === null || $readinessAge > $staleThreshold) {
            $blockReasons[] = self::BLOCK_REASON_ADAPTER_READINESS_STALE;
        }

        if (! $scopedCommandPresent) {
            $blockReasons[] = self::BLOCK_REASON_SCOPED_COMMAND_MISSING;
        }

        if (! $outputReceiptPathPresent) {
            $blockReasons[] = self::BLOCK_REASON_OUTPUT_RECEIPT_PATH_MISSING;
        }

        $primaryReason = $blockReasons[0] ?? null;

        return [
            'driver_allowed' => $blockReasons === [],
            'block_reason' => $primaryReason,
            'block_reasons' => $blockReasons,
            'required_proof' => $primaryReason !== null ? self::REQUIRED_PROOF[$primaryReason] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preparePostStartProviderStartDriver(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach ([
            'atlas_self_construction_agent_runs',
            'atlas_self_construction_agent_dispatch_receipts',
            'atlas_self_construction_agent_dispatch_authorizations',
            'atlas_self_construction_agent_sandbox_bindings',
            'atlas_self_construction_agent_heartbeats',
            'atlas_ledger_events',
        ] as $table) {
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
            $existingAttemptId = (string) data_get($metadata, 'codex_real_invoker_post_start_provider_start_driver.provider_start_attempt_id', '');

            if ($existingAttemptId !== '') {
                if ($existingAttemptId !== $normalized['provider_start_attempt_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_provider_start_driver_already_prepared');
                }

                return $this->result($observedRun, idempotent: true, providerStartResult: null);
            }

            $this->assertReceiptUseReady($observedRun, $metadata, $normalized);

            $providerStartResult = $this->providerStartDriver->startProviderOnce([
                'receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'executor_contract_hash' => $normalized['executor_contract_hash'],
                'executor_release_authorization_hash' => $normalized['executor_release_authorization_hash'],
                'sandbox_binding_key' => $normalized['sandbox_binding_key'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'packet_id' => (string) $observedRun->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'command' => $normalized['command'],
                'cwd' => $normalized['cwd'],
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'max_runtime_minutes' => $normalized['max_runtime_minutes'],
                'max_cost_usd' => $normalized['max_cost_usd'],
                'reason' => $normalized['reason'],
            ]);

            foreach (['provider_started', 'adapter_invocation_allowed', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($providerStartResult, $field, false)) {
                    throw new InvalidArgumentException('provider_start_driver_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_provider_start_driver'] = [
                'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'dispatch_executor_handoff_id' => $normalized['dispatch_executor_handoff_id'],
                'signed_dispatch_authorization_id' => $normalized['signed_dispatch_authorization_id'],
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'executor_contract_hash' => $normalized['executor_contract_hash'],
                'executor_release_authorization_hash' => $normalized['executor_release_authorization_hash'],
                'executor_handoff_packet_hash' => $normalized['executor_handoff_packet_hash'],
                'executor_workspace_hash' => $normalized['executor_workspace_hash'],
                'executor_scope_lock_hash' => $normalized['executor_scope_lock_hash'],
                'sandbox_binding_key' => $normalized['sandbox_binding_key'],
                'command_hash' => hash('sha256', $normalized['command']),
                'cwd_hash' => hash('sha256', $normalized['cwd']),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_provider_start_driver_prepared_pending_adapter_invocation_boundary',
                'post_start_provider_start_driver_prepared' => true,
                'dispatch_receipt_used' => true,
                'dispatch_executor_handoff_prepared' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'driver_provider_started' => false,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'dispatch_allowed' => false,
                'provider_start_result' => $providerStartResult,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start provider start driver prepared; adapter invocation remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, providerStartResult: $providerStartResult);
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
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
            'sandbox_binding_key',
            'command',
            'cwd',
            'actor',
            'session',
            'max_runtime_minutes',
            'max_cost_usd',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashes = [];
        foreach ([
            'signed_dispatch_receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
        ] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));
            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $runtimeMinutes = (int) $input['max_runtime_minutes'];
        $maxCost = (float) $input['max_cost_usd'];

        if ($runtimeMinutes < 1) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ($maxCost < 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return array_merge([
            'run_key' => (string) $input['run_key'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'dispatch_executor_handoff_id' => (string) $input['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'sandbox_binding_key' => (string) $input['sandbox_binding_key'],
            'command' => (string) $input['command'],
            'cwd' => (string) $input['cwd'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => $runtimeMinutes,
            'max_cost_usd' => $maxCost,
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertReceiptUseReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.provider_start_attempt_id') !== $normalized['provider_start_attempt_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_receipt_use_missing_or_mismatch');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.status') !== 'post_start_dispatch_receipt_used_pending_provider_start_driver') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_receipt_use_not_ready_for_provider_start_driver');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.dispatch_receipt_used', false)) {
            throw new InvalidArgumentException('dispatch_receipt_not_used');
        }

        foreach ([
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'provider_process_call_allowed', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['external_process_started', 'provider_started'] as $field) {
            if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.'.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $providerStartResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $providerStartResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_provider_start_driver_prepared',
            'idempotent' => $idempotent,
            'provider_start_driver_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_start_driver.provider_start_driver_gate_id'),
            'provider_start_attempt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_start_driver.provider_start_attempt_id'),
            'dispatch_executor_handoff_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_start_driver.dispatch_executor_handoff_id'),
            'signed_dispatch_authorization_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_start_driver.signed_dispatch_authorization_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_start_driver.post_start_evidence_acceptance_bridge_id'),
            'signed_dispatch_receipt_hash' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_start_driver.signed_dispatch_receipt_hash'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'dispatch_receipt_used' => true,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'driver_provider_started' => false,
            'actual_process_start_allowed' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'dispatch_allowed' => false,
            'provider_start_result' => $providerStartResult,
        ];
    }
}
