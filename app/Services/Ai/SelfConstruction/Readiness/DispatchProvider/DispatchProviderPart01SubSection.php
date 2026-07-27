<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\DispatchProvider;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Closure;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;

/**
 * GOD-DEBULK sub-split part 01 of {@see ReadinessProjectionAgentDispatchProviderSection}.
 *
 * Bodies moved VERBATIM from the facade (A1-SC-0056 dispatch-receipt guard
 * lives in part 01, byte-identical). The injected $stableHash closure keeps
 * every ($this->stableHash)(...) call unchanged, and __call routes every
 * sibling/mother back-call through the facade exactly as before.
 */
final class DispatchProviderPart01SubSection
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
    public function agentProviderAdapterInvocationRuntimePolicy(array $options = []): array
    {
        $controlPlanePayload = $this->agentControlPlane($options);
        $registryPayload = $this->agentProviderAdapterRegistryPreflight($options);
        $receiptUsePayload = $this->agentDispatchExecutorReceiptUseWriterPreflight($options);
        $sandboxPayload = $this->agentDispatchExecutorSandboxBindingPreflight($options);
        $providerStartPayload = $this->agentDispatchExecutorProviderStartDriverPreflight($options);
        $boundaryPayload = $this->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
        $guardPayload = $this->agentProviderAdapterExecutionGuardPreflight($options);

        $componentReadiness = [
            'agent_control_plane_runtime_schema' => data_get($controlPlanePayload, 'control_plane.persistent_runtime.status') === 'schema_ready',
            'provider_adapter_registry' => data_get($registryPayload, 'status') === 'provider_adapter_registry_ready',
            'dispatch_receipt_use_writer' => data_get($receiptUsePayload, 'status') === 'agent_dispatch_executor_receipt_use_writer_ready',
            'sandbox_binding' => data_get($sandboxPayload, 'status') === 'agent_dispatch_executor_sandbox_binding_ready',
            'provider_start_driver' => data_get($providerStartPayload, 'status') === 'agent_dispatch_executor_provider_start_driver_ready',
            'adapter_invocation_boundary' => data_get($boundaryPayload, 'status') === 'agent_dispatch_executor_adapter_invocation_boundary_ready',
            'provider_adapter_execution_guard' => data_get($guardPayload, 'status') === 'provider_adapter_execution_guard_ready',
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_provider_adapter_invocation_runtime_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-PROVIDER-ADAPTER-INVOCATION-RUNTIME-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'control_plane_hash' => data_get($controlPlanePayload, 'control_plane_hash'),
            'runtime_schema_status' => data_get($controlPlanePayload, 'control_plane.persistent_runtime.status'),
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'provider_adapter_registry' => data_get($registryPayload, 'provider_adapter_registry_preflight_hash'),
                'dispatch_receipt_use_writer' => data_get($receiptUsePayload, 'dispatch_executor_receipt_use_writer_preflight_hash'),
                'sandbox_binding' => data_get($sandboxPayload, 'dispatch_executor_sandbox_binding_preflight_hash'),
                'provider_start_driver' => data_get($providerStartPayload, 'dispatch_executor_provider_start_driver_preflight_hash'),
                'adapter_invocation_boundary' => data_get($boundaryPayload, 'dispatch_executor_adapter_invocation_boundary_preflight_hash'),
                'provider_adapter_execution_guard' => data_get($guardPayload, 'provider_adapter_execution_guard_preflight_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'runtime_stage_order' => [
                'signed_dispatch_receipt_must_exist',
                'dispatch_receipt_use_writer_marks_receipt_used_atomically',
                'sandbox_binding_must_exist',
                'provider_start_driver_prepares_pre_start_guarded_run',
                'pre_start_heartbeat_must_exist',
                'adapter_invocation_boundary_prepares_adapter_invocation',
                'provider_adapter_execution_guard_blocks_provider_specific_execution_until_next_contract',
                'provider_specific_process_start_requires_future_signed_release',
            ],
            'allowed_now' => [
                'readiness_projection',
                'runtime_policy_projection',
                'component_preflight_composition',
            ],
            'forbidden_now' => [
                'call_codex_claude_gemini_local_or_http_adapters',
                'spawn_provider_process',
                'spend_provider_tokens',
                'run_background_dispatch_scheduler',
                'mark_packets_complete_from_adapter_policy',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'adapter_call_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'provider_process_supervision_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_future_provider_process_supervision_policy' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_provider_process_supervision_policy'
                : 'repair_provider_adapter_invocation_runtime_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_adapter_invocation_runtime_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_provider_adapter_invocation_runtime_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'adapter_call_allowed' => false,
            'provider_start_allowed' => false,
            'provider_process_supervision_allowed' => false,
            'token_spend_allowed' => false,
            'agent_provider_adapter_invocation_runtime_policy' => $policy,
            'agent_provider_adapter_invocation_runtime_policy_hash' => ($this->stableHash)($policy),
            'non_execution_guarantees' => [
                'agent_provider_adapter_invocation_runtime_policy_does_not_start_providers',
                'agent_provider_adapter_invocation_runtime_policy_does_not_call_adapters',
                'agent_provider_adapter_invocation_runtime_policy_does_not_spend_tokens',
                'agent_provider_adapter_invocation_runtime_policy_does_not_dispatch_work',
                'agent_provider_adapter_invocation_runtime_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Provider adapter invocation runtime policy is ready: all prerequisite control-plane gates exist, but provider calls remain disabled until process supervision is separately released.'
                : 'Provider adapter invocation runtime policy is blocked until every prerequisite control-plane gate is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentProviderProcessSupervisionPolicy(array $options = []): array
    {
        $adapterPolicyPayload = $this->agentProviderAdapterInvocationRuntimePolicy($options);
        $spawnPayload = $this->agentCodexProcessSpawnExecutorPreflight($options);
        $runtimePayload = $this->agentCodexExternalProcessRuntimeDriverPreflight($options);
        $authorizationPayload = $this->agentCodexExternalProcessInvocationAuthorizationPreflight($options);
        $dryRunPayload = $this->agentCodexExternalProcessInvokerDryRunPreflight($options);

        $componentReadiness = [
            'provider_adapter_invocation_runtime_policy' => data_get($adapterPolicyPayload, 'status') === 'agent_provider_adapter_invocation_runtime_policy_ready',
            'codex_process_spawn_executor' => data_get($spawnPayload, 'status') === 'codex_process_spawn_executor_ready',
            'codex_external_process_runtime_driver' => data_get($runtimePayload, 'status') === 'codex_external_process_runtime_driver_ready',
            'codex_external_process_invocation_authorization' => data_get($authorizationPayload, 'status') === 'codex_external_process_invocation_authorization_ready',
            'codex_external_process_invoker_dry_run' => data_get($dryRunPayload, 'status') === 'codex_external_process_invoker_dry_run_ready',
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_provider_process_supervision_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-PROVIDER-PROCESS-SUPERVISION-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider_scope' => 'codex_first_then_provider_neutral',
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'provider_adapter_invocation_runtime_policy' => data_get($adapterPolicyPayload, 'agent_provider_adapter_invocation_runtime_policy_hash'),
                'codex_process_spawn_executor' => data_get($spawnPayload, 'codex_process_spawn_executor_preflight_hash'),
                'codex_external_process_runtime_driver' => data_get($runtimePayload, 'codex_external_process_runtime_driver_preflight_hash'),
                'codex_external_process_invocation_authorization' => data_get($authorizationPayload, 'codex_external_process_invocation_authorization_preflight_hash'),
                'codex_external_process_invoker_dry_run' => data_get($dryRunPayload, 'codex_external_process_invoker_dry_run_preflight_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'supervision_requirements' => [
                'signed_start_receipt',
                'runtime_supervision_plan_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'termination_policy_hash',
                'budget_policy_hash',
                'kill_switch_hash',
                'post_start_evidence_acceptance_bridge_id',
            ],
            'allowed_now' => [
                'process_supervision_policy_projection',
                'component_preflight_composition',
                'dry_run_contract_validation',
            ],
            'forbidden_now' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_from_policy_projection',
                'dispatch_work_to_provider',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'process_spawn_allowed_here' => false,
                'provider_process_supervision_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_future_signed_process_start_execution_gate' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_work_product_collection_policy'
                : 'repair_provider_process_supervision_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_process_supervision_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_provider_process_supervision_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'process_spawn_allowed' => false,
            'provider_process_supervision_allowed' => false,
            'codex_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_provider_process_supervision_policy' => $policy,
            'agent_provider_process_supervision_policy_hash' => ($this->stableHash)($policy),
            'non_execution_guarantees' => [
                'agent_provider_process_supervision_policy_does_not_start_providers',
                'agent_provider_process_supervision_policy_does_not_call_codex',
                'agent_provider_process_supervision_policy_does_not_spend_tokens',
                'agent_provider_process_supervision_policy_does_not_dispatch_work',
                'agent_provider_process_supervision_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Provider process supervision policy is ready: Codex process supervision prerequisites are composed, but actual process start remains disabled until a signed execution gate.'
                : 'Provider process supervision policy is blocked until every process supervision prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchPreflight(array $options = []): array
    {
        $adapterPayload = $this->agentAdapterContract($options);
        $adapterContract = (array) data_get($adapterPayload, 'adapter_contract', []);

        if (! Schema::hasTable('atlas_self_construction_agent_wakeup_items')) {
            $preflight = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_wakeup_queue_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'adapter_contract_hash' => data_get($adapterPayload, 'adapter_contract_hash'),
                'wakeup_items_table_ready' => false,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_preflight.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_dispatch_preflight',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_preflight' => $preflight,
                'dispatch_preflight_hash' => ($this->stableHash)($preflight),
                'human_summary' => 'Agent dispatch preflight is blocked until the Agent Control Plane wakeup queue table exists.',
            ];
        }

        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);
        $packetId = trim((string) ($options['packet'] ?? ''));

        $query = AtlasSelfConstructionAgentWakeupItem::query()
            ->where('status', 'claimed');

        if ($packetId !== '') {
            $query->where('packet_id', $packetId);
        }

        $claimed = $query
            ->orderByDesc('claimed_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $claimed instanceof AtlasSelfConstructionAgentWakeupItem) {
            $preflight = [
                'status' => 'blocked',
                'blocking_reasons' => ['no_claimed_wakeup_item'],
                'requested_packet_id' => $packetId === '' ? null : $packetId,
                'requested_by_actor' => $actor,
                'requested_by_session' => $session,
                'adapter_contract_hash' => data_get($adapterPayload, 'adapter_contract_hash'),
                'required_previous_command' => 'php artisan atlas:ai:self-construction --agent-wakeup-claim --actor='.$actor.' --session='.$session.' --json',
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_preflight.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_dispatch_preflight',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_preflight' => $preflight,
                'dispatch_preflight_hash' => ($this->stableHash)($preflight),
                'non_execution_guarantees' => [
                    'agent_dispatch_preflight_does_not_start_providers',
                    'agent_dispatch_preflight_does_not_claim_packets',
                    'agent_dispatch_preflight_does_not_release_packets',
                    'agent_dispatch_preflight_does_not_dispatch_work',
                ],
                'human_summary' => 'Agent dispatch preflight found no claimed wakeup item. Claim a wakeup item before dispatch can be prepared.',
            ];
        }

        $provider = (string) ($claimed->provider ?: $claimed->actor ?: $actor);
        $providerSpec = collect((array) data_get($adapterContract, 'providers', []))
            ->first(fn (array $providerContract): bool => (string) ($providerContract['provider'] ?? '') === $provider);

        if (! is_array($providerSpec)) {
            $providerSpec = $this->providerRoleForActor($provider);
        }

        $preflight = [
            'status' => 'ready_for_signed_dispatch_receipt',
            'dispatch_allowed_now' => false,
            'provider' => $provider,
            'adapter_id' => data_get($providerSpec, 'adapter_id', 'ADAPTER-UNKNOWN-SELF-CONSTRUCTION'),
            'provider_role' => data_get($providerSpec, 'role', 'implementation_worker'),
            'claimed_wakeup_item' => [
                'wakeup_item_id' => $claimed->id,
                'wakeup_key' => $claimed->wakeup_key,
                'run_id' => $claimed->agent_run_id,
                'packet_id' => $claimed->packet_id,
                'actor' => $claimed->actor,
                'provider' => $claimed->provider,
                'reason' => $claimed->reason,
                'priority' => $claimed->priority,
                'claimed_at' => $claimed->claimed_at?->toIso8601String(),
            ],
            'dispatch_envelope_draft' => [
                'operation_id' => 'OP-SELF-CONSTRUCTION-DISPATCH-'.strtoupper(substr(hash('sha256', (string) $claimed->wakeup_key), 0, 16)),
                'wakeup_key' => $claimed->wakeup_key,
                'run_id' => $claimed->agent_run_id,
                'packet_id' => $claimed->packet_id,
                'actor' => $claimed->actor ?: $actor,
                'session_id' => $session,
                'provider' => $provider,
                'provider_role' => data_get($providerSpec, 'role', 'implementation_worker'),
                'adapter_contract_hash' => data_get($adapterPayload, 'adapter_contract_hash'),
                'required_inputs' => data_get($providerSpec, 'required_inputs', []),
                'required_outputs' => data_get($providerSpec, 'required_outputs', []),
            ],
            'required_before_dispatch' => [
                'signed_dispatch_receipt',
                'provider_runtime_adapter',
                'budget_policy_check',
                'scope_validator_passed',
                'context_pack_hash',
                'evidence_capture_policy',
            ],
            'forbidden_actions' => array_values(array_unique(array_merge(
                (array) data_get($providerSpec, 'forbidden_actions', []),
                [
                    'start_provider_from_preflight',
                    'dispatch_without_signed_receipt',
                    'mutate_packet_ledger_from_preflight',
                ],
            ))),
            'policy' => [
                'preflight_is_read_only' => true,
                'provider_dispatch_requires_signed_receipt' => true,
                'does_not_start_providers' => true,
                'does_not_claim_or_release_packets' => true,
                'does_not_mutate_wakeup_item' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_preflight.v1',
            'status' => 'agent_dispatch_preflight_ready',
            'mode' => 'read_only_agent_dispatch_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_preflight' => $preflight,
            'dispatch_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_preflight_does_not_start_providers',
                'agent_dispatch_preflight_does_not_claim_packets',
                'agent_dispatch_preflight_does_not_release_packets',
                'agent_dispatch_preflight_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent dispatch preflight is ready: Atlas drafted the provider dispatch envelope but still requires a signed dispatch receipt before any provider can start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchReceiptTemplate(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_preflight', []);
        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);

        $template = [
            'receipt_template_id' => 'DISPATCH-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => data_get($preflightPayload, 'status') === 'agent_dispatch_preflight_ready'
                ? 'ready_for_human_signature'
                : 'blocked_until_preflight_ready',
            'parent_schema_version' => data_get($preflightPayload, 'schema_version'),
            'preflight_hash' => data_get($preflightPayload, 'dispatch_preflight_hash'),
            'requested_by_actor' => $actor,
            'requested_by_session' => $session,
            'dispatch_allowed_by_template' => false,
            'signature_required' => true,
            'signature_status' => 'unsigned_template_only',
            'receipt_fields_to_sign' => [
                'receipt_id',
                'signed_by',
                'signed_at',
                'decision',
                'dispatch_envelope_hash',
                'adapter_contract_hash',
                'provider',
                'provider_role',
                'packet_id',
                'wakeup_key',
                'budget_policy_result',
                'scope_validator_result',
                'context_pack_hash',
                'evidence_capture_policy_hash',
                'rollback_policy',
                'expiry_at',
            ],
            'allowed_decisions' => [
                'approve_dispatch_once',
                'reject_dispatch',
                'request_more_evidence',
            ],
            'minimum_evidence_before_signature' => [
                'dispatch_preflight_hash',
                'claimed_wakeup_item',
                'adapter_contract_hash',
                'scope_validator_passed',
                'budget_policy_passed',
                'provider_runtime_adapter_ready',
                'evidence_capture_policy_ready',
            ],
            'draft_receipt' => [
                'receipt_id' => 'DISPATCH-RECEIPT-'.strtoupper(substr(hash('sha256', (string) data_get($preflight, 'dispatch_envelope_draft.operation_id', 'blocked')), 0, 16)),
                'decision' => 'unsigned',
                'dispatch_envelope_hash' => ($this->stableHash)((array) data_get($preflight, 'dispatch_envelope_draft', [])),
                'adapter_contract_hash' => data_get($preflight, 'dispatch_envelope_draft.adapter_contract_hash'),
                'provider' => data_get($preflight, 'provider'),
                'provider_role' => data_get($preflight, 'provider_role'),
                'packet_id' => data_get($preflight, 'claimed_wakeup_item.packet_id'),
                'wakeup_key' => data_get($preflight, 'claimed_wakeup_item.wakeup_key'),
                'expiry_policy' => 'single_use_short_lived_receipt_required',
                'rollback_policy' => 'stop_provider_and_mark_wakeup_claim_for_review_on_failure',
            ],
            'blocked_preflight' => data_get($preflightPayload, 'status') === 'agent_dispatch_preflight_ready' ? null : $preflight,
            'policy' => [
                'template_is_read_only' => true,
                'template_does_not_sign_receipt' => true,
                'template_does_not_start_providers' => true,
                'template_does_not_dispatch_work' => true,
                'signed_receipt_persistence_requires_future_writer' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_receipt_template.v1',
            'status' => data_get($preflightPayload, 'status') === 'agent_dispatch_preflight_ready'
                ? 'agent_dispatch_receipt_template_ready'
                : 'blocked',
            'mode' => 'read_only_agent_dispatch_receipt_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_receipt_template' => $template,
            'dispatch_receipt_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_receipt_template_does_not_start_providers',
                'agent_dispatch_receipt_template_does_not_claim_packets',
                'agent_dispatch_receipt_template_does_not_release_packets',
                'agent_dispatch_receipt_template_does_not_dispatch_work',
                'agent_dispatch_receipt_template_does_not_sign_receipt',
            ],
            'human_summary' => data_get($preflightPayload, 'status') === 'agent_dispatch_preflight_ready'
                ? 'Agent dispatch receipt template is ready for human signature, but it does not sign or dispatch provider work.'
                : 'Agent dispatch receipt template is blocked until dispatch preflight is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchReceiptValidationPreflight(array $options = []): array
    {
        $templatePayload = $this->agentDispatchReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'dispatch_receipt_template', []);
        $dispatchReceiptTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $requiredChecks = [
            'dispatch_receipt_storage_schema',
            'signed_receipt_payload',
            'signature_identity_policy',
            'receipt_hash_matches_template',
            'dispatch_envelope_hash_matches_preflight',
            'budget_policy_passed',
            'scope_validator_passed',
            'provider_runtime_adapter_ready',
            'evidence_capture_policy_ready',
            'single_use_expiry_policy',
            'rollback_policy_ready',
        ];
        $availableChecks = [
            'dispatch_receipt_storage_schema' => $dispatchReceiptTableReady,
            'dispatch_receipt_template_hash' => data_get($templatePayload, 'dispatch_receipt_template_hash') !== null,
            'template_requires_signature' => (bool) data_get($template, 'signature_required'),
            'template_does_not_dispatch' => data_get($template, 'dispatch_allowed_by_template') === false,
            'receipt_field_contract_present' => count((array) data_get($template, 'receipt_fields_to_sign', [])) > 0,
        ];
        $missingRequirements = array_values(array_filter(
            $requiredChecks,
            fn (string $check): bool => ! (bool) ($availableChecks[$check] ?? false),
        ));

        $preflight = [
            'status' => 'blocked',
            'blocking_reasons' => array_values(array_filter([
                $dispatchReceiptTableReady ? null : 'signed_dispatch_receipt_persistence_schema_missing',
                'signed_dispatch_receipt_payload_not_supplied',
                'provider_dispatch_still_requires_future_signed_policy',
            ])),
            'parent_schema_version' => data_get($templatePayload, 'schema_version'),
            'dispatch_receipt_template_hash' => data_get($templatePayload, 'dispatch_receipt_template_hash'),
            'counts' => [
                'required_checks' => count($requiredChecks),
                'available_checks' => count(array_filter($availableChecks)),
                'missing_requirements' => count($missingRequirements),
            ],
            'available_checks' => $availableChecks,
            'missing_requirements' => $missingRequirements,
            'validation_contract' => [
                'must_verify_signature_identity' => true,
                'must_verify_receipt_hash_against_template' => true,
                'must_verify_dispatch_envelope_hash_against_preflight' => true,
                'must_verify_budget_policy_result' => true,
                'must_verify_scope_validator_result' => true,
                'must_verify_receipt_not_expired' => true,
                'must_verify_single_use_receipt' => true,
                'must_persist_append_only_event_before_dispatch' => true,
            ],
            'future_persistence_record' => [
                'table' => 'atlas_self_construction_agent_dispatch_receipts',
                'table_ready' => $dispatchReceiptTableReady,
                'required_columns' => [
                    'id',
                    'receipt_key',
                    'wakeup_item_id',
                    'agent_run_id',
                    'packet_id',
                    'provider',
                    'decision',
                    'signed_by',
                    'signed_at',
                    'expires_at',
                    'dispatch_envelope_hash',
                    'receipt_hash',
                    'status',
                    'payload',
                ],
            ],
            'policy' => [
                'preflight_is_read_only' => true,
                'preflight_does_not_validate_external_signatures_yet' => true,
                'preflight_does_not_persist_receipt' => true,
                'preflight_does_not_start_providers' => true,
                'preflight_does_not_dispatch_work' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_receipt_validation_preflight.v1',
            'status' => 'blocked',
            'mode' => 'read_only_agent_dispatch_receipt_validation_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_receipt_validation_preflight' => $preflight,
            'dispatch_receipt_validation_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_receipt_validation_preflight_does_not_start_providers',
                'agent_dispatch_receipt_validation_preflight_does_not_claim_packets',
                'agent_dispatch_receipt_validation_preflight_does_not_release_packets',
                'agent_dispatch_receipt_validation_preflight_does_not_dispatch_work',
                'agent_dispatch_receipt_validation_preflight_does_not_persist_receipt',
            ],
            'human_summary' => 'Agent dispatch receipt validation preflight is blocked by design until signed receipt persistence schema and signed receipt payload validation exist.',
        ];
    }


    /**
     * Single-use dispatch receipts are short-lived by policy (template contract:
     * single_use_short_lived_receipt_required). A future signed-policy layer may tune this; ponytail: raise
     * the ceiling here if a legitimate signer ever needs a longer window.
     */
    private const SIGNED_DISPATCH_RECEIPT_MAX_TTL_SECONDS = 3600;

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, decision?: string|null, signed_by?: string|null, receipt_hash?: string|null, dispatch_envelope_hash?: string|null, adapter_contract_hash?: string|null, expires_at?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchReceiptWrite(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_preflight', []);

        if (! Schema::hasTable('atlas_self_construction_agent_dispatch_receipts')) {
            $write = [
                'status' => 'blocked',
                'blocking_reasons' => ['signed_dispatch_receipt_persistence_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'dispatch_receipts_table_ready' => false,
                'preflight_status' => data_get($preflightPayload, 'status'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_receipt_write.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_dispatch_receipt_writer',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_receipt_write' => $write,
                'dispatch_receipt_write_hash' => ($this->stableHash)($write),
                'human_summary' => 'Agent dispatch receipt writer is blocked until the signed dispatch receipt table exists.',
            ];
        }

        $decision = trim((string) ($options['decision'] ?? ''));
        $signedBy = trim((string) ($options['signed_by'] ?? ''));
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $dispatchEnvelopeHash = strtolower(trim((string) ($options['dispatch_envelope_hash'] ?? '')));
        $adapterContractHash = strtolower(trim((string) ($options['adapter_contract_hash'] ?? '')));
        $expiresAt = trim((string) ($options['expires_at'] ?? ''));
        $allowedDecisions = ['approve_dispatch_once', 'reject_dispatch', 'request_more_evidence'];
        $missing = array_values(array_filter([
            data_get($preflightPayload, 'status') === 'agent_dispatch_preflight_ready' ? null : 'dispatch_preflight_ready',
            in_array($decision, $allowedDecisions, true) ? null : 'valid_decision',
            $signedBy !== '' ? null : 'signed_by',
            preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1 ? null : 'receipt_hash',
            preg_match('/^[a-f0-9]{64}$/', $dispatchEnvelopeHash) === 1 ? null : 'dispatch_envelope_hash',
            $expiresAt !== '' ? null : 'expires_at',
        ]));

        if ($missing !== []) {
            $write = [
                'status' => 'blocked',
                'blocking_reasons' => ['signed_dispatch_receipt_payload_incomplete'],
                'missing_requirements' => $missing,
                'allowed_decisions' => $allowedDecisions,
                'preflight_status' => data_get($preflightPayload, 'status'),
                'dispatch_receipts_table_ready' => true,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_receipt_write.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_dispatch_receipt_writer',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_receipt_write' => $write,
                'dispatch_receipt_write_hash' => ($this->stableHash)($write),
                'non_execution_guarantees' => [
                    'agent_dispatch_receipt_write_does_not_start_providers',
                    'agent_dispatch_receipt_write_does_not_claim_packets',
                    'agent_dispatch_receipt_write_does_not_release_packets',
                    'agent_dispatch_receipt_write_does_not_dispatch_work',
                ],
                'human_summary' => 'Agent dispatch receipt writer is blocked until a complete signed dispatch receipt payload is supplied.',
            ];
        }

        // SECURITY (A1-SC-0056) — the shape checks above are necessary but NOT sufficient: a caller could
        // still mint a signed_pending_dispatch authority row from arbitrary 64-hex text with a past/garbage
        // expiry. Before persisting, BIND the receipt to the server's canonical dispatch envelope (recomputed
        // from THIS preflight — the same value the template exposes at draft_receipt.dispatch_envelope_hash)
        // and prove a real, single-use, FUTURE expiry. This is the implementable core of the validation-preflight
        // contract (envelope-hash-against-preflight + receipt-not-expired + single-use TTL). Signer-identity
        // verification remains the declared future signed-policy layer (SelfConstructionReadiness blueprint) —
        // not faked here. Fail-closed: any unbound envelope or non-future expiry blocks the write.
        $canonicalEnvelopeHash = ($this->stableHash)((array) data_get($preflight, 'dispatch_envelope_draft', []));
        $expiresAtInstant = $this->parseFutureSingleUseExpiry($expiresAt);
        $integrityFailures = array_values(array_filter([
            hash_equals($canonicalEnvelopeHash, $dispatchEnvelopeHash) ? null : 'dispatch_envelope_hash_not_bound_to_preflight',
            $expiresAtInstant !== null ? null : 'receipt_expiry_not_a_future_single_use_instant',
        ]));

        if ($integrityFailures !== []) {
            $write = [
                'status' => 'blocked',
                'blocking_reasons' => ['signed_dispatch_receipt_integrity_unverified'],
                'integrity_failures' => $integrityFailures,
                'expected_dispatch_envelope_hash' => $canonicalEnvelopeHash,
                'max_receipt_ttl_seconds' => self::SIGNED_DISPATCH_RECEIPT_MAX_TTL_SECONDS,
                'preflight_status' => data_get($preflightPayload, 'status'),
                'dispatch_receipts_table_ready' => true,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_dispatch_receipt_write.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_dispatch_receipt_writer',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'dispatch_receipt_write' => $write,
                'dispatch_receipt_write_hash' => ($this->stableHash)($write),
                'non_execution_guarantees' => [
                    'agent_dispatch_receipt_write_does_not_start_providers',
                    'agent_dispatch_receipt_write_does_not_claim_packets',
                    'agent_dispatch_receipt_write_does_not_release_packets',
                    'agent_dispatch_receipt_write_does_not_dispatch_work',
                ],
                'human_summary' => 'Agent dispatch receipt writer refused: the signed receipt is not bound to the server dispatch envelope or its single-use expiry is not in the future.',
            ];
        }

        $claimedWakeup = (array) data_get($preflight, 'claimed_wakeup_item', []);
        // Row identity is SERVER-derived from the canonical envelope (not caller-supplied receipt_hash):
        // one signed dispatch authority per operation, and a caller cannot pick or overwrite an arbitrary
        // receipt row by choosing its receipt_hash prefix (A1-SC-0056 hardening).
        $receiptKey = 'DISPATCH-RECEIPT-'.strtoupper(substr($canonicalEnvelopeHash, 0, 24));
        $model = AtlasSelfConstructionAgentDispatchReceipt::query()->updateOrCreate(
            ['receipt_key' => $receiptKey],
            [
                'agent_run_id' => data_get($claimedWakeup, 'run_id'),
                'wakeup_item_id' => data_get($claimedWakeup, 'wakeup_item_id'),
                'packet_id' => data_get($claimedWakeup, 'packet_id'),
                'provider' => (string) data_get($preflight, 'provider', 'unknown'),
                'provider_role' => data_get($preflight, 'provider_role'),
                'decision' => $decision,
                'status' => $decision === 'approve_dispatch_once' ? 'signed_pending_dispatch' : 'signed_no_dispatch',
                'signed_by' => $signedBy,
                'signed_at' => now(),
                'expires_at' => $expiresAtInstant,
                'dispatch_envelope_hash' => $dispatchEnvelopeHash,
                'adapter_contract_hash' => $adapterContractHash === '' ? null : $adapterContractHash,
                'receipt_hash' => $receiptHash,
                'payload' => [
                    'source' => 'agent_dispatch_receipt_write',
                    'dispatch_preflight_hash' => data_get($preflightPayload, 'dispatch_preflight_hash'),
                    'dispatch_allowed_by_writer' => false,
                    'provider_dispatch_requires_separate_executor' => true,
                ],
            ],
        );

        $write = [
            'status' => 'written',
            'receipt_id' => $model->id,
            'receipt_key' => $model->receipt_key,
            'decision' => $model->decision,
            'receipt_status' => $model->status,
            'packet_id' => $model->packet_id,
            'provider' => $model->provider,
            'signed_by' => $model->signed_by,
            'signed_at' => $model->signed_at?->toIso8601String(),
            'expires_at' => $model->expires_at?->toIso8601String(),
            'created' => $model->wasRecentlyCreated,
            'dispatch_allowed_after_write' => false,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_receipt_write.v1',
            'status' => 'agent_dispatch_receipt_write_ready',
            'mode' => 'controlled_agent_dispatch_receipt_writer',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'dispatch_receipt_write' => $write,
            'dispatch_receipt_write_hash' => ($this->stableHash)($write),
            'non_execution_guarantees' => [
                'agent_dispatch_receipt_write_does_not_start_providers',
                'agent_dispatch_receipt_write_does_not_claim_packets',
                'agent_dispatch_receipt_write_does_not_release_packets',
                'agent_dispatch_receipt_write_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent dispatch receipt writer persisted a signed receipt without starting providers or dispatching work.',
        ];
    }

    /**
     * Parse a caller-supplied receipt expiry, returning the instant ONLY when it is a valid timestamp
     * strictly in the FUTURE and within the single-use short-lived TTL ceiling. Returns null (fail-closed)
     * for anything unparseable, past, now, or beyond the ceiling — so a garbage or already-expired string
     * can never mint dispatch authority.
     */
    private function parseFutureSingleUseExpiry(string $raw): ?CarbonImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            $instant = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        $now = CarbonImmutable::now();
        if ($instant->lessThanOrEqualTo($now)
            || $instant->greaterThan($now->addSeconds(self::SIGNED_DISPATCH_RECEIPT_MAX_TTL_SECONDS))) {
            return null;
        }

        return $instant;
    }


}
