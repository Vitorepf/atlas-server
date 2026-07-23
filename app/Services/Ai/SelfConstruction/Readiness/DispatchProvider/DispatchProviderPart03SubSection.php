<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\DispatchProvider;

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
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;

/**
 * GOD-DEBULK sub-split part 03 of {@see ReadinessProjectionAgentDispatchProviderSection}.
 *
 * Bodies moved VERBATIM from the facade (A1-SC-0056 dispatch-receipt guard
 * lives in part 01, byte-identical). The injected $stableHash closure keeps
 * every ($this->stableHash)(...) call unchanged, and __call routes every
 * sibling/mother back-call through the facade exactly as before.
 */
final class DispatchProviderPart03SubSection
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


}
