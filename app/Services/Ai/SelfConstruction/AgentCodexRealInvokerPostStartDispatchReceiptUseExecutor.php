<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor
{
    public function __construct(
        private readonly AgentDispatchExecutorReceiptUseWriter $receiptUseWriter,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function executePostStartDispatchReceiptUse(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_self_construction_agent_dispatch_receipts', 'atlas_ledger_events'] as $table) {
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
            $existingAttemptId = (string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.provider_start_attempt_id', '');

            if ($existingAttemptId !== '') {
                if ($existingAttemptId !== $normalized['provider_start_attempt_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_receipt_use_already_executed');
                }

                return $this->result($run, idempotent: true, receiptUseResult: null);
            }

            $this->assertDispatchExecutorHandoff($run, $metadata, $normalized);

            $receiptUseResult = $this->receiptUseWriter->markReceiptUsedAtomically([
                'receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'executor_contract_hash' => $normalized['executor_contract_hash'],
                'executor_release_authorization_hash' => $normalized['executor_release_authorization_hash'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'packet_id' => (string) $run->packet_id,
                'provider' => 'codex',
                'reason' => $normalized['reason'],
            ]);

            $metadata['codex_real_invoker_post_start_dispatch_receipt_use'] = [
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
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_dispatch_receipt_used_pending_provider_start_driver',
                'dispatch_receipt_used' => true,
                'dispatch_executor_handoff_prepared' => true,
                'future_dispatch_authorized' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'provider_start_allowed_after_mark' => false,
                'dispatch_allowed' => false,
                'receipt_use_result' => $receiptUseResult,
                'executed_by' => $normalized['actor'],
                'executed_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex real invoker post-start dispatch receipt marked used; provider start remains disabled pending provider start driver.',
                'metadata' => $metadata,
            ])->save();

            $run->refresh();

            return $this->result($run, idempotent: false, receiptUseResult: $receiptUseResult);
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
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
            'provider_start_attempt_id',
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
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
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
            'dispatch_executor_handoff_id' => (string) $input['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertDispatchExecutorHandoff(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff.dispatch_executor_handoff_id') !== $normalized['dispatch_executor_handoff_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_executor_handoff_missing_or_mismatch');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff.status') !== 'post_start_dispatch_executor_handoff_prepared_pending_dispatch_use_receipt') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_dispatch_executor_handoff_not_ready_for_receipt_use');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff.dispatch_executor_handoff_prepared', false)) {
            throw new InvalidArgumentException('dispatch_executor_handoff_not_prepared');
        }

        foreach ([
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'dispatch_allowed', 'provider_process_call_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['external_process_started', 'provider_started'] as $field) {
            if (! (bool) data_get($metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff.'.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $receiptUseResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $receiptUseResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_dispatch_receipt_used',
            'idempotent' => $idempotent,
            'provider_start_attempt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.provider_start_attempt_id'),
            'dispatch_executor_handoff_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.dispatch_executor_handoff_id'),
            'signed_dispatch_authorization_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.signed_dispatch_authorization_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.post_start_evidence_acceptance_bridge_id'),
            'signed_dispatch_receipt_hash' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.signed_dispatch_receipt_hash'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'dispatch_receipt_used' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'token_spend_allowed' => false,
            'provider_started' => true,
            'provider_process_call_allowed' => false,
            'provider_start_allowed_after_mark' => false,
            'dispatch_allowed' => false,
            'receipt_use_result' => $receiptUseResult,
        ];
    }
}
