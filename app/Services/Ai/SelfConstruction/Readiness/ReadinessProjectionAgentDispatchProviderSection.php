<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentCostEvent;
use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Models\AtlasSelfConstructionAgentWorkProduct;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReleaseAuthorizationPersistenceWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorSandboxBindingWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Family 5 — Agent Dispatch + Provider.
 * Bodies extracted from pre-split mother (Obra 3 SC-02). Mother helpers via __call.
 */
final class ReadinessProjectionAgentDispatchProviderSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    /**
     * @param  Closure(array<string,mixed>): string  $stableHash
     */
    public function __construct(
        private readonly Closure $stableHash,
    ) {}

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException("ReadinessProjectionAgentDispatchProviderSection mother not bound for {$name}.");
        }

        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
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


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentProviderAdapterRegistryContractTemplate(array $options = []): array
    {
        $adapterContractPayload = $this->agentAdapterContract($options);
        $registryClass = AgentProviderAdapterRegistry::class;
        $projection = class_exists($registryClass)
            ? app($registryClass)->projection()
            : [
                'status' => 'provider_adapter_registry_missing',
                'registry_id' => 'AGENT-PROVIDER-ADAPTER-REGISTRY-SELF-CONSTRUCTION-0001',
                'provider_count' => 0,
                'providers' => [],
            ];

        $template = [
            'status' => 'provider_adapter_registry_contract_template_ready',
            'contract_id' => 'PROVIDER-ADAPTER-REGISTRY-'.strtoupper(substr(($this->stableHash)([
                'adapter_contract_hash' => data_get($adapterContractPayload, 'adapter_contract_hash'),
                'registry_id' => data_get($projection, 'registry_id'),
            ]), 0, 24)),
            'source_agent_adapter_contract_hash' => data_get($adapterContractPayload, 'adapter_contract_hash'),
            'registry_id' => data_get($projection, 'registry_id'),
            'provider_count' => data_get($projection, 'provider_count'),
            'providers' => data_get($projection, 'providers'),
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentProviderAdapterRegistry',
                'methods' => ['projection', 'resolve', 'descriptorHash'],
                'resolve_input_contract' => ['provider', 'adapter'],
                'resolve_result_contract' => [
                    'provider',
                    'adapter',
                    'adapter_id',
                    'role',
                    'supported_invocation_modes',
                    'required_context',
                    'required_outputs',
                    'forbidden_capabilities',
                    'external_process_start_enabled',
                    'token_spend_enabled',
                ],
            ],
            'registry_must' => [
                'declare_every_supported_provider_adapter_pair',
                'reject_unknown_provider',
                'reject_adapter_mismatch',
                'keep_external_process_start_disabled_by_default',
                'keep_token_spend_disabled_by_default',
                'make_provider_specific_execution_depend_on_future_contract',
            ],
            'registry_must_not' => [
                'start_provider_processes',
                'call_codex_claude_gemini_local_or_http',
                'read_secrets',
                'mutate_packet_state',
                'write_runtime_state',
                'change_policy_at_runtime',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentProviderAdapterRegistry.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentProviderAdapterRegistryTest.php',
                'app/Services/Ai/SelfConstruction/AgentDispatchExecutorAdapterInvocationBoundary.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorAdapterInvocationBoundaryTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'registry_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-provider-adapter-registry-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_adapter_registry_contract_template.v1',
            'status' => 'provider_adapter_registry_contract_template_ready',
            'mode' => 'read_only_agent_provider_adapter_registry_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_registry_contract_template' => $template,
            'provider_adapter_registry_contract_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_provider_adapter_registry_contract_template_does_not_start_providers',
                'agent_provider_adapter_registry_contract_template_does_not_call_adapters',
                'agent_provider_adapter_registry_contract_template_does_not_spend_tokens',
                'agent_provider_adapter_registry_contract_template_does_not_create_registry_files',
                'agent_provider_adapter_registry_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent provider adapter registry contract template defines canonical provider adapters, but does not call providers or create registry files.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentProviderAdapterRegistryPreflight(array $options = []): array
    {
        $contractPayload = $this->agentProviderAdapterRegistryContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'provider_adapter_registry_contract_template', []);
        $registryClass = AgentProviderAdapterRegistry::class;
        $registryReady = class_exists($registryClass);
        $providers = (array) data_get($contract, 'providers', []);
        $providersByName = array_values(array_filter(array_map(
            static fn (mixed $provider): string => is_array($provider) ? (string) ($provider['provider'] ?? '') : '',
            $providers
        )));
        $requiredProviders = ['codex', 'claude', 'gemini', 'local', 'http'];
        $missingProviders = array_values(array_diff($requiredProviders, $providersByName));

        $blockingReasons = array_values(array_filter(array_merge([
            $registryReady ? null : 'provider_adapter_registry_missing',
        ], array_map(
            static fn (string $provider): string => 'provider_adapter_missing_'.$provider,
            $missingProviders
        ))));

        $preflight = [
            'status' => $blockingReasons === [] ? 'provider_adapter_registry_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'provider_adapter_registry_contract_template_hash'),
            'registry_id' => data_get($contract, 'registry_id'),
            'provider_count' => count($providers),
            'required_providers' => $requiredProviders,
            'missing_providers' => $missingProviders,
            'storage' => [
                'provider_adapter_registry_ready' => $registryReady,
                'provider_descriptors_are_code_static' => true,
                'runtime_table_required' => false,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'registry_ready_for_future_release' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_provider_adapter_registry_service',
                'declare_codex_claude_gemini_local_http_descriptors',
                'disable_external_process_start_and_token_spend_by_default',
                'wire_adapter_invocation_boundary_to_registry_resolution',
                'reject_unknown_provider_or_adapter_mismatch_before_boundary_preparation',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentProviderAdapterRegistry.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentProviderAdapterRegistryTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorAdapterInvocationBoundaryTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_provider_adapter_registry',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'registry_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-provider-adapter-registry-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_adapter_registry_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_provider_adapter_registry_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_registry_preflight' => $preflight,
            'provider_adapter_registry_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_provider_adapter_registry_preflight_does_not_start_providers',
                'agent_provider_adapter_registry_preflight_does_not_call_adapters',
                'agent_provider_adapter_registry_preflight_does_not_spend_tokens',
                'agent_provider_adapter_registry_preflight_does_not_create_registry_files',
                'agent_provider_adapter_registry_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent provider adapter registry preflight is ready; provider adapters are declared, but no provider can be called from this command.'
                : 'Agent provider adapter registry preflight is blocked until every required adapter descriptor exists.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentProviderAdapterRegistryImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentProviderAdapterRegistryPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'provider_adapter_registry_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'provider_adapter_registry_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create provider adapter registry service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentProviderAdapterRegistry.php'],
                'acceptance' => 'Registry exposes projection, resolve and descriptorHash for Codex, Claude, Gemini, local and HTTP adapters without invoking providers.',
            ],
            [
                'id' => 'T2',
                'title' => 'Wire adapter boundary to registry resolution',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorAdapterInvocationBoundary.php'],
                'acceptance' => 'Boundary rejects unknown provider/adapter pairs and stores adapter_id plus descriptor hash when preparation succeeds.',
            ],
            [
                'id' => 'T3',
                'title' => 'Write registry and boundary tests',
                'type' => 'test',
                'allowed_files' => [
                    'tests/Feature/Ai/AtlasAiSelfConstructionAgentProviderAdapterRegistryTest.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorAdapterInvocationBoundaryTest.php',
                ],
                'acceptance' => 'Tests prove canonical descriptors, mismatch rejection, unknown provider rejection and no provider/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose registry readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands are read-only and declare provider process start and token spend disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_provider_adapter_registry_implementation',
            'implementation_packet_id' => 'AGENT-PROVIDER-ADAPTER-REGISTRY-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the canonical provider adapter registry so Atlas can resolve provider-specific adapter identity before any future external invocation.',
            'non_goals' => [
                'do_not_call_codex_claude_gemini_local_or_http_adapters',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_enable_provider_specific_execution',
                'do_not_change_packet_claim_or_completion_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_provider_process_invocation',
                'provider_specific_adapter_execution',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'registry_declares_codex_claude_gemini_local_http',
                'registry_rejects_unknown_provider',
                'registry_rejects_provider_adapter_mismatch',
                'boundary_records_adapter_id_and_descriptor_hash',
                'registry_and_boundary_do_not_start_provider_or_spend_tokens',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_provider_or_spawn_process',
                'need_to_modify_file_outside_allowed_files',
                'need_to_create_provider_specific_execution_adapter_without_new_contract',
                'need_to_change_packet_claim_or_completion_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_process_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_adapter_registry_implementation_packet.v1',
            'status' => 'ready_for_scoped_provider_adapter_registry_implementation',
            'mode' => 'read_only_agent_provider_adapter_registry_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_registry_implementation_packet' => $packet,
            'provider_adapter_registry_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_provider_adapter_registry_implementation_packet_does_not_start_providers',
                'agent_provider_adapter_registry_implementation_packet_does_not_call_adapters',
                'agent_provider_adapter_registry_implementation_packet_does_not_spend_tokens',
                'agent_provider_adapter_registry_implementation_packet_does_not_create_registry_files',
                'agent_provider_adapter_registry_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent provider adapter registry implementation packet is ready; it defines scoped registry work but does not create files, call adapters or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentProviderAdapterExecutionGuardContractTemplate(array $options = []): array
    {
        $registryPayload = $this->agentProviderAdapterRegistryPreflight($options);
        $boundaryPayload = $this->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);

        $template = [
            'status' => 'provider_adapter_execution_guard_contract_template_ready',
            'contract_id' => 'PROVIDER-ADAPTER-EXECUTION-GUARD-'.strtoupper(substr(($this->stableHash)([
                'registry_preflight_hash' => data_get($registryPayload, 'provider_adapter_registry_preflight_hash'),
                'boundary_preflight_hash' => data_get($boundaryPayload, 'dispatch_executor_adapter_invocation_boundary_preflight_hash'),
            ]), 0, 24)),
            'source_provider_adapter_registry_status' => data_get($registryPayload, 'status'),
            'source_provider_adapter_registry_preflight_hash' => data_get($registryPayload, 'provider_adapter_registry_preflight_hash'),
            'source_adapter_invocation_boundary_status' => data_get($boundaryPayload, 'status'),
            'source_adapter_invocation_boundary_preflight_hash' => data_get($boundaryPayload, 'dispatch_executor_adapter_invocation_boundary_preflight_hash'),
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentProviderAdapterExecutionGuard',
                'method' => 'blockUntilProviderSpecificContract',
                'input_contract' => [
                    'run_key',
                    'execution_guard_id',
                    'adapter_invocation_id',
                    'provider',
                    'adapter',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'execution_guard_id',
                    'adapter_invocation_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'blocked_by',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'guard_must' => [
                'require_agent_run_status_adapter_invocation_prepared',
                'require_adapter_invocation_metadata_matches_input',
                'resolve_provider_adapter_pair_from_registry',
                'verify_adapter_descriptor_hash_matches_prepared_boundary',
                'record_blocking_ledger_event',
                'keep_external_process_start_and_token_spend_false',
            ],
            'guard_must_not' => [
                'start_provider_processes',
                'call_codex_claude_gemini_local_or_http',
                'spend_provider_tokens',
                'mark_run_terminal',
                'complete_or_merge_packet',
                'grant_provider_specific_execution_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentProviderAdapterExecutionGuard.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentProviderAdapterExecutionGuardTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'guard_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-provider-adapter-execution-guard-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_adapter_execution_guard_contract_template.v1',
            'status' => 'provider_adapter_execution_guard_contract_template_ready',
            'mode' => 'read_only_agent_provider_adapter_execution_guard_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_execution_guard_contract_template' => $template,
            'provider_adapter_execution_guard_contract_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_provider_adapter_execution_guard_contract_template_does_not_start_providers',
                'agent_provider_adapter_execution_guard_contract_template_does_not_call_adapters',
                'agent_provider_adapter_execution_guard_contract_template_does_not_spend_tokens',
                'agent_provider_adapter_execution_guard_contract_template_does_not_create_guard_files',
                'agent_provider_adapter_execution_guard_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent provider adapter execution guard contract template defines the final no-execution tripwire before provider-specific adapter contracts.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentProviderAdapterExecutionGuardPreflight(array $options = []): array
    {
        $contractPayload = $this->agentProviderAdapterExecutionGuardContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'provider_adapter_execution_guard_contract_template', []);
        $guardClass = AgentProviderAdapterExecutionGuard::class;
        $guardReady = class_exists($guardClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $guardReady ? null : 'provider_adapter_execution_guard_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'provider_adapter_execution_guard_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'provider_adapter_execution_guard_contract_template_hash'),
            'source_provider_adapter_registry_status' => data_get($contract, 'source_provider_adapter_registry_status'),
            'source_adapter_invocation_boundary_status' => data_get($contract, 'source_adapter_invocation_boundary_status'),
            'storage' => [
                'provider_adapter_execution_guard_ready' => $guardReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'execution_guard_ready_for_future_release' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_provider_adapter_execution_guard_service',
                'require_adapter_invocation_prepared_run_before_execution_guard',
                'verify_registry_descriptor_hash_before_blocking_event',
                'record_provider_execution_blocked_ledger_event',
                'keep_provider_specific_execution_for_future_contract',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentProviderAdapterExecutionGuard.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentProviderAdapterExecutionGuardTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_provider_adapter_execution_guard',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'guard_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-provider-adapter-execution-guard-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_adapter_execution_guard_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_provider_adapter_execution_guard_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_execution_guard_preflight' => $preflight,
            'provider_adapter_execution_guard_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_provider_adapter_execution_guard_preflight_does_not_start_providers',
                'agent_provider_adapter_execution_guard_preflight_does_not_call_adapters',
                'agent_provider_adapter_execution_guard_preflight_does_not_spend_tokens',
                'agent_provider_adapter_execution_guard_preflight_does_not_create_guard_files',
                'agent_provider_adapter_execution_guard_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent provider adapter execution guard preflight is ready; provider execution remains blocked until a provider-specific execution contract exists.'
                : 'Agent provider adapter execution guard preflight is blocked until guard service and storage exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentProviderAdapterExecutionGuardImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentProviderAdapterExecutionGuardPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'provider_adapter_execution_guard_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'provider_adapter_execution_guard_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create provider adapter execution guard service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentProviderAdapterExecutionGuard.php'],
                'acceptance' => 'Guard validates adapter_invocation_prepared runs and records an execution-blocked ledger event without starting providers.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce registry descriptor hash before execution',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentProviderAdapterExecutionGuard.php'],
                'acceptance' => 'Guard resolves provider/adapter through registry and rejects descriptor hash mismatch before writing metadata.',
            ],
            [
                'id' => 'T3',
                'title' => 'Write execution guard tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentProviderAdapterExecutionGuardTest.php'],
                'acceptance' => 'Tests prove blocking behavior, idempotency, mismatch rejection, rollback and no provider/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose execution guard readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands are read-only and declare provider execution disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_provider_adapter_execution_guard_implementation',
            'implementation_packet_id' => 'AGENT-PROVIDER-ADAPTER-EXECUTION-GUARD-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the provider adapter execution guard that records a governed block before any provider-specific adapter execution can exist.',
            'non_goals' => [
                'do_not_call_codex_claude_gemini_local_or_http_adapters',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_grant_provider_specific_execution_authority',
                'do_not_change_packet_claim_or_completion_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_provider_process_invocation',
                'provider_specific_adapter_execution',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'guard_blocks_provider_execution_without_starting_provider',
                'guard_is_idempotent_for_same_guard_id',
                'guard_rejects_run_not_adapter_invocation_prepared',
                'guard_rejects_adapter_invocation_or_descriptor_mismatch',
                'guard_rolls_back_metadata_when_ledger_write_fails',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_provider_or_spawn_process',
                'need_to_modify_file_outside_allowed_files',
                'need_to_grant_provider_specific_execution_authority_without_new_contract',
                'need_to_change_packet_claim_or_completion_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_process_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_provider_adapter_execution_guard_implementation_packet.v1',
            'status' => 'ready_for_scoped_provider_adapter_execution_guard_implementation',
            'mode' => 'read_only_agent_provider_adapter_execution_guard_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_execution_guard_implementation_packet' => $packet,
            'provider_adapter_execution_guard_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_provider_adapter_execution_guard_implementation_packet_does_not_start_providers',
                'agent_provider_adapter_execution_guard_implementation_packet_does_not_call_adapters',
                'agent_provider_adapter_execution_guard_implementation_packet_does_not_spend_tokens',
                'agent_provider_adapter_execution_guard_implementation_packet_does_not_create_guard_files',
                'agent_provider_adapter_execution_guard_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent provider adapter execution guard implementation packet is ready; it defines scoped guard work but does not create files, call adapters or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorAdapterInvocationBoundaryContractTemplate(array $options = []): array
    {
        $providerStartPayload = $this->agentDispatchExecutorProviderStartDriverPreflight($options);
        $providerStart = (array) data_get($providerStartPayload, 'dispatch_executor_provider_start_driver_preflight', []);
        $registryPayload = $this->agentProviderAdapterRegistryPreflight($options);
        $registry = (array) data_get($registryPayload, 'provider_adapter_registry_preflight', []);

        $template = [
            'status' => 'agent_dispatch_executor_adapter_invocation_boundary_contract_template_ready',
            'contract_id' => 'DISPATCH-EXECUTOR-ADAPTER-INVOCATION-BOUNDARY-'.strtoupper(substr(($this->stableHash)([
                'provider_start_driver_preflight_hash' => data_get($providerStartPayload, 'dispatch_executor_provider_start_driver_preflight_hash'),
                'provider_adapter_registry_preflight_hash' => data_get($registryPayload, 'provider_adapter_registry_preflight_hash'),
                'provider' => data_get($providerStart, 'provider'),
                'packet_id' => data_get($providerStart, 'packet_id'),
            ]), 0, 24)),
            'source_provider_start_driver_status' => data_get($providerStartPayload, 'status'),
            'source_provider_start_driver_preflight_hash' => data_get($providerStartPayload, 'dispatch_executor_provider_start_driver_preflight_hash'),
            'source_provider_adapter_registry_status' => data_get($registryPayload, 'status'),
            'source_provider_adapter_registry_preflight_hash' => data_get($registryPayload, 'provider_adapter_registry_preflight_hash'),
            'provider' => data_get($providerStart, 'provider'),
            'provider_role' => data_get($providerStart, 'provider_role'),
            'packet_id' => data_get($providerStart, 'packet_id'),
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentDispatchExecutorAdapterInvocationBoundary',
                'method' => 'prepareInvocation',
                'input_contract' => [
                    'run_key',
                    'adapter_invocation_id',
                    'provider_start_attempt_id',
                    'provider',
                    'adapter',
                    'command',
                    'cwd',
                    'context_pack_hash',
                    'continuation_summary_hash',
                    'actor',
                    'session',
                    'max_runtime_minutes',
                    'max_cost_usd',
                    'reason',
                ],
                'result_contract' => [
                    'adapter_invocation_id',
                    'agent_run_id',
                    'run_key',
                    'run_status',
                    'provider',
                    'adapter',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'mandatory_boundary_guards' => [
                'agent_run_exists_in_pre_start_guarded_state',
                'pre_start_heartbeat_exists_before_adapter_boundary',
                'provider_adapter_registry_resolves_provider_adapter_pair',
                'provider_start_attempt_matches_run_metadata',
                'adapter_command_and_cwd_match_prepared_run_metadata',
                'context_pack_hash_is_explicit',
                'continuation_summary_hash_is_explicit',
                'full_chat_history_is_not_used_as_adapter_context',
                'provider_process_is_not_started_by_boundary',
                'token_spend_is_not_allowed_by_boundary',
                'append_only_ledger_event_is_written_before_any_future_external_invocation',
            ],
            'forbidden_boundary_behaviors' => [
                'shell_exec_or_process_spawn',
                'calling_codex_claude_gemini_or_http_provider',
                'spending_provider_tokens',
                'mutating_packet_claim_or_completion_state',
                'marking_run_terminal',
                'self_merging_or_publishing',
                'using_unbounded_context',
            ],
            'adapter_registry_contract' => [
                'codex' => 'codex_adapter_requires_explicit_external_invocation_stage',
                'claude' => 'claude_adapter_requires_future_provider_specific_contract',
                'gemini' => 'gemini_adapter_requires_future_provider_specific_contract',
                'local' => 'local_shell_adapter_requires_restricted_command_allowlist',
                'http' => 'http_adapter_requires_provider_policy_and_redaction_contract',
            ],
            'required_tests' => [
                'prepares_boundary_without_starting_provider',
                'is_idempotent_for_same_adapter_invocation_id',
                'rejects_run_not_pre_start_guarded',
                'rejects_missing_pre_start_heartbeat',
                'rejects_adapter_command_or_cwd_mismatch',
                'rolls_back_run_update_when_ledger_write_fails',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentDispatchExecutorAdapterInvocationBoundary.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorAdapterInvocationBoundaryTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'boundary_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-adapter-invocation-boundary-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_adapter_invocation_boundary_contract_template.v1',
            'status' => 'agent_dispatch_executor_adapter_invocation_boundary_contract_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_adapter_invocation_boundary_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_adapter_invocation_boundary_contract_template' => $template,
            'dispatch_executor_adapter_invocation_boundary_contract_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_adapter_invocation_boundary_contract_template_does_not_start_providers',
                'agent_dispatch_executor_adapter_invocation_boundary_contract_template_does_not_call_adapters',
                'agent_dispatch_executor_adapter_invocation_boundary_contract_template_does_not_spend_tokens',
                'agent_dispatch_executor_adapter_invocation_boundary_contract_template_does_not_create_boundary_files',
                'agent_dispatch_executor_adapter_invocation_boundary_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent dispatch executor adapter invocation boundary contract template defines the future safe adapter boundary, but does not call providers or create boundary files.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorAdapterInvocationBoundaryPreflight(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorAdapterInvocationBoundaryContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_adapter_invocation_boundary_contract_template', []);
        $boundaryClass = AgentDispatchExecutorAdapterInvocationBoundary::class;
        $boundaryReady = class_exists($boundaryClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $boundaryReady ? null : 'adapter_invocation_boundary_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $heartbeatTableReady ? null : 'agent_heartbeat_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_dispatch_executor_adapter_invocation_boundary_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'dispatch_executor_adapter_invocation_boundary_contract_template_hash'),
            'source_provider_start_driver_status' => data_get($contract, 'source_provider_start_driver_status'),
            'provider' => data_get($contract, 'provider'),
            'provider_role' => data_get($contract, 'provider_role'),
            'packet_id' => data_get($contract, 'packet_id'),
            'storage' => [
                'adapter_invocation_boundary_ready' => $boundaryReady,
                'agent_runs_table_ready' => $runsTableReady,
                'agent_heartbeat_table_ready' => $heartbeatTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'boundary_ready_for_future_release' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_adapter_invocation_boundary_service',
                'require_pre_start_guarded_run_before_boundary',
                'require_pre_start_heartbeat_before_boundary',
                'record_adapter_invocation_prepared_without_process_spawn',
                'keep_provider_process_start_in_future_provider_specific_adapter_stage',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentDispatchExecutorAdapterInvocationBoundary.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorAdapterInvocationBoundaryTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_dispatch_executor_adapter_invocation_boundary',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'boundary_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-adapter-invocation-boundary-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_adapter_invocation_boundary_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_dispatch_executor_adapter_invocation_boundary_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_adapter_invocation_boundary_preflight' => $preflight,
            'dispatch_executor_adapter_invocation_boundary_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_adapter_invocation_boundary_preflight_does_not_start_providers',
                'agent_dispatch_executor_adapter_invocation_boundary_preflight_does_not_call_adapters',
                'agent_dispatch_executor_adapter_invocation_boundary_preflight_does_not_spend_tokens',
                'agent_dispatch_executor_adapter_invocation_boundary_preflight_does_not_create_boundary_files',
                'agent_dispatch_executor_adapter_invocation_boundary_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent dispatch executor adapter invocation boundary preflight is ready for a future signed release path, but this command still does not call adapters or start providers.'
                : 'Agent dispatch executor adapter invocation boundary preflight is blocked until boundary service and observability storage exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorAdapterInvocationBoundaryImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_adapter_invocation_boundary_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_adapter_invocation_boundary_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create safe adapter invocation boundary service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorAdapterInvocationBoundary.php'],
                'acceptance' => 'Service exposes prepareInvocation, validates pre-start run and heartbeat, records boundary metadata and never starts external provider processes.',
            ],
            [
                'id' => 'T2',
                'title' => 'Implement context and command guards',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorAdapterInvocationBoundary.php'],
                'acceptance' => 'Boundary requires explicit context_pack_hash and continuation_summary_hash and rejects adapter, command or cwd mismatch.',
            ],
            [
                'id' => 'T3',
                'title' => 'Write adapter boundary feature tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorAdapterInvocationBoundaryTest.php'],
                'acceptance' => 'Tests prove idempotency, heartbeat requirement, mismatch rejection, rollback and no token/provider side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Keep provider-specific adapters behind a later contract',
                'type' => 'integration_gate',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Boundary readiness is visible in release preflight, but command surfaces remain read-only and cannot call Codex, Claude, Gemini, local shell or HTTP providers.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_adapter_invocation_boundary_implementation',
            'implementation_packet_id' => 'AGENT-DISPATCH-EXECUTOR-ADAPTER-INVOCATION-BOUNDARY-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the safe adapter invocation boundary that turns a pre-start guarded run into an audited adapter-invocation-prepared state without starting providers.',
            'non_goals' => [
                'do_not_call_codex_claude_gemini_local_or_http_adapters',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_self_merge_or_complete_packets',
                'do_not_use_full_chat_history_as_context',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_provider_process_invocation',
                'provider_specific_adapter_execution',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'boundary_prepares_invocation_without_starting_provider',
                'boundary_is_idempotent_for_same_adapter_invocation_id',
                'boundary_rejects_run_not_pre_start_guarded',
                'boundary_rejects_missing_pre_start_heartbeat',
                'boundary_rejects_adapter_command_or_cwd_mismatch',
                'boundary_rolls_back_run_update_when_ledger_write_fails',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_provider_or_spawn_process',
                'need_to_modify_file_outside_allowed_files',
                'need_to_create_provider_specific_adapter_without_new_contract',
                'need_to_change_packet_claim_or_completion_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_process_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_adapter_invocation_boundary_implementation_packet.v1',
            'status' => 'ready_for_scoped_adapter_invocation_boundary_implementation',
            'mode' => 'read_only_agent_dispatch_executor_adapter_invocation_boundary_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_adapter_invocation_boundary_implementation_packet' => $packet,
            'dispatch_executor_adapter_invocation_boundary_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_adapter_invocation_boundary_implementation_packet_does_not_start_providers',
                'agent_dispatch_executor_adapter_invocation_boundary_implementation_packet_does_not_call_adapters',
                'agent_dispatch_executor_adapter_invocation_boundary_implementation_packet_does_not_spend_tokens',
                'agent_dispatch_executor_adapter_invocation_boundary_implementation_packet_does_not_create_boundary_files',
                'agent_dispatch_executor_adapter_invocation_boundary_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent dispatch executor adapter invocation boundary implementation packet is ready; it defines scoped boundary work but does not create files, call adapters or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationTemplate(array $options = []): array
    {
        $releasePreflightPayload = $this->agentDispatchExecutorReleasePreflight($options);
        $releasePreflight = (array) data_get($releasePreflightPayload, 'dispatch_executor_release_preflight', []);

        $template = [
            'status' => 'ready_for_signature_collection',
            'authorization_id' => 'DISPATCH-EXECUTOR-RELEASE-AUTH-'.strtoupper(substr((string) data_get($releasePreflight, 'contract_hash', ($this->stableHash)($releasePreflight)), 0, 24)),
            'preflight_status' => data_get($releasePreflightPayload, 'status'),
            'preflight_hash' => data_get($releasePreflightPayload, 'dispatch_executor_release_preflight_hash'),
            'contract_hash' => data_get($releasePreflight, 'contract_hash'),
            'provider' => data_get($releasePreflight, 'provider'),
            'provider_role' => data_get($releasePreflight, 'provider_role'),
            'packet_id' => data_get($releasePreflight, 'packet_id'),
            'receipt_key' => data_get($releasePreflight, 'receipt_key'),
            'authorization_scope' => [
                'single_provider_start_only' => true,
                'single_receipt_only' => true,
                'single_packet_only' => true,
                'workspace_bound' => true,
                'expires_before_execution_required' => true,
            ],
            'required_signatures' => [
                [
                    'role' => 'operator',
                    'purpose' => 'approve provider executor release for one bounded dispatch',
                    'required' => true,
                ],
                [
                    'role' => 'atlas_policy',
                    'purpose' => 'confirm governance, scope, budget and observability are satisfied',
                    'required' => true,
                ],
            ],
            'required_evidence' => [
                'dispatch_executor_contract_template_hash',
                'dispatch_executor_release_preflight_hash',
                'signed_dispatch_receipt_hash',
                'provider_sandbox_or_worktree_binding',
                'atomic_receipt_used_writer_plan',
                'pre_start_heartbeat_plan',
                'cost_and_work_product_capture_plan',
                'stop_conditions_and_scope_validator_output',
            ],
            'authorization_fields' => [
                'decision' => ['approve_release_once', 'reject_release', 'request_more_evidence'],
                'signed_payload_fields' => [
                    'signed_by',
                    'signed_at',
                    'expires_at',
                    'max_provider_starts',
                    'max_runtime_minutes',
                    'max_cost_usd',
                    'notes',
                    'authorization_hash',
                ],
            ],
            'hard_denial_conditions' => [
                'missing_signed_dispatch_receipt',
                'missing_atomic_receipt_use_writer',
                'missing_workspace_or_worktree_binding',
                'provider_identity_mismatch',
                'packet_scope_mismatch',
                'hot_scope_overlap',
                'missing_liveness_or_cost_capture',
            ],
            'release_allowed_by_template' => false,
            'provider_start_allowed_by_template' => false,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-receipt-draft --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_template.v1',
            'status' => 'agent_dispatch_executor_release_authorization_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_template' => $template,
            'dispatch_executor_release_authorization_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_template_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_template_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_template_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_template_does_not_persist_authorization',
            ],
            'human_summary' => 'Agent dispatch executor release authorization template defines the future signatures and evidence required to release one provider executor, but grants no dispatch authority.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->agentDispatchExecutorReleaseAuthorizationTemplate($options);
        $template = (array) data_get($templatePayload, 'dispatch_executor_release_authorization_template', []);
        $templateHash = (string) data_get($templatePayload, 'dispatch_executor_release_authorization_template_hash');
        $receiptKey = 'EXECUTOR-RELEASE-AUTH-DRAFT-'.strtoupper(substr($templateHash, 0, 24));

        $draft = [
            'status' => 'unsigned_draft_ready',
            'receipt_key' => $receiptKey,
            'authorization_id' => data_get($template, 'authorization_id'),
            'authorization_template_hash' => $templateHash,
            'preflight_hash' => data_get($template, 'preflight_hash'),
            'contract_hash' => data_get($template, 'contract_hash'),
            'provider' => data_get($template, 'provider'),
            'provider_role' => data_get($template, 'provider_role'),
            'packet_id' => data_get($template, 'packet_id'),
            'source_template_status' => data_get($templatePayload, 'status'),
            'allowed_decisions' => data_get($template, 'authorization_fields.decision', []),
            'default_decision' => 'request_more_evidence',
            'required_signatures' => data_get($template, 'required_signatures', []),
            'required_evidence' => data_get($template, 'required_evidence', []),
            'hard_denial_conditions' => data_get($template, 'hard_denial_conditions', []),
            'unsigned_payload' => [
                'decision' => null,
                'signed_by' => null,
                'signed_at' => null,
                'expires_at' => null,
                'max_provider_starts' => 1,
                'max_runtime_minutes' => null,
                'max_cost_usd' => null,
                'notes' => null,
                'authorization_hash' => null,
            ],
            'draft_policy' => [
                'is_unsigned' => true,
                'is_not_persisted' => true,
                'signature_acceptance_allowed' => false,
                'release_allowed_by_draft' => false,
                'provider_start_allowed_by_draft' => false,
                'requires_separate_signature_request' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-signature-request --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_receipt_draft.v1',
            'status' => 'agent_dispatch_executor_release_authorization_receipt_draft_ready',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_receipt_draft',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_receipt_draft' => $draft,
            'dispatch_executor_release_authorization_receipt_draft_hash' => ($this->stableHash)($draft),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_receipt_draft_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_receipt_draft_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_receipt_draft_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_receipt_draft_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_receipt_draft_does_not_persist_authorization',
            ],
            'human_summary' => 'Agent dispatch executor release authorization receipt draft is unsigned, non-persisted and cannot release or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        $draftPayload = $this->agentDispatchExecutorReleaseAuthorizationReceiptDraft($options);
        $draft = (array) data_get($draftPayload, 'dispatch_executor_release_authorization_receipt_draft', []);
        $draftHash = (string) data_get($draftPayload, 'dispatch_executor_release_authorization_receipt_draft_hash');

        $request = [
            'status' => 'signature_request_ready',
            'signature_request_id' => 'EXECUTOR-RELEASE-SIGNATURE-'.strtoupper(substr($draftHash, 0, 24)),
            'receipt_key' => data_get($draft, 'receipt_key'),
            'authorization_id' => data_get($draft, 'authorization_id'),
            'receipt_draft_hash' => $draftHash,
            'authorization_template_hash' => data_get($draft, 'authorization_template_hash'),
            'contract_hash' => data_get($draft, 'contract_hash'),
            'preflight_hash' => data_get($draft, 'preflight_hash'),
            'provider' => data_get($draft, 'provider'),
            'provider_role' => data_get($draft, 'provider_role'),
            'packet_id' => data_get($draft, 'packet_id'),
            'allowed_decisions' => data_get($draft, 'allowed_decisions', []),
            'requested_signatures' => array_map(
                static fn (array $signature): array => [
                    'role' => $signature['role'] ?? 'unknown',
                    'purpose' => $signature['purpose'] ?? 'approve executor release',
                    'required' => (bool) ($signature['required'] ?? true),
                    'status' => 'missing',
                ],
                (array) data_get($draft, 'required_signatures', []),
            ),
            'payload_to_sign' => [
                'receipt_key' => data_get($draft, 'receipt_key'),
                'receipt_draft_hash' => $draftHash,
                'decision' => null,
                'signed_by' => null,
                'expires_at' => null,
                'max_provider_starts' => data_get($draft, 'unsigned_payload.max_provider_starts', 1),
                'max_runtime_minutes' => null,
                'max_cost_usd' => null,
                'evidence_hashes' => [
                    'authorization_template_hash' => data_get($draft, 'authorization_template_hash'),
                    'contract_hash' => data_get($draft, 'contract_hash'),
                    'preflight_hash' => data_get($draft, 'preflight_hash'),
                ],
            ],
            'required_evidence' => data_get($draft, 'required_evidence', []),
            'hard_denial_conditions' => data_get($draft, 'hard_denial_conditions', []),
            'signature_policy' => [
                'signature_acceptance_allowed_here' => false,
                'signature_validation_allowed_here' => false,
                'authorization_persistence_allowed_here' => false,
                'release_allowed_by_request' => false,
                'provider_start_allowed_by_request' => false,
                'requires_separate_signed_receipt_template' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-post-signature-runbook --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_signature_request.v1',
            'status' => 'agent_dispatch_executor_release_authorization_signature_request_ready',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_signature_request',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_signature_request' => $request,
            'dispatch_executor_release_authorization_signature_request_hash' => ($this->stableHash)($request),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_signature_request_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_signature_request_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_signature_request_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_signature_request_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_signature_request_does_not_persist_authorization',
            ],
            'human_summary' => 'Agent dispatch executor release authorization signature request is ready, but it does not accept signatures, persist authorization or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->agentDispatchExecutorReleaseAuthorizationSignatureRequest($options);
        $signatureRequest = (array) data_get($signaturePayload, 'dispatch_executor_release_authorization_signature_request', []);
        $signatureRequestHash = (string) data_get($signaturePayload, 'dispatch_executor_release_authorization_signature_request_hash');

        $steps = [
            [
                'id' => 'verify_signature_payload_hashes',
                'description' => 'Recompute receipt draft, authorization template, executor contract and release preflight hashes before any signed receipt template is produced.',
                'blocking' => true,
            ],
            [
                'id' => 'validate_external_signatures_outside_this_surface',
                'description' => 'Collect and validate operator and atlas_policy signatures using a future signature-validation surface, not this runbook.',
                'blocking' => true,
            ],
            [
                'id' => 'verify_required_evidence',
                'description' => 'Confirm sandbox/worktree binding, signed dispatch receipt, atomic receipt-use plan, heartbeat plan, cost/work-product capture and scope validator evidence.',
                'blocking' => true,
            ],
            [
                'id' => 'stop_on_denial_conditions',
                'description' => 'Reject the release if any hard denial condition appears: missing receipt, missing atomic writer, workspace mismatch, provider mismatch, packet mismatch or hot-scope overlap.',
                'blocking' => true,
            ],
            [
                'id' => 'prepare_signed_receipt_template',
                'description' => 'Only after the above checks, generate the next signed receipt template; do not persist it or start providers here.',
                'blocking' => false,
            ],
        ];

        $runbook = [
            'status' => 'post_signature_runbook_ready',
            'signature_request_hash' => $signatureRequestHash,
            'signature_request_id' => data_get($signatureRequest, 'signature_request_id'),
            'receipt_key' => data_get($signatureRequest, 'receipt_key'),
            'authorization_id' => data_get($signatureRequest, 'authorization_id'),
            'provider' => data_get($signatureRequest, 'provider'),
            'provider_role' => data_get($signatureRequest, 'provider_role'),
            'packet_id' => data_get($signatureRequest, 'packet_id'),
            'required_signatures' => data_get($signatureRequest, 'requested_signatures', []),
            'required_evidence' => data_get($signatureRequest, 'required_evidence', []),
            'hard_denial_conditions' => data_get($signatureRequest, 'hard_denial_conditions', []),
            'steps' => $steps,
            'step_count' => count($steps),
            'post_signature_policy' => [
                'signature_acceptance_allowed_here' => false,
                'signature_validation_allowed_here' => false,
                'authorization_persistence_allowed_here' => false,
                'release_allowed_by_runbook' => false,
                'provider_start_allowed_by_runbook' => false,
                'requires_separate_signed_receipt_template' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-signed-receipt-template --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_post_signature_runbook.v1',
            'status' => 'agent_dispatch_executor_release_authorization_post_signature_runbook_ready',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_post_signature_runbook',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_post_signature_runbook' => $runbook,
            'dispatch_executor_release_authorization_post_signature_runbook_hash' => ($this->stableHash)($runbook),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_post_signature_runbook_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_post_signature_runbook_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_post_signature_runbook_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_post_signature_runbook_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_post_signature_runbook_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_post_signature_runbook_does_not_persist_authorization',
            ],
            'human_summary' => 'Agent dispatch executor release authorization post-signature runbook is ready, but it does not accept or validate signatures, persist authorization or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'dispatch_executor_release_authorization_post_signature_runbook', []);
        $runbookHash = (string) data_get($runbookPayload, 'dispatch_executor_release_authorization_post_signature_runbook_hash');

        $template = [
            'status' => 'signed_receipt_template_ready',
            'receipt_key' => data_get($runbook, 'receipt_key'),
            'authorization_id' => data_get($runbook, 'authorization_id'),
            'signature_request_id' => data_get($runbook, 'signature_request_id'),
            'post_signature_runbook_hash' => $runbookHash,
            'signature_request_hash' => data_get($runbook, 'signature_request_hash'),
            'provider' => data_get($runbook, 'provider'),
            'provider_role' => data_get($runbook, 'provider_role'),
            'packet_id' => data_get($runbook, 'packet_id'),
            'required_signatures' => data_get($runbook, 'required_signatures', []),
            'required_evidence' => data_get($runbook, 'required_evidence', []),
            'hard_denial_conditions' => data_get($runbook, 'hard_denial_conditions', []),
            'allowed_decisions' => ['approve_release_once', 'reject_release', 'request_more_evidence'],
            'signed_payload_template' => [
                'decision' => null,
                'signed_by' => null,
                'signed_at' => null,
                'expires_at' => null,
                'validated_signature_refs' => [],
                'evidence_hashes' => [
                    'post_signature_runbook_hash' => $runbookHash,
                    'signature_request_hash' => data_get($runbook, 'signature_request_hash'),
                ],
                'limits' => [
                    'max_provider_starts' => 1,
                    'max_runtime_minutes' => null,
                    'max_cost_usd' => null,
                ],
                'notes' => null,
                'signed_receipt_hash' => null,
            ],
            'template_policy' => [
                'signature_acceptance_allowed_here' => false,
                'signature_validation_allowed_here' => false,
                'receipt_persistence_allowed_here' => false,
                'release_allowed_by_template' => false,
                'provider_start_allowed_by_template' => false,
                'requires_separate_signed_receipt_preflight' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-signed-receipt-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_signed_receipt_template.v1',
            'status' => 'agent_dispatch_executor_release_authorization_signed_receipt_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_signed_receipt_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_signed_receipt_template' => $template,
            'dispatch_executor_release_authorization_signed_receipt_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_signed_receipt_template_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_signed_receipt_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_signed_receipt_template_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_signed_receipt_template_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_signed_receipt_template_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_signed_receipt_template_does_not_persist_authorization',
            ],
            'human_summary' => 'Agent dispatch executor release authorization signed receipt template is ready, but it does not accept signatures, persist authorization or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight(array $options = []): array
    {
        $templatePayload = $this->agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'dispatch_executor_release_authorization_signed_receipt_template', []);
        $templateHash = (string) data_get($templatePayload, 'dispatch_executor_release_authorization_signed_receipt_template_hash');

        $blockingReasons = [
            'external_signature_validation_evidence_missing',
            'signed_payload_values_missing',
            'authorization_persistence_schema_missing',
            'atomic_receipt_use_writer_missing',
            'provider_sandbox_binding_evidence_missing',
            'provider_start_driver_disabled',
        ];

        $preflight = [
            'status' => 'blocked',
            'blocking_reasons' => $blockingReasons,
            'blocking_count' => count($blockingReasons),
            'signed_receipt_template_hash' => $templateHash,
            'receipt_key' => data_get($template, 'receipt_key'),
            'authorization_id' => data_get($template, 'authorization_id'),
            'provider' => data_get($template, 'provider'),
            'provider_role' => data_get($template, 'provider_role'),
            'packet_id' => data_get($template, 'packet_id'),
            'required_before_persistence' => [
                'validated_operator_signature' => false,
                'validated_atlas_policy_signature' => false,
                'complete_signed_payload' => false,
                'signature_refs_are_bound_to_template_hash' => false,
                'required_evidence_hashes_present' => false,
                'hard_denial_conditions_absent' => false,
                'authorization_persistence_storage_ready' => false,
            ],
            'required_before_executor_release' => [
                'signed_authorization_receipt_persisted' => false,
                'receipt_use_atomic_writer_ready' => false,
                'provider_sandbox_or_worktree_bound' => false,
                'pre_start_heartbeat_ready' => false,
                'cost_and_work_product_capture_ready' => false,
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'signature_acceptance_allowed_here' => false,
                'signature_validation_allowed_here' => false,
                'authorization_persistence_allowed_here' => false,
                'release_allowed_by_preflight' => false,
                'provider_start_allowed_by_preflight' => false,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-template --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_signed_receipt_preflight.v1',
            'status' => 'blocked',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_signed_receipt_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_signed_receipt_preflight' => $preflight,
            'dispatch_executor_release_authorization_signed_receipt_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_signed_receipt_preflight_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_signed_receipt_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_signed_receipt_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_signed_receipt_preflight_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_signed_receipt_preflight_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_signed_receipt_preflight_does_not_persist_authorization',
            ],
            'human_summary' => 'Agent dispatch executor release authorization signed receipt preflight is blocked by design until signatures, evidence, persistence storage and atomic receipt-use writer exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceTemplate(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_release_authorization_signed_receipt_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_release_authorization_signed_receipt_preflight_hash');

        $template = [
            'status' => 'blocked_before_authorization_persistence_writer',
            'source_signed_receipt_preflight_hash' => $preflightHash,
            'receipt_key' => data_get($preflight, 'receipt_key'),
            'authorization_id' => data_get($preflight, 'authorization_id'),
            'provider' => data_get($preflight, 'provider'),
            'provider_role' => data_get($preflight, 'provider_role'),
            'packet_id' => data_get($preflight, 'packet_id'),
            'persistence_target' => [
                'table' => 'atlas_self_construction_agent_dispatch_executor_release_authorizations',
                'record_key_column' => 'authorization_key',
                'idempotency_column' => 'signed_receipt_hash',
                'status_column' => 'status',
                'append_only_ledger_event_type' => 'self_construction.agent_dispatch_executor_release_authorization.persisted',
            ],
            'required_columns' => [
                'authorization_key',
                'receipt_key',
                'authorization_id',
                'packet_id',
                'provider',
                'provider_role',
                'decision',
                'status',
                'signed_by',
                'signed_at',
                'expires_at',
                'signed_receipt_template_hash',
                'signed_receipt_preflight_hash',
                'signed_receipt_hash',
                'payload',
                'persisted_at',
            ],
            'required_atomic_guards' => [
                'unique_authorization_key',
                'unique_signed_receipt_hash',
                'reject_expired_signed_receipt',
                'reject_reused_signed_receipt',
                'reject_missing_external_signature_validation_report',
                'reject_hard_denial_condition',
                'write_authorization_and_ledger_event_in_same_transaction',
            ],
            'required_before_persistence_writer' => [
                'authorization_persistence_migration_exists' => false,
                'authorization_model_exists' => false,
                'authorization_repository_exists' => false,
                'external_signature_validation_report_exists' => false,
                'signed_payload_values_exist' => false,
                'append_only_event_writer_exists' => false,
                'idempotency_guard_exists' => false,
            ],
            'persistence_policy' => [
                'template_is_read_only' => true,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'release_allowed_by_template' => false,
                'provider_start_allowed_by_template' => false,
                'requires_separate_persistence_preflight' => true,
                'requires_separate_atomic_writer' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_template.v1',
            'status' => 'blocked',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_template' => $template,
            'dispatch_executor_release_authorization_persistence_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_write_ledger',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence template is blocked/read-only; it defines the future storage, idempotency and ledger contract without persisting authorization or releasing providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistencePreflight(array $options = []): array
    {
        $templatePayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceTemplate($options);
        $template = (array) data_get($templatePayload, 'dispatch_executor_release_authorization_persistence_template', []);
        $templateHash = (string) data_get($templatePayload, 'dispatch_executor_release_authorization_persistence_template_hash');
        $targetTable = (string) data_get($template, 'persistence_target.table');

        $migrationExists = file_exists(database_path('migrations/2026_05_12_020000_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php'));
        $modelExists = class_exists(AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::class);
        $schemaExists = $targetTable !== '' && Schema::hasTable($targetTable);

        $blockingReasons = array_values(array_filter([
            $migrationExists ? null : 'authorization_persistence_migration_missing',
            $modelExists ? null : 'authorization_model_missing',
            $schemaExists ? null : 'authorization_persistence_table_missing',
            'authorization_repository_missing',
            'external_signature_validation_report_missing',
            'signed_payload_values_missing',
            'append_only_event_writer_missing',
            'idempotency_guard_missing',
            'atomic_transaction_boundary_missing',
            'receipt_use_writer_missing',
            'provider_release_still_disabled',
        ]));

        $preflight = [
            'status' => 'blocked',
            'blocking_reasons' => $blockingReasons,
            'blocking_count' => count($blockingReasons),
            'persistence_template_hash' => $templateHash,
            'source_signed_receipt_preflight_hash' => data_get($template, 'source_signed_receipt_preflight_hash'),
            'receipt_key' => data_get($template, 'receipt_key'),
            'authorization_id' => data_get($template, 'authorization_id'),
            'provider' => data_get($template, 'provider'),
            'provider_role' => data_get($template, 'provider_role'),
            'packet_id' => data_get($template, 'packet_id'),
            'storage_checks' => [
                'migration_exists' => $migrationExists,
                'model_exists' => $modelExists,
                'table_exists' => $schemaExists,
                'target_table' => $targetTable,
            ],
            'writer_checks' => [
                'authorization_repository_exists' => false,
                'external_signature_validation_report_exists' => false,
                'signed_payload_values_exist' => false,
                'append_only_event_writer_exists' => false,
                'idempotency_guard_exists' => false,
                'atomic_transaction_boundary_exists' => false,
                'receipt_use_writer_exists' => false,
            ],
            'required_atomic_guards' => data_get($template, 'required_atomic_guards', []),
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'release_allowed_by_preflight' => false,
                'provider_start_allowed_by_preflight' => false,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-contract-template --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_preflight.v1',
            'status' => 'blocked',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_preflight' => $preflight,
            'dispatch_executor_release_authorization_persistence_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_write_ledger',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence preflight remains blocked until storage, repository, external signature validation, idempotency, append-only event writer and atomic receipt-use writer exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorReleaseAuthorizationPersistencePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_preflight_hash');

        $contract = [
            'status' => 'writer_contract_template_ready_but_not_implemented',
            'persistence_preflight_hash' => $preflightHash,
            'receipt_key' => data_get($preflight, 'receipt_key'),
            'authorization_id' => data_get($preflight, 'authorization_id'),
            'provider' => data_get($preflight, 'provider'),
            'provider_role' => data_get($preflight, 'provider_role'),
            'packet_id' => data_get($preflight, 'packet_id'),
            'contract' => [
                'service' => 'AgentDispatchExecutorReleaseAuthorizationPersistenceWriter',
                'method' => 'persistSignedReleaseAuthorization',
                'input_dto' => 'SignedExecutorReleaseAuthorizationPersistenceInput',
                'output_dto' => 'SignedExecutorReleaseAuthorizationPersistenceResult',
                'transaction_boundary' => 'single_database_transaction',
                'idempotency_key' => 'signed_receipt_hash',
            ],
            'required_input_fields' => [
                'authorization_key',
                'receipt_key',
                'authorization_id',
                'decision',
                'signed_by',
                'signed_at',
                'expires_at',
                'signed_receipt_template_hash',
                'signed_receipt_preflight_hash',
                'persistence_template_hash',
                'persistence_preflight_hash',
                'external_signature_validation_report_hash',
                'signed_receipt_hash',
                'payload',
            ],
            'required_validation_steps' => [
                'verify_persistence_preflight_hash_matches_current_contract',
                'verify_signed_receipt_hash_is_unique',
                'verify_authorization_key_is_unique',
                'verify_decision_is_approve_release_once_or_reject_release_or_request_more_evidence',
                'reject_expired_signed_receipt',
                'reject_missing_external_signature_validation_report_hash',
                'reject_hard_denial_conditions',
                'write_authorization_record',
                'write_append_only_ledger_event',
                'return_persistence_receipt_hash',
            ],
            'forbidden_writer_behaviors' => [
                'starting_provider',
                'dispatching_work',
                'marking_dispatch_receipt_used',
                'validating_raw_signatures_without_external_report',
                'mutating_packet_state',
                'mutating_policy',
                'bypassing_idempotency',
                'writing_authorization_without_ledger_event',
            ],
            'required_tests' => [
                'persists_authorization_once_with_validated_signature_report',
                'is_idempotent_for_same_signed_receipt_hash',
                'rejects_duplicate_authorization_key',
                'rejects_expired_signed_receipt',
                'rejects_missing_external_signature_validation_report',
                'does_not_start_provider_or_dispatch_work',
                'writes_ledger_event_in_same_transaction',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentDispatchExecutorReleaseAuthorizationPersistenceWriter.php',
                'app/Models/AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization.php',
                'database/migrations/*_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'writer_implementation_allowed_here' => false,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_separate_implementation_preflight' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-implementation-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_writer_contract_template.v1',
            'status' => 'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_writer_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_writer_contract_template' => $contract,
            'dispatch_executor_release_authorization_persistence_writer_contract_template_hash' => ($this->stableHash)($contract),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_write_ledger',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence writer contract template is ready, but it only defines the future writer interface, validations, forbidden behaviors and tests.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_release_authorization_persistence_writer_contract_template', []);
        $contractHash = (string) data_get($contractPayload, 'dispatch_executor_release_authorization_persistence_writer_contract_template_hash');
        $allowedFiles = (array) data_get($contract, 'implementation_files_allowed_future', []);

        $preflight = [
            'status' => 'ready_for_scoped_writer_implementation_packet',
            'writer_contract_template_hash' => $contractHash,
            'receipt_key' => data_get($contract, 'receipt_key'),
            'authorization_id' => data_get($contract, 'authorization_id'),
            'provider' => data_get($contract, 'provider'),
            'provider_role' => data_get($contract, 'provider_role'),
            'packet_id' => data_get($contract, 'packet_id'),
            'allowed_files' => $allowedFiles,
            'allowed_file_count' => count($allowedFiles),
            'required_first_changes' => [
                'create_authorization_persistence_migration',
                'create_authorization_model',
                'create_persistence_writer_service',
                'create_writer_feature_test',
                'wire_no_provider_start_or_dispatch_side_effects',
            ],
            'implementation_constraints' => [
                'do_not_start_providers',
                'do_not_dispatch_work',
                'do_not_mark_dispatch_receipts_used',
                'do_not_accept_raw_signatures',
                'do_not_validate_raw_signatures_without_external_report',
                'do_not_mutate_packet_state',
                'do_not_mutate_policy',
                'do_not_touch_voice_runtime',
                'do_not_touch_hot_kernel_runtime',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentDispatchExecutorReleaseAuthorizationPersistenceWriter.php',
                'php -l app/Models/AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_dispatch_executor_release_authorization',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'writer_file_creation_allowed_by_preflight' => false,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_explicit_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight.v1',
            'status' => 'ready_for_scoped_writer_implementation_packet',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_writer_implementation_preflight' => $preflight,
            'dispatch_executor_release_authorization_persistence_writer_implementation_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_write_ledger',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence writer implementation preflight is ready to produce a scoped implementation packet, but it does not create writer files or persist authorization.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_writer_implementation_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_writer_implementation_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create executor release authorization persistence migration',
                'type' => 'migration',
                'allowed_files' => ['database/migrations/*_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php'],
                'acceptance' => 'Table contains authorization key, receipt links, signer metadata, hashes, payload, status, timestamps and unique idempotency indexes.',
            ],
            [
                'id' => 'T2',
                'title' => 'Create executor release authorization model',
                'type' => 'model',
                'allowed_files' => ['app/Models/AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization.php'],
                'acceptance' => 'Model exposes guarded fillable/casts for payload and datetime fields without side effects.',
            ],
            [
                'id' => 'T3',
                'title' => 'Create persistence writer service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorReleaseAuthorizationPersistenceWriter.php'],
                'acceptance' => 'Writer validates required fields, idempotency and external signature validation hash, writes authorization and append-only event in one transaction, and never starts providers.',
            ],
            [
                'id' => 'T4',
                'title' => 'Create writer feature test',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest.php'],
                'acceptance' => 'Tests cover successful persistence, idempotency, duplicate rejection, expiry rejection, missing signature report rejection and no dispatch/provider side effects.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_writer_implementation',
            'implementation_packet_id' => 'AGENT-DISPATCH-EXECUTOR-RELEASE-AUTHORIZATION-PERSISTENCE-WRITER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the guarded persistence writer for signed executor release authorizations without enabling provider execution.',
            'non_goals' => [
                'do_not_start_providers',
                'do_not_dispatch_work',
                'do_not_mark_dispatch_receipts_used',
                'do_not_build_signature_authority',
                'do_not_mutate_packet_state',
                'do_not_release_executor',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'voice_runtime',
                'hot_kernel_runtime',
                'provider_start_drivers',
                'policy_mutation',
                'packet_claim_or_completion_state',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'signed_authorization_can_be_persisted_once_with_external_signature_validation_report_hash',
                'same_signed_receipt_hash_is_idempotent',
                'duplicate_authorization_key_is_rejected',
                'expired_signed_receipt_is_rejected',
                'missing_external_signature_validation_report_hash_is_rejected',
                'authorization_and_ledger_event_share_one_transaction',
                'writer_does_not_start_provider_dispatch_work_or_mark_receipt_used',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_modify_file_outside_allowed_files',
                'need_to_start_provider_or_dispatch_work',
                'need_to_validate_raw_signature_inside_writer',
                'missing_append_only_event_api_contract',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'signature_validation_authority_allowed_by_packet' => false,
                'executor_release_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet.v1',
            'status' => 'ready_for_scoped_writer_implementation',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_writer_implementation_packet' => $packet,
            'dispatch_executor_release_authorization_persistence_writer_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_write_ledger',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence writer implementation packet is ready; it defines the scoped implementation work but does not create files or persist authorization.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceStatus(array $options = []): array
    {
        $authorizationModel = AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::class;
        $writerService = AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class;
        $authorizationTable = 'atlas_self_construction_agent_dispatch_executor_release_authorizations';
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashIsValid = preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $storage = [
            'authorization_table' => $authorizationTable,
            'authorization_table_ready' => Schema::hasTable($authorizationTable),
            'authorization_model_ready' => class_exists($authorizationModel),
            'persistence_writer_ready' => class_exists($writerService),
            'ledger_table_ready' => Schema::hasTable('atlas_ledger_events'),
            'receipt_hash_filter' => $receiptHashIsValid ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHash === '' || $receiptHashIsValid,
        ];

        $selectedAuthorization = null;
        $persistedAuthorizationCount = 0;

        if ($storage['authorization_table_ready'] && $storage['authorization_model_ready']) {
            /** @var class-string<Model> $authorizationModel */
            $persistedAuthorizationCount = $authorizationModel::query()->count();
            $query = $authorizationModel::query()->latest('created_at');

            if ($receiptHashIsValid) {
                $query->where('signed_receipt_hash', $receiptHash);
            }

            $authorization = $query->first();

            if ($authorization !== null) {
                $expiresAt = $authorization->getAttribute('expires_at');
                $selectedAuthorization = [
                    'id' => (string) $authorization->getAttribute('id'),
                    'authorization_key' => (string) $authorization->getAttribute('authorization_key'),
                    'receipt_key' => (string) $authorization->getAttribute('receipt_key'),
                    'authorization_id' => (string) $authorization->getAttribute('authorization_id'),
                    'packet_id' => $authorization->getAttribute('packet_id'),
                    'provider' => $authorization->getAttribute('provider'),
                    'provider_role' => $authorization->getAttribute('provider_role'),
                    'decision' => (string) $authorization->getAttribute('decision'),
                    'status' => (string) $authorization->getAttribute('status'),
                    'signed_by' => (string) $authorization->getAttribute('signed_by'),
                    'signed_receipt_hash' => (string) $authorization->getAttribute('signed_receipt_hash'),
                    'expires_at' => method_exists($expiresAt, 'toIso8601String') ? $expiresAt->toIso8601String() : (string) $expiresAt,
                ];
            }
        }

        $selectedStatus = (string) data_get($selectedAuthorization, 'status', '');
        $selectedDecision = (string) data_get($selectedAuthorization, 'decision', '');
        $selectedExpiresAt = (string) data_get($selectedAuthorization, 'expires_at', '');
        $selectedNotExpired = $selectedExpiresAt !== '' && now()->lessThan(CarbonImmutable::parse($selectedExpiresAt));
        $selectedUsableForFutureReleasePreflight = $selectedAuthorization !== null
            && $selectedStatus === 'persisted_pending_executor_release'
            && $selectedDecision === 'approve_release_once'
            && $selectedNotExpired;

        $blockingReasons = array_values(array_filter([
            $storage['authorization_table_ready'] ? null : 'authorization_table_missing',
            $storage['authorization_model_ready'] ? null : 'authorization_model_missing',
            $storage['persistence_writer_ready'] ? null : 'persistence_writer_missing',
            $storage['ledger_table_ready'] ? null : 'ledger_table_missing',
            $storage['receipt_hash_filter_valid'] ? null : 'receipt_hash_filter_invalid',
        ]));

        $status = [
            'status' => $blockingReasons === [] ? 'agent_dispatch_executor_release_authorization_persistence_status_ready' : 'blocked',
            'storage' => $storage,
            'persisted_authorization_count' => $persistedAuthorizationCount,
            'selected_authorization' => $selectedAuthorization,
            'selected_authorization_present' => $selectedAuthorization !== null,
            'selected_authorization_not_expired' => $selectedNotExpired,
            'selected_usable_for_future_release_preflight' => $selectedUsableForFutureReleasePreflight,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'release_preconditions' => [
                'persisted_authorization_present' => $selectedAuthorization !== null,
                'decision_approves_one_release' => $selectedDecision === 'approve_release_once',
                'status_pending_executor_release' => $selectedStatus === 'persisted_pending_executor_release',
                'authorization_not_expired' => $selectedNotExpired,
                'ledger_table_ready' => (bool) $storage['ledger_table_ready'],
                'future_receipt_use_mark_writer_required' => true,
                'provider_sandbox_binding_required' => true,
                'manual_release_authorization_required' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-preflight --json',
            'status_policy' => [
                'read_only' => true,
                'authorization_persistence_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_status' => $status,
            'dispatch_executor_release_authorization_persistence_status_hash' => ($this->stableHash)($status),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_write_ledger',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent dispatch executor release authorization persistence status is ready; persisted authorization state can be inspected, but providers remain unreleased.'
                : 'Agent dispatch executor release authorization persistence status is blocked by missing storage or invalid filters; no provider release is allowed.',
        ];
    }
}
