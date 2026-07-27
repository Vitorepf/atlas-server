<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\DispatchProvider;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorSandboxBindingWriter;
use Illuminate\Support\Facades\Schema;
use Closure;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;

/**
 * GOD-DEBULK sub-split part 02 of {@see ReadinessProjectionAgentDispatchProviderSection}.
 *
 * Bodies moved VERBATIM from the facade (A1-SC-0056 dispatch-receipt guard
 * lives in part 01, byte-identical). The injected $stableHash closure keeps
 * every ($this->stableHash)(...) call unchanged, and __call routes every
 * sibling/mother back-call through the facade exactly as before.
 */
final class DispatchProviderPart02SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentDispatchProviderSection $section,
        private readonly \Closure $stableHash,
    ) {}

    public function __call(string $name, array $arguments): mixed
    {
        return $this->section->$name(...$arguments);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorPreflight(array $options = []): array
    {
        if (! Schema::hasTable('atlas_self_construction_agent_dispatch_receipts')) {
            $preflight = [
                'status' => 'blocked',
                'blocking_reasons' => ['signed_dispatch_receipt_persistence_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'dispatch_receipts_table_ready' => false,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_executor_preflight.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_dispatch_executor_preflight',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_executor_preflight' => $preflight,
                'dispatch_executor_preflight_hash' => ($this->stableHash)($preflight),
                'human_summary' => 'Agent dispatch executor preflight is blocked until the signed dispatch receipt table exists.',
            ];
        }

        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $packetId = trim((string) ($options['packet'] ?? ''));
        $query = AtlasSelfConstructionAgentDispatchReceipt::query()
            ->where('decision', 'approve_dispatch_once')
            ->where('status', 'signed_pending_dispatch')
            ->whereNull('used_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        if ($receiptHash !== '') {
            $query->where('receipt_hash', $receiptHash);
        }

        if ($packetId !== '') {
            $query->where('packet_id', $packetId);
        }

        $receipt = $query
            ->orderBy('expires_at')
            ->orderByDesc('signed_at')
            ->first();

        if (! $receipt instanceof AtlasSelfConstructionAgentDispatchReceipt) {
            $preflight = [
                'status' => 'blocked',
                'blocking_reasons' => ['no_valid_signed_dispatch_receipt'],
                'requested_packet_id' => $packetId === '' ? null : $packetId,
                'requested_receipt_hash' => $receiptHash === '' ? null : $receiptHash,
                'required_previous_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-receipt-write --json',
                'dispatch_receipts_table_ready' => true,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_executor_preflight.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_dispatch_executor_preflight',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_executor_preflight' => $preflight,
                'dispatch_executor_preflight_hash' => ($this->stableHash)($preflight),
                'non_execution_guarantees' => [
                    'agent_dispatch_executor_preflight_does_not_start_providers',
                    'agent_dispatch_executor_preflight_does_not_claim_packets',
                    'agent_dispatch_executor_preflight_does_not_release_packets',
                    'agent_dispatch_executor_preflight_does_not_dispatch_work',
                    'agent_dispatch_executor_preflight_does_not_mark_receipt_used',
                ],
                'human_summary' => 'Agent dispatch executor preflight found no valid signed pending dispatch receipt.',
            ];
        }

        $preflight = [
            'status' => 'ready_for_future_executor_contract',
            'dispatch_allowed_now' => false,
            'receipt' => [
                'receipt_id' => $receipt->id,
                'receipt_key' => $receipt->receipt_key,
                'packet_id' => $receipt->packet_id,
                'provider' => $receipt->provider,
                'provider_role' => $receipt->provider_role,
                'decision' => $receipt->decision,
                'receipt_status' => $receipt->status,
                'signed_by' => $receipt->signed_by,
                'signed_at' => $receipt->signed_at?->toIso8601String(),
                'expires_at' => $receipt->expires_at?->toIso8601String(),
                'dispatch_envelope_hash' => $receipt->dispatch_envelope_hash,
                'adapter_contract_hash' => $receipt->adapter_contract_hash,
                'receipt_hash' => $receipt->receipt_hash,
            ],
            'executor_contract_draft' => [
                'must_start_provider_once' => true,
                'must_mark_receipt_used_before_or_atomically_with_start' => true,
                'must_record_agent_run_heartbeat' => true,
                'must_capture_cost_events' => true,
                'must_capture_work_products' => true,
                'must_stop_on_scope_violation' => true,
                'must_append_evidence_before_completion' => true,
            ],
            'policy' => [
                'preflight_is_read_only' => true,
                'provider_start_requires_future_executor' => true,
                'does_not_start_providers' => true,
                'does_not_mark_receipt_used' => true,
                'does_not_dispatch_work' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_preflight.v1',
            'status' => 'agent_dispatch_executor_preflight_ready',
            'mode' => 'read_only_agent_dispatch_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_preflight' => $preflight,
            'dispatch_executor_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_preflight_does_not_start_providers',
                'agent_dispatch_executor_preflight_does_not_claim_packets',
                'agent_dispatch_executor_preflight_does_not_release_packets',
                'agent_dispatch_executor_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_preflight_does_not_mark_receipt_used',
            ],
            'human_summary' => 'Agent dispatch executor preflight found a valid signed receipt and drafted the future executor contract without starting providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_preflight', []);
        $receipt = (array) data_get($preflight, 'receipt', []);

        if (data_get($preflightPayload, 'status') !== 'agent_dispatch_executor_preflight_ready') {
            $template = [
                'status' => 'blocked',
                'blocking_reasons' => array_values(array_filter(array_merge(
                    (array) data_get($preflight, 'blocking_reasons', []),
                    ['agent_dispatch_executor_preflight_not_ready'],
                ))),
                'preflight_status' => data_get($preflightPayload, 'status'),
                'preflight_hash' => data_get($preflightPayload, 'dispatch_executor_preflight_hash'),
                'provider_start_allowed_by_template' => false,
                'dispatch_allowed_by_template' => false,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_executor_contract_template.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_dispatch_executor_contract_template',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_executor_contract_template' => $template,
                'dispatch_executor_contract_template_hash' => ($this->stableHash)($template),
                'non_execution_guarantees' => [
                    'agent_dispatch_executor_contract_template_does_not_start_providers',
                    'agent_dispatch_executor_contract_template_does_not_mark_receipt_used',
                    'agent_dispatch_executor_contract_template_does_not_dispatch_work',
                    'agent_dispatch_executor_contract_template_does_not_write_runtime_state',
                ],
                'human_summary' => 'Agent dispatch executor contract template is blocked until executor preflight finds a valid signed receipt.',
            ];
        }

        $provider = (string) data_get($receipt, 'provider', 'unknown');
        $adapterCommand = match ($provider) {
            'codex' => 'codex',
            'claude' => 'claude',
            'gemini' => 'gemini',
            'local' => 'bash',
            default => $provider,
        };

        $template = [
            'status' => 'ready_for_human_or_policy_review',
            'contract_id' => 'DISPATCH-EXECUTOR-CONTRACT-'.strtoupper(substr((string) data_get($receipt, 'receipt_hash'), 0, 24)),
            'provider' => $provider,
            'provider_role' => data_get($receipt, 'provider_role'),
            'packet_id' => data_get($receipt, 'packet_id'),
            'receipt_key' => data_get($receipt, 'receipt_key'),
            'receipt_hash' => data_get($receipt, 'receipt_hash'),
            'dispatch_envelope_hash' => data_get($receipt, 'dispatch_envelope_hash'),
            'adapter_contract_hash' => data_get($receipt, 'adapter_contract_hash'),
            'executor_must' => [
                'verify_receipt_status_is_signed_pending_dispatch',
                'verify_receipt_is_unused_and_unexpired',
                'verify_packet_scope_before_provider_start',
                'mark_receipt_used_atomically_with_provider_start_or_before_start',
                'create_or_update_agent_run_runtime_state',
                'write_heartbeat_before_and_after_provider_invocation',
                'capture_provider_cost_events',
                'capture_work_products',
                'stop_on_scope_or_hot_file_violation',
                'append_evidence_before_marking_terminal',
            ],
            'executor_must_not' => [
                'start_provider_without_signed_receipt',
                'reuse_receipt_after_used_at_is_set',
                'change_packet_scope',
                'write_outside_allowed_files',
                'skip_liveness_or_cost_tracking',
                'self_merge_or_publish_without_review_chain',
            ],
            'adapter_invocation_draft' => [
                'adapter' => $adapterCommand,
                'provider' => $provider,
                'input_source' => 'agent_start_packet_or_wakeup_context_pack',
                'required_context' => [
                    'packet_id',
                    'allowed_files',
                    'forbidden_scopes',
                    'required_gates',
                    'stop_conditions',
                    'continuation_summary',
                ],
                'token_policy' => [
                    'use_continuation_summary_not_full_history' => true,
                    'include_only_packet_relevant_docs' => true,
                    'avoid_duplicate_context_across_parallel_agents' => true,
                ],
            ],
            'atomicity_contract' => [
                'receipt_used_at_must_be_set_once' => true,
                'provider_start_and_receipt_use_must_be_idempotent' => true,
                'failed_start_keeps_terminal_evidence' => true,
                'no_silent_retry_without_new_heartbeat' => true,
            ],
            'observability_contract' => [
                'heartbeat_required' => true,
                'cost_event_required_when_provider_reports_usage' => true,
                'work_product_required_for_file_or_doc_changes' => true,
                'terminal_status_required' => true,
            ],
            'human_signature_fields' => [
                'approved_by',
                'approved_at',
                'max_provider_starts',
                'max_runtime_minutes',
                'max_cost_usd',
                'notes',
            ],
            'provider_start_allowed_by_template' => false,
            'dispatch_allowed_by_template' => false,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_contract_template.v1',
            'status' => 'agent_dispatch_executor_contract_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_contract_template' => $template,
            'dispatch_executor_contract_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_contract_template_does_not_start_providers',
                'agent_dispatch_executor_contract_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_contract_template_does_not_dispatch_work',
                'agent_dispatch_executor_contract_template_does_not_write_runtime_state',
            ],
            'human_summary' => 'Agent dispatch executor contract template is ready for review, but it does not start providers or mark dispatch receipts used.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleasePreflight(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_contract_template', []);
        $persistenceStatusPayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceStatus($options);
        $persistenceStatus = (array) data_get($persistenceStatusPayload, 'dispatch_executor_release_authorization_persistence_status', []);
        $persistedAuthorizationUsable = (bool) data_get($persistenceStatus, 'selected_usable_for_future_release_preflight', false);
        $receiptUseWriterPayload = $this->agentDispatchExecutorReceiptUseWriterPreflight($options);
        $receiptUseWriter = (array) data_get($receiptUseWriterPayload, 'dispatch_executor_receipt_use_writer_preflight', []);
        $receiptUseWriterReady = data_get($receiptUseWriterPayload, 'status') === 'blocked'
            ? false
            : (bool) data_get($receiptUseWriter, 'writer_ready_for_future_release', false);
        $sandboxBindingPayload = $this->agentDispatchExecutorSandboxBindingPreflight($options);
        $sandboxBinding = (array) data_get($sandboxBindingPayload, 'dispatch_executor_sandbox_binding_preflight', []);
        $sandboxBindingReady = data_get($sandboxBindingPayload, 'status') === 'blocked'
            ? false
            : (bool) data_get($sandboxBinding, 'binding_ready_for_future_release', false);
        $providerStartDriverPayload = $this->agentDispatchExecutorProviderStartDriverPreflight($options);
        $providerStartDriver = (array) data_get($providerStartDriverPayload, 'dispatch_executor_provider_start_driver_preflight', []);
        $providerStartDriverReady = data_get($providerStartDriverPayload, 'status') === 'blocked'
            ? false
            : (bool) data_get($providerStartDriver, 'driver_ready_for_future_release', false);
        $adapterInvocationPayload = $this->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
        $adapterInvocation = (array) data_get($adapterInvocationPayload, 'dispatch_executor_adapter_invocation_boundary_preflight', []);
        $adapterInvocationReady = data_get($adapterInvocationPayload, 'status') === 'blocked'
            ? false
            : (bool) data_get($adapterInvocation, 'boundary_ready_for_future_release', false);

        $blockingReasons = [];
        if (data_get($contractPayload, 'status') !== 'agent_dispatch_executor_contract_template_ready') {
            $blockingReasons[] = 'agent_dispatch_executor_contract_template_not_ready';
        }

        if (! $persistedAuthorizationUsable) {
            $blockingReasons[] = 'executor_release_authorization_receipt_missing';
        }

        if (data_get($persistenceStatusPayload, 'status') !== 'agent_dispatch_executor_release_authorization_persistence_status_ready') {
            $blockingReasons[] = 'executor_release_authorization_persistence_status_not_ready';
        }

        if (! $receiptUseWriterReady) {
            $blockingReasons[] = 'receipt_use_atomic_writer_missing';
        }

        if (! $sandboxBindingReady) {
            $blockingReasons[] = 'provider_sandbox_binding_missing';
        }

        if (! $providerStartDriverReady) {
            $blockingReasons[] = 'provider_start_driver_disabled';
        }

        if (! $adapterInvocationReady) {
            $blockingReasons[] = 'adapter_invocation_boundary_missing';
        }

        $blockingReasons = array_values(array_unique($blockingReasons));

        $preflight = [
            'status' => 'blocked',
            'blocking_reasons' => $blockingReasons,
            'contract_status' => data_get($contractPayload, 'status'),
            'contract_hash' => data_get($contractPayload, 'dispatch_executor_contract_template_hash'),
            'provider' => data_get($contract, 'provider'),
            'provider_role' => data_get($contract, 'provider_role'),
            'packet_id' => data_get($contract, 'packet_id'),
            'receipt_key' => data_get($contract, 'receipt_key'),
            'persistence_status' => data_get($persistenceStatus, 'status'),
            'persistence_status_hash' => data_get($persistenceStatusPayload, 'dispatch_executor_release_authorization_persistence_status_hash'),
            'selected_authorization' => data_get($persistenceStatus, 'selected_authorization'),
            'receipt_use_writer_status' => data_get($receiptUseWriter, 'status'),
            'receipt_use_writer_preflight_hash' => data_get($receiptUseWriterPayload, 'dispatch_executor_receipt_use_writer_preflight_hash'),
            'sandbox_binding_status' => data_get($sandboxBinding, 'status'),
            'sandbox_binding_preflight_hash' => data_get($sandboxBindingPayload, 'dispatch_executor_sandbox_binding_preflight_hash'),
            'selected_sandbox_binding' => data_get($sandboxBinding, 'selected_binding'),
            'provider_start_driver_status' => data_get($providerStartDriver, 'status'),
            'provider_start_driver_preflight_hash' => data_get($providerStartDriverPayload, 'dispatch_executor_provider_start_driver_preflight_hash'),
            'adapter_invocation_boundary_status' => data_get($adapterInvocation, 'status'),
            'adapter_invocation_boundary_preflight_hash' => data_get($adapterInvocationPayload, 'dispatch_executor_adapter_invocation_boundary_preflight_hash'),
            'release_requirements' => [
                'signed_executor_release_authorization' => $persistedAuthorizationUsable,
                'provider_sandbox_or_worktree_binding' => $sandboxBindingReady,
                'atomic_receipt_used_writer' => $receiptUseWriterReady,
                'provider_start_adapter_enabled' => $providerStartDriverReady && $adapterInvocationReady,
                'adapter_invocation_boundary_ready' => $adapterInvocationReady,
                'pre_start_heartbeat_writer' => (bool) data_get($providerStartDriver, 'observability.agent_heartbeat_table_ready', false),
                'post_start_observability_watch' => (bool) data_get($providerStartDriver, 'observability.agent_runs_table_ready', false),
            ],
            'future_release_sequence' => [
                'validate_contract_hash',
                'validate_signed_executor_release_authorization',
                'bind_provider_to_workspace_and_packet_scope',
                'atomically_mark_receipt_used',
                'create_or_update_agent_run',
                'write_pre_start_heartbeat',
                'prepare_adapter_invocation_boundary',
                'start_provider_once',
                'capture_cost_work_and_terminal_evidence',
            ],
            'policy' => [
                'preflight_is_read_only' => true,
                'dispatch_release_allowed_now' => false,
                'provider_start_allowed_now' => false,
                'manual_override_requires_new_signed_receipt' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_preflight.v1',
            'status' => 'blocked',
            'mode' => 'read_only_agent_dispatch_executor_release_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_preflight' => $preflight,
            'dispatch_executor_release_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_preflight_does_not_start_providers',
                'agent_dispatch_executor_release_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_release_preflight_does_not_write_runtime_state',
            ],
            'human_summary' => 'Agent dispatch executor release preflight is blocked by design until a signed executor release authorization, sandbox binding and atomic receipt-use writer exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReceiptUseWriterContractTemplate(array $options = []): array
    {
        $executorPayload = $this->agentDispatchExecutorPreflight($options);
        $executor = (array) data_get($executorPayload, 'dispatch_executor_preflight', []);
        $receipt = (array) data_get($executor, 'receipt', []);
        $receiptHash = (string) data_get($receipt, 'receipt_hash', (string) ($options['receipt_hash'] ?? ''));

        $contract = [
            'status' => 'agent_dispatch_executor_receipt_use_writer_contract_template_ready',
            'contract_id' => 'DISPATCH-EXECUTOR-RECEIPT-USE-WRITER-'.strtoupper(substr(($this->stableHash)([
                'receipt_hash' => $receiptHash,
                'executor_preflight_hash' => data_get($executorPayload, 'dispatch_executor_preflight_hash'),
            ]), 0, 24)),
            'source_executor_preflight_status' => data_get($executorPayload, 'status'),
            'source_executor_preflight_hash' => data_get($executorPayload, 'dispatch_executor_preflight_hash'),
            'target' => [
                'table' => 'atlas_self_construction_agent_dispatch_receipts',
                'model' => 'App\\Models\\AtlasSelfConstructionAgentDispatchReceipt',
                'identity_fields' => ['receipt_hash', 'receipt_key'],
                'mutable_fields' => ['used_at', 'status', 'payload.receipt_use'],
            ],
            'receipt' => [
                'receipt_key' => data_get($receipt, 'receipt_key'),
                'receipt_hash' => $receiptHash === '' ? null : $receiptHash,
                'packet_id' => data_get($receipt, 'packet_id'),
                'provider' => data_get($receipt, 'provider'),
                'provider_role' => data_get($receipt, 'provider_role'),
                'receipt_status' => data_get($receipt, 'receipt_status'),
            ],
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentDispatchExecutorReceiptUseWriter',
                'method' => 'markReceiptUsedAtomically',
                'input_contract' => [
                    'receipt_hash',
                    'executor_contract_hash',
                    'executor_release_authorization_hash',
                    'provider_start_attempt_id',
                    'actor',
                    'session',
                    'packet_id',
                    'provider',
                    'reason',
                ],
                'result_contract' => [
                    'receipt_id',
                    'receipt_key',
                    'receipt_hash',
                    'previous_status',
                    'new_status',
                    'used_at',
                    'idempotent',
                    'provider_start_allowed_after_mark',
                ],
            ],
            'atomic_guards' => [
                'row_lock_by_receipt_hash',
                'require_decision_approve_dispatch_once',
                'require_status_signed_pending_dispatch',
                'require_used_at_null',
                'require_receipt_not_expired',
                'require_packet_matches_executor_contract',
                'require_provider_matches_executor_contract',
                'require_executor_release_authorization_hash',
                'write_receipt_use_metadata_before_provider_start',
            ],
            'forbidden_writer_behaviors' => [
                'starting_provider',
                'dispatching_work',
                'claiming_packets',
                'releasing_packets',
                'persisting_release_authorization',
                'accepting_or_validating_raw_signatures',
                'marking_multiple_receipts',
                'overwriting_existing_used_at',
                'bypassing_row_lock',
            ],
            'required_tests' => [
                'marks_one_pending_receipt_used_once',
                'is_idempotent_for_same_provider_start_attempt',
                'rejects_already_used_receipt_for_different_attempt',
                'rejects_expired_receipt',
                'rejects_wrong_packet_or_provider',
                'does_not_start_provider_or_dispatch_work',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentDispatchExecutorReceiptUseWriter.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReceiptUseWriterTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'writer_implementation_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-receipt-use-writer-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_receipt_use_writer_contract_template.v1',
            'status' => 'agent_dispatch_executor_receipt_use_writer_contract_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_receipt_use_writer_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_receipt_use_writer_contract_template' => $contract,
            'dispatch_executor_receipt_use_writer_contract_template_hash' => ($this->stableHash)($contract),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_receipt_use_writer_contract_template_does_not_start_providers',
                'agent_dispatch_executor_receipt_use_writer_contract_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_receipt_use_writer_contract_template_does_not_dispatch_work',
                'agent_dispatch_executor_receipt_use_writer_contract_template_does_not_write_ledger',
                'agent_dispatch_executor_receipt_use_writer_contract_template_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor receipt-use writer contract template defines the future atomic mark-used writer, but does not mark receipts used or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReceiptUseWriterPreflight(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorReceiptUseWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_receipt_use_writer_contract_template', []);
        $writerClass = AgentDispatchExecutorReceiptUseWriter::class;
        $receiptTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $receiptModelReady = class_exists(AtlasSelfConstructionAgentDispatchReceipt::class);
        $writerReady = class_exists($writerClass);
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $receiptTableReady ? null : 'dispatch_receipts_table_missing',
            $receiptModelReady ? null : 'dispatch_receipt_model_missing',
            $writerReady ? null : 'receipt_use_writer_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_dispatch_executor_receipt_use_writer_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'dispatch_executor_receipt_use_writer_contract_template_hash'),
            'source_executor_preflight_status' => data_get($contract, 'source_executor_preflight_status'),
            'receipt' => data_get($contract, 'receipt'),
            'storage' => [
                'dispatch_receipts_table_ready' => $receiptTableReady,
                'dispatch_receipt_model_ready' => $receiptModelReady,
                'receipt_use_writer_ready' => $writerReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'writer_ready_for_future_release' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_receipt_use_writer_service',
                'add_row_lock_and_idempotency_logic',
                'add_expiry_packet_provider_guards',
                'add_no_provider_start_side_effect_tests',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentDispatchExecutorReceiptUseWriter.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReceiptUseWriterTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_dispatch_executor_receipt_use_writer',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'receipt_use_mark_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'writer_file_creation_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-receipt-use-writer-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_receipt_use_writer_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_dispatch_executor_receipt_use_writer_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_receipt_use_writer_preflight' => $preflight,
            'dispatch_executor_receipt_use_writer_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_receipt_use_writer_preflight_does_not_start_providers',
                'agent_dispatch_executor_receipt_use_writer_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_receipt_use_writer_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_receipt_use_writer_preflight_does_not_write_ledger',
                'agent_dispatch_executor_receipt_use_writer_preflight_does_not_create_writer_files',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent dispatch executor receipt-use writer preflight is ready for future release checks, but still does not mark receipts used or start providers.'
                : 'Agent dispatch executor receipt-use writer preflight is blocked until the atomic writer and required storage exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReceiptUseWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorReceiptUseWriterPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_receipt_use_writer_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_receipt_use_writer_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create atomic receipt-use writer service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorReceiptUseWriter.php'],
                'acceptance' => 'Service exposes markReceiptUsedAtomically and locks one dispatch receipt by receipt_hash before mutating used_at/status.',
            ],
            [
                'id' => 'T2',
                'title' => 'Implement receipt-use guards and idempotency',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorReceiptUseWriter.php'],
                'acceptance' => 'Writer rejects expired, already-used, wrong packet/provider and non-approve_dispatch_once receipts; same provider_start_attempt_id remains idempotent.',
            ],
            [
                'id' => 'T3',
                'title' => 'Write receipt-use feature tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReceiptUseWriterTest.php'],
                'acceptance' => 'Tests prove one-time use, idempotency, rejection paths and no provider dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Keep release preflight blocked until provider sandbox and start driver exist',
                'type' => 'integration_gate',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Receipt-use writer readiness can satisfy only the atomic writer requirement; provider release remains blocked by sandbox/start-driver gates.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_receipt_use_writer_implementation',
            'implementation_packet_id' => 'AGENT-DISPATCH-EXECUTOR-RECEIPT-USE-WRITER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the atomic dispatch receipt-use writer so a future executor can mark exactly one signed receipt used before provider start.',
            'non_goals' => [
                'do_not_start_providers',
                'do_not_dispatch_work',
                'do_not_persist_release_authorization',
                'do_not_accept_or_validate_raw_signatures',
                'do_not_release_executor',
                'do_not_modify_provider_start_drivers',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'provider_start_drivers',
                'hot_kernel_runtime',
                'voice_runtime',
                'packet_claim_or_completion_state',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'one_pending_signed_receipt_can_be_marked_used_once',
                'same_provider_start_attempt_is_idempotent',
                'different_attempt_cannot_reuse_used_receipt',
                'expired_receipt_is_rejected',
                'wrong_packet_or_provider_is_rejected',
                'writer_does_not_start_provider_or_dispatch_work',
                'release_preflight_still_blocks_without_provider_sandbox_and_start_driver',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_provider_or_dispatch_work',
                'need_to_modify_file_outside_allowed_files',
                'need_to_change_dispatch_receipt_schema_without_new_contract',
                'need_to_validate_raw_signature_inside_receipt_use_writer',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'receipt_use_mark_allowed_by_packet' => false,
                'provider_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'executor_release_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_receipt_use_writer_implementation_packet.v1',
            'status' => 'ready_for_scoped_receipt_use_writer_implementation',
            'mode' => 'read_only_agent_dispatch_executor_receipt_use_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_receipt_use_writer_implementation_packet' => $packet,
            'dispatch_executor_receipt_use_writer_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_receipt_use_writer_implementation_packet_does_not_start_providers',
                'agent_dispatch_executor_receipt_use_writer_implementation_packet_does_not_mark_receipt_used',
                'agent_dispatch_executor_receipt_use_writer_implementation_packet_does_not_dispatch_work',
                'agent_dispatch_executor_receipt_use_writer_implementation_packet_does_not_write_ledger',
                'agent_dispatch_executor_receipt_use_writer_implementation_packet_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor receipt-use writer implementation packet is ready; it defines scoped writer work but does not create files or mark receipts used.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorSandboxBindingContractTemplate(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_contract_template', []);
        $workspaceRoot = (string) (($options['workspace'] ?? null) ?: base_path());

        $template = [
            'status' => 'agent_dispatch_executor_sandbox_binding_contract_template_ready',
            'contract_id' => 'DISPATCH-EXECUTOR-SANDBOX-BINDING-'.strtoupper(substr(($this->stableHash)([
                'contract_hash' => data_get($contractPayload, 'dispatch_executor_contract_template_hash'),
                'workspace_root' => $workspaceRoot,
                'packet_id' => data_get($contract, 'packet_id'),
                'provider' => data_get($contract, 'provider'),
            ]), 0, 24)),
            'source_executor_contract_status' => data_get($contractPayload, 'status'),
            'source_executor_contract_hash' => data_get($contractPayload, 'dispatch_executor_contract_template_hash'),
            'workspace_identity' => [
                'workspace_root' => $workspaceRoot,
                'forge_workspace_id' => 'atlas-self-construction-forge-workspace',
                'obra_id' => 'atlas-self-construction-os',
            ],
            'receipt_binding' => [
                'receipt_hash' => data_get($contract, 'receipt_hash'),
                'receipt_key' => data_get($contract, 'receipt_key'),
                'packet_id' => data_get($contract, 'packet_id'),
                'provider' => data_get($contract, 'provider'),
                'provider_role' => data_get($contract, 'provider_role'),
            ],
            'target' => [
                'table' => 'atlas_self_construction_agent_sandbox_bindings',
                'model' => 'App\\Models\\AtlasSelfConstructionAgentSandboxBinding',
                'identity_fields' => ['binding_key', 'receipt_hash'],
                'mutable_fields' => ['status', 'payload.binding_state', 'activated_at', 'released_at'],
            ],
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentDispatchExecutorSandboxBindingWriter',
                'method' => 'bindProviderToWorkspace',
                'input_contract' => [
                    'receipt_hash',
                    'executor_contract_hash',
                    'executor_release_authorization_hash',
                    'packet_id',
                    'provider',
                    'provider_role',
                    'workspace_root',
                    'worktree_path',
                    'branch',
                    'allowed_files_hash',
                    'forbidden_scope_hash',
                    'scope_validator_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'binding_id',
                    'binding_key',
                    'receipt_hash',
                    'packet_id',
                    'provider',
                    'worktree_path',
                    'branch',
                    'status',
                    'idempotent',
                    'provider_start_allowed_after_binding',
                ],
            ],
            'binding_guards' => [
                'one_active_binding_per_receipt_hash',
                'workspace_root_must_match_forge_workspace',
                'worktree_path_must_be_under_workspace_root_or_registered_worktree_root',
                'packet_id_must_match_signed_dispatch_receipt',
                'provider_must_match_executor_contract',
                'allowed_files_hash_must_match_packet_scope',
                'forbidden_scope_hash_must_match_packet_scope',
                'scope_validator_hash_must_be_current',
                'hot_scope_overlap_must_be_absent',
                'binding_must_exist_before_provider_start',
            ],
            'forbidden_writer_behaviors' => [
                'starting_provider',
                'dispatching_work',
                'creating_or_deleting_worktrees',
                'changing_packet_scope',
                'claiming_or_completing_packets',
                'marking_dispatch_receipt_used',
                'persisting_release_authorization',
                'writing_files_outside_binding_storage',
                'binding_two_providers_to_same_receipt',
            ],
            'required_tests' => [
                'creates_one_binding_for_signed_contract_scope',
                'is_idempotent_for_same_receipt_and_workspace',
                'rejects_workspace_outside_allowed_root',
                'rejects_packet_or_provider_mismatch',
                'rejects_hot_scope_overlap',
                'does_not_start_provider_or_mark_receipt_used',
                'writes_binding_event_in_same_transaction',
            ],
            'implementation_files_allowed_future' => [
                'database/migrations/*_create_atlas_self_construction_agent_sandbox_bindings_table.php',
                'app/Models/AtlasSelfConstructionAgentSandboxBinding.php',
                'app/Services/Ai/SelfConstruction/AgentDispatchExecutorSandboxBindingWriter.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorSandboxBindingWriterTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'writer_implementation_allowed_here' => false,
                'sandbox_binding_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-sandbox-binding-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_sandbox_binding_contract_template.v1',
            'status' => 'agent_dispatch_executor_sandbox_binding_contract_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_sandbox_binding_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_sandbox_binding_contract_template' => $template,
            'dispatch_executor_sandbox_binding_contract_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_sandbox_binding_contract_template_does_not_start_providers',
                'agent_dispatch_executor_sandbox_binding_contract_template_does_not_bind_workspace',
                'agent_dispatch_executor_sandbox_binding_contract_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_sandbox_binding_contract_template_does_not_dispatch_work',
                'agent_dispatch_executor_sandbox_binding_contract_template_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor sandbox binding contract template defines the future provider-to-worktree binding, but does not bind workspaces or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorSandboxBindingPreflight(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorSandboxBindingContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_sandbox_binding_contract_template', []);
        $bindingTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $bindingModelReady = class_exists(AtlasSelfConstructionAgentSandboxBinding::class);
        $bindingWriterClass = AgentDispatchExecutorSandboxBindingWriter::class;
        $bindingWriterReady = class_exists($bindingWriterClass);
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $bindingTableReady ? null : 'sandbox_bindings_table_missing',
            $bindingModelReady ? null : 'sandbox_binding_model_missing',
            $bindingWriterReady ? null : 'sandbox_binding_writer_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_dispatch_executor_sandbox_binding_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'dispatch_executor_sandbox_binding_contract_template_hash'),
            'source_executor_contract_status' => data_get($contract, 'source_executor_contract_status'),
            'workspace_identity' => data_get($contract, 'workspace_identity'),
            'receipt_binding' => data_get($contract, 'receipt_binding'),
            'storage' => [
                'sandbox_bindings_table_ready' => $bindingTableReady,
                'sandbox_binding_model_ready' => $bindingModelReady,
                'sandbox_binding_writer_ready' => $bindingWriterReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'binding_ready_for_future_release' => $blockingReasons === [],
            'selected_binding' => null,
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_sandbox_binding_storage',
                'create_sandbox_binding_model',
                'create_sandbox_binding_writer_service',
                'add_workspace_root_and_scope_hash_guards',
                'add_no_provider_start_side_effect_tests',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentDispatchExecutorSandboxBindingWriter.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorSandboxBindingWriterTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_dispatch_executor_sandbox_binding',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'sandbox_binding_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'writer_file_creation_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-sandbox-binding-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_sandbox_binding_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_dispatch_executor_sandbox_binding_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_sandbox_binding_preflight' => $preflight,
            'dispatch_executor_sandbox_binding_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_sandbox_binding_preflight_does_not_start_providers',
                'agent_dispatch_executor_sandbox_binding_preflight_does_not_bind_workspace',
                'agent_dispatch_executor_sandbox_binding_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_sandbox_binding_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_sandbox_binding_preflight_does_not_write_ledger',
                'agent_dispatch_executor_sandbox_binding_preflight_does_not_create_writer_files',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent dispatch executor sandbox binding preflight is ready for future release checks, but still does not bind a workspace or start providers.'
                : 'Agent dispatch executor sandbox binding preflight is blocked until binding storage, model and writer exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorSandboxBindingImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorSandboxBindingPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_sandbox_binding_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_sandbox_binding_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create sandbox binding storage and model',
                'type' => 'storage',
                'allowed_files' => [
                    'database/migrations/*_create_atlas_self_construction_agent_sandbox_bindings_table.php',
                    'app/Models/AtlasSelfConstructionAgentSandboxBinding.php',
                ],
                'acceptance' => 'Binding storage records receipt_hash, packet/provider identity, workspace/worktree identity, scope hashes, status and append-only metadata.',
            ],
            [
                'id' => 'T2',
                'title' => 'Create sandbox binding writer service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorSandboxBindingWriter.php'],
                'acceptance' => 'Writer exposes bindProviderToWorkspace, validates workspace/scope/provider identity and creates one active binding per receipt.',
            ],
            [
                'id' => 'T3',
                'title' => 'Write binding feature tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorSandboxBindingWriterTest.php'],
                'acceptance' => 'Tests prove idempotency, workspace root rejection, packet/provider mismatch rejection, hot-scope rejection and no provider start side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Keep executor release preflight gated by provider start driver',
                'type' => 'integration_gate',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Sandbox binding readiness can satisfy only the sandbox/worktree requirement; provider release remains blocked by provider_start_driver_disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_sandbox_binding_implementation',
            'implementation_packet_id' => 'AGENT-DISPATCH-EXECUTOR-SANDBOX-BINDING-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement governed provider sandbox/worktree binding so every future provider start is tied to one receipt, one packet scope and one workspace identity.',
            'non_goals' => [
                'do_not_start_providers',
                'do_not_dispatch_work',
                'do_not_create_or_delete_actual_worktrees',
                'do_not_mark_receipts_used',
                'do_not_persist_release_authorization',
                'do_not_modify_provider_start_drivers',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'provider_start_drivers',
                'actual_worktree_creation_or_deletion',
                'hot_kernel_runtime',
                'voice_runtime',
                'packet_claim_or_completion_state',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'one_active_binding_per_receipt_hash',
                'same_receipt_and_workspace_binding_is_idempotent',
                'workspace_outside_allowed_root_is_rejected',
                'packet_or_provider_mismatch_is_rejected',
                'hot_scope_overlap_is_rejected',
                'writer_does_not_start_provider_or_mark_receipt_used',
                'release_preflight_still_blocks_without_provider_start_driver',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_provider_or_dispatch_work',
                'need_to_create_or_delete_real_worktree',
                'need_to_modify_file_outside_allowed_files',
                'need_to_change_dispatch_receipt_schema_without_new_contract',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'sandbox_binding_allowed_by_packet' => false,
                'provider_start_allowed_by_packet' => false,
                'receipt_use_mark_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_sandbox_binding_implementation_packet.v1',
            'status' => 'ready_for_scoped_sandbox_binding_implementation',
            'mode' => 'read_only_agent_dispatch_executor_sandbox_binding_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_sandbox_binding_implementation_packet' => $packet,
            'dispatch_executor_sandbox_binding_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_sandbox_binding_implementation_packet_does_not_start_providers',
                'agent_dispatch_executor_sandbox_binding_implementation_packet_does_not_bind_workspace',
                'agent_dispatch_executor_sandbox_binding_implementation_packet_does_not_mark_receipt_used',
                'agent_dispatch_executor_sandbox_binding_implementation_packet_does_not_dispatch_work',
                'agent_dispatch_executor_sandbox_binding_implementation_packet_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor sandbox binding implementation packet is ready; it defines scoped binding work but does not create files, bind workspaces or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorProviderStartDriverContractTemplate(array $options = []): array
    {
        $executorContractPayload = $this->agentDispatchExecutorContractTemplate($options);
        $executorContract = (array) data_get($executorContractPayload, 'dispatch_executor_contract_template', []);
        $receiptUsePayload = $this->agentDispatchExecutorReceiptUseWriterPreflight($options);
        $sandboxPayload = $this->agentDispatchExecutorSandboxBindingPreflight($options);
        $persistencePayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceStatus($options);

        $template = [
            'status' => 'agent_dispatch_executor_provider_start_driver_contract_template_ready',
            'contract_id' => 'DISPATCH-EXECUTOR-PROVIDER-START-DRIVER-'.strtoupper(substr(($this->stableHash)([
                'executor_contract_hash' => data_get($executorContractPayload, 'dispatch_executor_contract_template_hash'),
                'receipt_use_preflight_hash' => data_get($receiptUsePayload, 'dispatch_executor_receipt_use_writer_preflight_hash'),
                'sandbox_preflight_hash' => data_get($sandboxPayload, 'dispatch_executor_sandbox_binding_preflight_hash'),
            ]), 0, 24)),
            'source_executor_contract_status' => data_get($executorContractPayload, 'status'),
            'source_executor_contract_hash' => data_get($executorContractPayload, 'dispatch_executor_contract_template_hash'),
            'receipt_use_writer_preflight_hash' => data_get($receiptUsePayload, 'dispatch_executor_receipt_use_writer_preflight_hash'),
            'sandbox_binding_preflight_hash' => data_get($sandboxPayload, 'dispatch_executor_sandbox_binding_preflight_hash'),
            'release_authorization_persistence_status_hash' => data_get($persistencePayload, 'dispatch_executor_release_authorization_persistence_status_hash'),
            'provider' => data_get($executorContract, 'provider'),
            'provider_role' => data_get($executorContract, 'provider_role'),
            'packet_id' => data_get($executorContract, 'packet_id'),
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentDispatchExecutorProviderStartDriver',
                'method' => 'startProviderOnce',
                'input_contract' => [
                    'receipt_hash',
                    'executor_contract_hash',
                    'executor_release_authorization_hash',
                    'sandbox_binding_key',
                    'provider_start_attempt_id',
                    'provider',
                    'adapter',
                    'command',
                    'cwd',
                    'actor',
                    'session',
                    'max_runtime_minutes',
                    'max_cost_usd',
                    'reason',
                ],
                'result_contract' => [
                    'provider_start_attempt_id',
                    'provider',
                    'adapter',
                    'process_status',
                    'agent_run_id',
                    'pre_start_heartbeat_id',
                    'terminal_observability_required',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'mandatory_pre_start_guards' => [
                'signed_release_authorization_is_persisted_and_unexpired',
                'dispatch_receipt_is_used_pending_provider_start',
                'sandbox_binding_is_active_for_same_receipt_packet_provider',
                'executor_contract_hash_matches_authorized_contract',
                'provider_matches_adapter_contract',
                'cwd_matches_sandbox_binding_worktree_path',
                'agent_run_state_is_created_or_updated_before_start',
                'pre_start_heartbeat_is_written_before_start',
                'cost_budget_is_known_before_start',
                'continuation_summary_is_packet_scoped',
            ],
            'forbidden_driver_behaviors' => [
                'starting_without_used_receipt',
                'starting_without_active_sandbox_binding',
                'starting_without_pre_start_heartbeat',
                'starting_twice_for_same_receipt',
                'using_full_chat_history_as_context',
                'writing_outside_worktree',
                'self_merging_or_publishing',
                'bypassing_cost_budget',
            ],
            'adapter_policy' => [
                'codex' => 'local_codex_cli_or_codex_app_bridge',
                'claude' => 'future_claude_adapter',
                'gemini' => 'future_gemini_adapter',
                'local' => 'restricted_local_shell_adapter',
                'http' => 'future_http_provider_adapter',
            ],
            'required_tests' => [
                'rejects_start_without_used_receipt',
                'rejects_start_without_active_sandbox_binding',
                'rejects_provider_or_packet_mismatch',
                'writes_pre_start_heartbeat_before_adapter_invocation',
                'records_agent_run_state_before_adapter_invocation',
                'does_not_self_merge_or_mark_packet_complete',
                'is_idempotent_for_same_provider_start_attempt',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentDispatchExecutorProviderStartDriver.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorProviderStartDriverTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'driver_implementation_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-provider-start-driver-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_provider_start_driver_contract_template.v1',
            'status' => 'agent_dispatch_executor_provider_start_driver_contract_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_provider_start_driver_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_provider_start_driver_contract_template' => $template,
            'dispatch_executor_provider_start_driver_contract_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_provider_start_driver_contract_template_does_not_start_providers',
                'agent_dispatch_executor_provider_start_driver_contract_template_does_not_create_driver_files',
                'agent_dispatch_executor_provider_start_driver_contract_template_does_not_mark_receipts_used',
                'agent_dispatch_executor_provider_start_driver_contract_template_does_not_bind_workspace',
                'agent_dispatch_executor_provider_start_driver_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent dispatch executor provider start driver contract template defines the future governed start driver, but does not start providers or create driver files.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorProviderStartDriverPreflight(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorProviderStartDriverContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_provider_start_driver_contract_template', []);
        $driverClass = AgentDispatchExecutorProviderStartDriver::class;
        $driverReady = class_exists($driverClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $costTableReady = Schema::hasTable('atlas_self_construction_agent_cost_events');
        $workProductTableReady = Schema::hasTable('atlas_self_construction_agent_work_products');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $driverReady ? null : 'provider_start_driver_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $heartbeatTableReady ? null : 'agent_heartbeat_table_missing',
            $costTableReady ? null : 'agent_cost_events_table_missing',
            $workProductTableReady ? null : 'agent_work_products_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_dispatch_executor_provider_start_driver_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'dispatch_executor_provider_start_driver_contract_template_hash'),
            'source_executor_contract_status' => data_get($contract, 'source_executor_contract_status'),
            'provider' => data_get($contract, 'provider'),
            'provider_role' => data_get($contract, 'provider_role'),
            'packet_id' => data_get($contract, 'packet_id'),
            'storage' => [
                'provider_start_driver_ready' => $driverReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'observability' => [
                'agent_runs_table_ready' => $runsTableReady,
                'agent_heartbeat_table_ready' => $heartbeatTableReady,
                'agent_cost_events_table_ready' => $costTableReady,
                'agent_work_products_table_ready' => $workProductTableReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'driver_ready_for_future_release' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_provider_start_driver_service',
                'validate_receipt_use_before_start',
                'validate_active_sandbox_binding_before_start',
                'write_pre_start_heartbeat_before_adapter_invocation',
                'keep_adapter_invocation_disabled_until_signed_release_path_calls_driver',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentDispatchExecutorProviderStartDriver.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorProviderStartDriverTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_dispatch_executor_provider_start_driver',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'provider_start_allowed_here' => false,
                'driver_file_creation_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-provider-start-driver-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_provider_start_driver_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_dispatch_executor_provider_start_driver_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_provider_start_driver_preflight' => $preflight,
            'dispatch_executor_provider_start_driver_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_provider_start_driver_preflight_does_not_start_providers',
                'agent_dispatch_executor_provider_start_driver_preflight_does_not_create_driver_files',
                'agent_dispatch_executor_provider_start_driver_preflight_does_not_mark_receipts_used',
                'agent_dispatch_executor_provider_start_driver_preflight_does_not_bind_workspace',
                'agent_dispatch_executor_provider_start_driver_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent dispatch executor provider start driver preflight is ready for a future signed release path, but this command still does not start providers.'
                : 'Agent dispatch executor provider start driver preflight is blocked until the driver and observability storage exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorProviderStartDriverImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorProviderStartDriverPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_provider_start_driver_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_provider_start_driver_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create governed provider start driver service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorProviderStartDriver.php'],
                'acceptance' => 'Service exposes startProviderOnce and rejects missing used receipt, sandbox binding, authorization or observability prerequisites before any adapter invocation.',
            ],
            [
                'id' => 'T2',
                'title' => 'Implement pre-start runtime and observability guards',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorProviderStartDriver.php'],
                'acceptance' => 'Driver creates/updates run state and writes pre-start heartbeat before future adapter invocation, while remaining idempotent for the same attempt.',
            ],
            [
                'id' => 'T3',
                'title' => 'Write provider start driver tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorProviderStartDriverTest.php'],
                'acceptance' => 'Tests prove rejection paths, idempotency, pre-start heartbeat ordering and no self-merge/packet-completion side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Keep real adapter invocation behind signed release path',
                'type' => 'integration_gate',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Provider start driver readiness is visible, but command surfaces remain read-only and cannot start providers directly.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_provider_start_driver_implementation',
            'implementation_packet_id' => 'AGENT-DISPATCH-EXECUTOR-PROVIDER-START-DRIVER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the governed provider start driver boundary that validates receipt use, sandbox binding, authorization, runtime state, heartbeat and budget before any future adapter invocation.',
            'non_goals' => [
                'do_not_start_providers_from_command_surface',
                'do_not_implement_claude_or_gemini_adapters_yet',
                'do_not_self_merge_or_complete_packets',
                'do_not_bypass_receipt_use_or_sandbox_binding',
                'do_not_use_full_chat_history_as_context',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_provider_adapter_invocation_without_signed_release_path',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'driver_rejects_start_without_used_receipt',
                'driver_rejects_start_without_active_sandbox_binding',
                'driver_writes_pre_start_heartbeat_before_future_adapter_invocation',
                'driver_is_idempotent_for_same_provider_start_attempt',
                'driver_does_not_self_merge_or_complete_packets',
                'command_surfaces_still_do_not_start_providers',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_provider_from_command_surface',
                'need_to_modify_file_outside_allowed_files',
                'need_to_create_provider_specific_adapter_without_new_contract',
                'need_to_change_packet_claim_or_completion_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_provider_start_driver_implementation_packet.v1',
            'status' => 'ready_for_scoped_provider_start_driver_implementation',
            'mode' => 'read_only_agent_dispatch_executor_provider_start_driver_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_provider_start_driver_implementation_packet' => $packet,
            'dispatch_executor_provider_start_driver_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_provider_start_driver_implementation_packet_does_not_start_providers',
                'agent_dispatch_executor_provider_start_driver_implementation_packet_does_not_create_driver_files',
                'agent_dispatch_executor_provider_start_driver_implementation_packet_does_not_mark_receipts_used',
                'agent_dispatch_executor_provider_start_driver_implementation_packet_does_not_bind_workspace',
                'agent_dispatch_executor_provider_start_driver_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent dispatch executor provider start driver implementation packet is ready; it defines scoped driver work but does not create files or start providers.',
        ];
    }


}
