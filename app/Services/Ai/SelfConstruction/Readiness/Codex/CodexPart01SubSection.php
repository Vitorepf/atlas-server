<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterRegistry;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProviderExecutionDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSupervisedStartExecutor;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 01 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexProviderExecutionContractTemplate
 *           .. agentCodexProcessSpawnEnablementImplementationPacket
 */
final class CodexPart01SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexProviderExecutionContractTemplate(array $options = []): array
    {
        $registryPayload = $this->agentDispatchProviderSection->agentProviderAdapterRegistryPreflight($options);
        $guardPayload = $this->agentDispatchProviderSection->agentProviderAdapterExecutionGuardPreflight($options);

        $template = [
            'status' => 'codex_provider_execution_contract_template_ready',
            'contract_id' => 'CODEX-PROVIDER-EXECUTION-'.strtoupper(substr(ReadinessHash::stable([
                'registry_preflight_hash' => data_get($registryPayload, 'provider_adapter_registry_preflight_hash'),
                'execution_guard_preflight_hash' => data_get($guardPayload, 'provider_adapter_execution_guard_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_provider_adapter_registry_status' => data_get($registryPayload, 'status'),
            'source_provider_adapter_registry_preflight_hash' => data_get($registryPayload, 'provider_adapter_registry_preflight_hash'),
            'source_provider_adapter_execution_guard_status' => data_get($guardPayload, 'status'),
            'source_provider_adapter_execution_guard_preflight_hash' => data_get($guardPayload, 'provider_adapter_execution_guard_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'provider_role' => 'implementation_worker',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexProviderExecutionDriver',
                'method' => 'prepareCodexExecution',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'execution_guard_id',
                    'adapter_invocation_id',
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
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'provider_specific_contract_ready',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'codex_execution_must' => [
                'require_provider_adapter_execution_guard_recorded',
                'require_provider_and_adapter_are_codex',
                'require_registry_descriptor_hash_still_matches',
                'require_context_pack_hash_and_continuation_summary_hash',
                'require_command_allowlist_for_codex_only',
                'require_cwd_matches_active_sandbox_binding',
                'require_budget_runtime_and_liveness_limits',
                'require_heartbeat_plan_before_any_future_process_start',
                'require_cost_event_plan_before_token_spend',
                'require_append_only_ledger_event_for_prepared_execution',
            ],
            'codex_execution_must_not' => [
                'start_codex_process_from_contract_template',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'load_full_chat_history_as_context',
                'modify_packet_completion_or_merge_state',
                'bypass_decision_receipt_or_sandbox_binding',
                'grant_claude_gemini_local_or_http_execution_authority',
            ],
            'codex_command_policy' => [
                'allowed_provider' => 'codex',
                'allowed_adapter' => 'codex',
                'allowed_command_family' => 'codex_provider_specific_execution_only',
                'arbitrary_shell_allowed' => false,
                'full_history_context_allowed' => false,
                'continuation_summary_required' => true,
                'context_pack_required' => true,
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexProviderExecutionDriver.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProviderExecutionDriverTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'codex_driver_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-provider-execution-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_provider_execution_contract_template.v1',
            'status' => 'codex_provider_execution_contract_template_ready',
            'mode' => 'read_only_agent_codex_provider_execution_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_provider_execution_contract_template' => $template,
            'codex_provider_execution_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_provider_execution_contract_template_does_not_start_codex',
                'agent_codex_provider_execution_contract_template_does_not_call_codex',
                'agent_codex_provider_execution_contract_template_does_not_spend_tokens',
                'agent_codex_provider_execution_contract_template_does_not_create_driver_files',
                'agent_codex_provider_execution_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex provider execution contract template defines the first provider-specific execution contract without starting Codex or spending tokens.',
        ];
    }


public function agentCodexProviderExecutionPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexProviderExecutionContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_provider_execution_contract_template', []);
        $driverClass = AgentCodexProviderExecutionDriver::class;
        $registryClass = AgentProviderAdapterRegistry::class;
        $guardClass = AgentProviderAdapterExecutionGuard::class;
        $driverReady = class_exists($driverClass);
        $registryReady = class_exists($registryClass);
        $guardReady = class_exists($guardClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $driverReady ? null : 'codex_provider_execution_driver_missing',
            $registryReady ? null : 'provider_adapter_registry_missing',
            $guardReady ? null : 'provider_adapter_execution_guard_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_provider_execution_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_provider_execution_contract_template_hash'),
            'source_provider_adapter_registry_status' => data_get($contract, 'source_provider_adapter_registry_status'),
            'source_provider_adapter_execution_guard_status' => data_get($contract, 'source_provider_adapter_execution_guard_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_provider_execution_driver_ready' => $driverReady,
                'provider_adapter_registry_ready' => $registryReady,
                'provider_adapter_execution_guard_ready' => $guardReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'codex_execution_ready_for_future_release' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => $driverReady
                ? [
                    'keep_codex_provider_execution_driver_behind_explicit_release_path',
                    'apply_database_migrations_for_agent_runs_and_ledger_before_runtime_use',
                    'verify_codex_execution_driver_tests_before_any_process_start_contract',
                ]
                : [
                    'create_codex_provider_execution_driver',
                    'require_provider_adapter_execution_guard_before_codex_preparation',
                    'enforce_codex_provider_adapter_descriptor',
                    'enforce_codex_command_context_budget_and_cwd_guards',
                    'record_codex_provider_execution_prepared_ledger_event_without_starting_codex',
                ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexProviderExecutionDriver.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProviderExecutionDriverTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_provider_execution',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'codex_driver_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-provider-execution-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_provider_execution_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_provider_execution_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_provider_execution_preflight' => $preflight,
            'codex_provider_execution_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_provider_execution_preflight_does_not_start_codex',
                'agent_codex_provider_execution_preflight_does_not_call_codex',
                'agent_codex_provider_execution_preflight_does_not_spend_tokens',
                'agent_codex_provider_execution_preflight_does_not_create_driver_files',
                'agent_codex_provider_execution_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex provider execution preflight is ready; Codex process start still requires an explicit future release path.'
                : 'Codex provider execution preflight is blocked until its listed prerequisites exist; the driver may already be present while runtime storage is still unavailable.',
        ];
    }


public function agentCodexProviderExecutionImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexProviderExecutionPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_provider_execution_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_provider_execution_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex provider execution driver',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProviderExecutionDriver.php'],
                'acceptance' => 'Driver prepares Codex-specific execution metadata only after execution guard exists and never starts Codex.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce Codex-only command and context policy',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProviderExecutionDriver.php'],
                'acceptance' => 'Driver rejects non-Codex providers/adapters, arbitrary shell, missing context hashes, full-history context and cwd mismatches.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add Codex provider execution tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProviderExecutionDriverTest.php'],
                'acceptance' => 'Tests prove precondition guards, idempotency, ledger rollback and no Codex/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose Codex provider execution readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare Codex execution disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_provider_execution_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-PROVIDER-EXECUTION-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex-specific execution driver that can prepare a governed Codex execution envelope while still refusing to start Codex.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_enable_claude_gemini_local_or_http_execution',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'codex_execution_driver_requires_execution_guard_metadata',
                'codex_execution_driver_rejects_non_codex_provider_or_adapter',
                'codex_execution_driver_requires_context_hashes_budget_cwd_and_liveness_plan',
                'codex_execution_driver_records_prepared_ledger_event_without_starting_codex',
                'codex_execution_driver_rolls_back_metadata_when_ledger_write_fails',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_modify_file_outside_allowed_files',
                'need_to_allow_provider_token_spend',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_provider_execution_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_provider_execution_implementation',
            'mode' => 'read_only_agent_codex_provider_execution_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_provider_execution_implementation_packet' => $packet,
            'codex_provider_execution_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_provider_execution_implementation_packet_does_not_start_codex',
                'agent_codex_provider_execution_implementation_packet_does_not_call_codex',
                'agent_codex_provider_execution_implementation_packet_does_not_spend_tokens',
                'agent_codex_provider_execution_implementation_packet_does_not_create_driver_files',
                'agent_codex_provider_execution_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex provider execution implementation packet is ready; it defines scoped Codex-driver work but does not create files, start Codex or spend tokens.',
        ];
    }


public function agentCodexProcessStartReleaseContractTemplate(array $options = []): array
    {
        $codexPayload = $this->section->agentCodexProviderExecutionPreflight($options);

        $template = [
            'status' => 'codex_process_start_release_contract_template_ready',
            'contract_id' => 'CODEX-PROCESS-START-RELEASE-'.strtoupper(substr(ReadinessHash::stable([
                'codex_provider_execution_preflight_hash' => data_get($codexPayload, 'codex_provider_execution_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_provider_execution_status' => data_get($codexPayload, 'status'),
            'source_codex_provider_execution_preflight_hash' => data_get($codexPayload, 'codex_provider_execution_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexProcessStartReleaseGate',
                'method' => 'authorizeCodexProcessStart',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'operator_release_receipt_hash',
                    'codex_execution_contract_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'process_start_release_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'process_start_release_authorized',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'release_must' => [
                'require_codex_provider_execution_prepared_metadata',
                'require_operator_release_receipt_hash',
                'require_codex_execution_contract_hash_matches_current_template',
                'require_single_start_release_per_codex_execution',
                'require_liveness_heartbeat_and_budget_plan',
                'require_stdout_stderr_sanitization_plan',
                'require_pid_exit_timeout_and_ready_probe_plan',
                'require_rollback_and_revocation_plan',
                'record_append_only_release_gate_event_without_starting_codex',
            ],
            'release_must_not' => [
                'start_codex_process_from_release_contract',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexProcessStartReleaseGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessStartReleaseGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'process_start_gate_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-process-start-release-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_start_release_contract_template.v1',
            'status' => 'codex_process_start_release_contract_template_ready',
            'mode' => 'read_only_agent_codex_process_start_release_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_start_release_contract_template' => $template,
            'codex_process_start_release_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_process_start_release_contract_template_does_not_start_codex',
                'agent_codex_process_start_release_contract_template_does_not_call_codex',
                'agent_codex_process_start_release_contract_template_does_not_spend_tokens',
                'agent_codex_process_start_release_contract_template_does_not_create_start_files',
                'agent_codex_process_start_release_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex process start release contract template defines the final governed gate before any future Codex process start, without starting Codex.',
        ];
    }


public function agentCodexProcessStartReleasePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexProcessStartReleaseContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_process_start_release_contract_template', []);
        $gateClass = AgentCodexProcessStartReleaseGate::class;
        $driverClass = AgentCodexProviderExecutionDriver::class;
        $gateReady = class_exists($gateClass);
        $driverReady = class_exists($driverClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_process_start_release_gate_missing',
            $driverReady ? null : 'codex_provider_execution_driver_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_process_start_release_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_process_start_release_contract_template_hash'),
            'source_codex_provider_execution_status' => data_get($contract, 'source_codex_provider_execution_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_process_start_release_gate_ready' => $gateReady,
                'codex_provider_execution_driver_ready' => $driverReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'process_start_release_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_process_start_release_gate',
                'require_codex_provider_execution_prepared_metadata_before_release',
                'require_operator_release_receipt_hash_before_any_start',
                'record_release_gate_ledger_event_without_starting_codex',
                'keep_real_process_start_for_later_supervised_start_executor',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexProcessStartReleaseGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessStartReleaseGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_process_start_release',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'process_start_gate_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-process-start-release-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_start_release_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_process_start_release_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_start_release_preflight' => $preflight,
            'codex_process_start_release_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_process_start_release_preflight_does_not_start_codex',
                'agent_codex_process_start_release_preflight_does_not_call_codex',
                'agent_codex_process_start_release_preflight_does_not_spend_tokens',
                'agent_codex_process_start_release_preflight_does_not_create_start_files',
                'agent_codex_process_start_release_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex process start release preflight is ready; actual Codex start remains disabled until a later supervised start executor.'
                : 'Codex process start release preflight is blocked until the release gate and runtime storage prerequisites exist.',
        ];
    }


public function agentCodexProcessStartReleaseImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexProcessStartReleasePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_process_start_release_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_process_start_release_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex process start release gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProcessStartReleaseGate.php'],
                'acceptance' => 'Gate authorizes only the future release state and never starts Codex, spawns processes or allows token spend.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce release receipt and single-start contract',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProcessStartReleaseGate.php'],
                'acceptance' => 'Gate requires prepared Codex execution metadata, operator release receipt hash, matching contract hash and one release per execution.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add Codex process start release tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessStartReleaseGateTest.php'],
                'acceptance' => 'Tests prove missing receipt rejection, duplicate release rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose Codex process start release readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare Codex process start disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_process_start_release_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-PROCESS-START-RELEASE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex process start release gate that can authorize a future supervised start path while still refusing to start Codex.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'start_release_gate_requires_codex_execution_prepared_metadata',
                'start_release_gate_requires_operator_release_receipt_hash',
                'start_release_gate_is_idempotent_for_same_release_id',
                'start_release_gate_rejects_duplicate_release_for_different_id',
                'start_release_gate_records_release_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_modify_file_outside_allowed_files',
                'need_to_allow_provider_token_spend',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_start_release_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_process_start_release_implementation',
            'mode' => 'read_only_agent_codex_process_start_release_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_start_release_implementation_packet' => $packet,
            'codex_process_start_release_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_process_start_release_implementation_packet_does_not_start_codex',
                'agent_codex_process_start_release_implementation_packet_does_not_call_codex',
                'agent_codex_process_start_release_implementation_packet_does_not_spend_tokens',
                'agent_codex_process_start_release_implementation_packet_does_not_create_start_files',
                'agent_codex_process_start_release_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex process start release implementation packet is ready; it defines scoped release-gate work but does not create files, start Codex or spend tokens.',
        ];
    }


public function agentCodexSupervisedStartExecutorContractTemplate(array $options = []): array
    {
        $releasePayload = $this->section->agentCodexProcessStartReleasePreflight($options);

        $template = [
            'status' => 'codex_supervised_start_executor_contract_template_ready',
            'contract_id' => 'CODEX-SUPERVISED-START-EXECUTOR-'.strtoupper(substr(ReadinessHash::stable([
                'codex_process_start_release_preflight_hash' => data_get($releasePayload, 'codex_process_start_release_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_process_start_release_status' => data_get($releasePayload, 'status'),
            'source_codex_process_start_release_preflight_hash' => data_get($releasePayload, 'codex_process_start_release_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexSupervisedStartExecutor',
                'method' => 'prepareSupervisedStart',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'operator_release_receipt_hash',
                    'stdout_stderr_sanitizer_hash',
                    'ready_probe_plan_hash',
                    'rollback_plan_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'supervised_start_id',
                    'process_start_release_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'supervised_start_prepared',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'executor_must' => [
                'require_codex_process_start_release_authorized',
                'require_operator_release_receipt_hash_matches_release_gate',
                'require_single_supervised_start_per_release',
                'require_command_from_prepared_codex_execution_metadata',
                'require_cwd_from_active_sandbox_binding_metadata',
                'require_stdout_stderr_sanitizer_hash',
                'require_pid_guard_timeout_ready_probe_and_exit_code_plan',
                'require_rollback_and_revocation_plan_hash',
                'record_append_only_supervised_start_prepared_event_before_any_process_start',
            ],
            'executor_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexSupervisedStartExecutor.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexSupervisedStartExecutorTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'supervised_executor_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-supervised-start-executor-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_supervised_start_executor_contract_template.v1',
            'status' => 'codex_supervised_start_executor_contract_template_ready',
            'mode' => 'read_only_agent_codex_supervised_start_executor_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_supervised_start_executor_contract_template' => $template,
            'codex_supervised_start_executor_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_supervised_start_executor_contract_template_does_not_start_codex',
                'agent_codex_supervised_start_executor_contract_template_does_not_call_codex',
                'agent_codex_supervised_start_executor_contract_template_does_not_spend_tokens',
                'agent_codex_supervised_start_executor_contract_template_does_not_create_executor_files',
                'agent_codex_supervised_start_executor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex supervised start executor contract template defines the supervised process-start shell without starting Codex or spending tokens.',
        ];
    }


public function agentCodexSupervisedStartExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexSupervisedStartExecutorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_supervised_start_executor_contract_template', []);
        $executorClass = AgentCodexSupervisedStartExecutor::class;
        $releaseGateClass = AgentCodexProcessStartReleaseGate::class;
        $executorReady = class_exists($executorClass);
        $releaseGateReady = class_exists($releaseGateClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $executorReady ? null : 'codex_supervised_start_executor_missing',
            $releaseGateReady ? null : 'codex_process_start_release_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_supervised_start_executor_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_supervised_start_executor_contract_template_hash'),
            'source_codex_process_start_release_status' => data_get($contract, 'source_codex_process_start_release_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_supervised_start_executor_ready' => $executorReady,
                'codex_process_start_release_gate_ready' => $releaseGateReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'supervised_start_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_supervised_start_executor',
                'require_codex_process_start_release_authorized_metadata',
                'require_sanitizer_ready_probe_pid_timeout_and_rollback_hashes',
                'record_supervised_start_prepared_event_without_starting_codex',
                'keep_real_process_spawn_for_later_enablement_contract',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexSupervisedStartExecutor.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexSupervisedStartExecutorTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_supervised_start_executor',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'supervised_executor_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-supervised-start-executor-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_supervised_start_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_supervised_start_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_supervised_start_executor_preflight' => $preflight,
            'codex_supervised_start_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_supervised_start_executor_preflight_does_not_start_codex',
                'agent_codex_supervised_start_executor_preflight_does_not_call_codex',
                'agent_codex_supervised_start_executor_preflight_does_not_spend_tokens',
                'agent_codex_supervised_start_executor_preflight_does_not_create_executor_files',
                'agent_codex_supervised_start_executor_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex supervised start executor preflight is ready; real process spawn remains disabled until a later enablement contract.'
                : 'Codex supervised start executor preflight is blocked until the supervised executor and runtime storage prerequisites exist.',
        ];
    }


public function agentCodexSupervisedStartExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexSupervisedStartExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_supervised_start_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_supervised_start_executor_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create supervised Codex start executor shell',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexSupervisedStartExecutor.php'],
                'acceptance' => 'Executor prepares supervised start metadata and never spawns Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce process supervision artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexSupervisedStartExecutor.php'],
                'acceptance' => 'Executor requires release metadata, sanitizer hash, ready probe plan hash, rollback hash and single-start guard.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add supervised start executor tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexSupervisedStartExecutorTest.php'],
                'acceptance' => 'Tests prove missing release rejection, duplicate start rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose supervised start executor readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare process spawn disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_supervised_start_executor_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-SUPERVISED-START-EXECUTOR-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the supervised Codex start executor shell that validates all process supervision prerequisites while still refusing to spawn Codex.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'supervised_start_executor_requires_release_authorized_metadata',
                'supervised_start_executor_requires_sanitizer_probe_and_rollback_hashes',
                'supervised_start_executor_is_idempotent_for_same_start_id',
                'supervised_start_executor_rejects_duplicate_start_for_different_id',
                'supervised_start_executor_records_prepared_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_modify_file_outside_allowed_files',
                'need_to_allow_provider_token_spend',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_supervised_start_executor_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_supervised_start_executor_implementation',
            'mode' => 'read_only_agent_codex_supervised_start_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_supervised_start_executor_implementation_packet' => $packet,
            'codex_supervised_start_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_supervised_start_executor_implementation_packet_does_not_start_codex',
                'agent_codex_supervised_start_executor_implementation_packet_does_not_call_codex',
                'agent_codex_supervised_start_executor_implementation_packet_does_not_spend_tokens',
                'agent_codex_supervised_start_executor_implementation_packet_does_not_create_executor_files',
                'agent_codex_supervised_start_executor_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex supervised start executor implementation packet is ready; it defines scoped executor work but does not create files, start Codex or spend tokens.',
        ];
    }


public function agentCodexProcessSpawnEnablementContractTemplate(array $options = []): array
    {
        $supervisedPayload = $this->section->agentCodexSupervisedStartExecutorPreflight($options);

        $template = [
            'status' => 'codex_process_spawn_enablement_contract_template_ready',
            'contract_id' => 'CODEX-PROCESS-SPAWN-ENABLEMENT-'.strtoupper(substr(ReadinessHash::stable([
                'codex_supervised_start_executor_preflight_hash' => data_get($supervisedPayload, 'codex_supervised_start_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_supervised_start_executor_status' => data_get($supervisedPayload, 'status'),
            'source_codex_supervised_start_executor_preflight_hash' => data_get($supervisedPayload, 'codex_supervised_start_executor_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexProcessSpawnEnablementGate',
                'method' => 'enableCodexProcessSpawn',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'operator_spawn_receipt_hash',
                    'supervised_start_contract_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'spawn_enablement_id',
                    'supervised_start_id',
                    'process_start_release_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'process_spawn_enabled',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'enablement_must' => [
                'require_codex_supervised_start_prepared_metadata',
                'require_single_spawn_enablement_per_supervised_start',
                'require_operator_spawn_receipt_hash',
                'require_supervised_start_contract_hash',
                'require_all_started_token_provider_and_dispatch_flags_false',
                'record_append_only_spawn_enablement_event_before_any_process_start',
            ],
            'enablement_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnEnablementGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessSpawnEnablementGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'spawn_enablement_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-process-spawn-enablement-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_spawn_enablement_contract_template.v1',
            'status' => 'codex_process_spawn_enablement_contract_template_ready',
            'mode' => 'read_only_agent_codex_process_spawn_enablement_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_enablement_contract_template' => $template,
            'codex_process_spawn_enablement_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_process_spawn_enablement_contract_template_does_not_start_codex',
                'agent_codex_process_spawn_enablement_contract_template_does_not_call_codex',
                'agent_codex_process_spawn_enablement_contract_template_does_not_spend_tokens',
                'agent_codex_process_spawn_enablement_contract_template_does_not_create_spawn_files',
                'agent_codex_process_spawn_enablement_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex process spawn enablement contract template defines the audited enablement gate without starting Codex or spending tokens.',
        ];
    }


public function agentCodexProcessSpawnEnablementPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexProcessSpawnEnablementContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_process_spawn_enablement_contract_template', []);
        $gateClass = AgentCodexProcessSpawnEnablementGate::class;
        $supervisedExecutorClass = AgentCodexSupervisedStartExecutor::class;
        $gateReady = class_exists($gateClass);
        $supervisedExecutorReady = class_exists($supervisedExecutorClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_process_spawn_enablement_gate_missing',
            $supervisedExecutorReady ? null : 'codex_supervised_start_executor_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_process_spawn_enablement_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_process_spawn_enablement_contract_template_hash'),
            'source_codex_supervised_start_executor_status' => data_get($contract, 'source_codex_supervised_start_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_process_spawn_enablement_gate_ready' => $gateReady,
                'codex_supervised_start_executor_ready' => $supervisedExecutorReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'spawn_enablement_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_process_spawn_enablement_gate',
                'require_codex_supervised_start_prepared_metadata',
                'require_operator_spawn_receipt_and_supervised_start_contract_hashes',
                'record_spawn_enablement_event_without_starting_codex',
                'keep_real_process_spawn_for_later_executor_contract',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnEnablementGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessSpawnEnablementGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_process_spawn_enablement',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'spawn_enablement_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-process-spawn-enablement-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_spawn_enablement_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_process_spawn_enablement_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_enablement_preflight' => $preflight,
            'codex_process_spawn_enablement_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_process_spawn_enablement_preflight_does_not_start_codex',
                'agent_codex_process_spawn_enablement_preflight_does_not_call_codex',
                'agent_codex_process_spawn_enablement_preflight_does_not_spend_tokens',
                'agent_codex_process_spawn_enablement_preflight_does_not_create_spawn_files',
                'agent_codex_process_spawn_enablement_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex process spawn enablement preflight is ready; real process spawn remains disabled until a final executor contract.'
                : 'Codex process spawn enablement preflight is blocked until the gate and runtime storage prerequisites exist.',
        ];
    }


public function agentCodexProcessSpawnEnablementImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexProcessSpawnEnablementPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_process_spawn_enablement_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_process_spawn_enablement_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex process spawn enablement gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnEnablementGate.php'],
                'acceptance' => 'Gate records spawn enablement metadata and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce supervised start handoff artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnEnablementGate.php'],
                'acceptance' => 'Gate requires prepared supervised start metadata, operator spawn receipt hash and supervised start contract hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add process spawn enablement tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessSpawnEnablementGateTest.php'],
                'acceptance' => 'Tests prove missing supervised start rejection, duplicate enablement rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose spawn enablement readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare final process spawn disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_process_spawn_enablement_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-PROCESS-SPAWN-ENABLEMENT-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex process spawn enablement gate that authorizes the future spawn step while still refusing to start Codex.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'spawn_enablement_gate_requires_supervised_start_prepared_metadata',
                'spawn_enablement_gate_requires_operator_spawn_receipt_hash',
                'spawn_enablement_gate_requires_supervised_start_contract_hash',
                'spawn_enablement_gate_is_idempotent_for_same_enablement_id',
                'spawn_enablement_gate_records_enablement_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_modify_file_outside_allowed_files',
                'need_to_allow_provider_token_spend',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_spawn_enablement_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_process_spawn_enablement_implementation',
            'mode' => 'read_only_agent_codex_process_spawn_enablement_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_enablement_implementation_packet' => $packet,
            'codex_process_spawn_enablement_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_process_spawn_enablement_implementation_packet_does_not_start_codex',
                'agent_codex_process_spawn_enablement_implementation_packet_does_not_call_codex',
                'agent_codex_process_spawn_enablement_implementation_packet_does_not_spend_tokens',
                'agent_codex_process_spawn_enablement_implementation_packet_does_not_create_spawn_files',
                'agent_codex_process_spawn_enablement_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex process spawn enablement implementation packet is ready; it defines scoped enablement work but does not create files, start Codex or spend tokens.',
        ];
    }


}
