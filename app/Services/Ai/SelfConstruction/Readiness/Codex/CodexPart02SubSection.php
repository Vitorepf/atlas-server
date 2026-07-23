<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvokerDryRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessRuntimeDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnExecutor;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 02 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexProcessSpawnExecutorContractTemplate
 *           .. agentCodexExternalProcessInvokerDryRunImplementationPacket
 */
final class CodexPart02SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexProcessSpawnExecutorContractTemplate(array $options = []): array
    {
        $enablementPayload = $this->section->agentCodexProcessSpawnEnablementPreflight($options);

        $template = [
            'status' => 'codex_process_spawn_executor_contract_template_ready',
            'contract_id' => 'CODEX-PROCESS-SPAWN-EXECUTOR-'.strtoupper(substr(ReadinessHash::stable([
                'codex_process_spawn_enablement_preflight_hash' => data_get($enablementPayload, 'codex_process_spawn_enablement_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_process_spawn_enablement_status' => data_get($enablementPayload, 'status'),
            'source_codex_process_spawn_enablement_preflight_hash' => data_get($enablementPayload, 'codex_process_spawn_enablement_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexProcessSpawnExecutor',
                'method' => 'prepareProcessSpawn',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'operator_final_spawn_receipt_hash',
                    'runtime_supervision_plan_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'spawn_executor_id',
                    'spawn_enablement_id',
                    'supervised_start_id',
                    'process_start_release_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'process_spawn_executor_prepared',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'executor_must' => [
                'require_codex_process_spawn_enablement_recorded',
                'require_operator_final_spawn_receipt_hash',
                'require_runtime_supervision_plan_hash',
                'require_stdout_stderr_sink_hash',
                'require_liveness_probe_hash',
                'record_append_only_spawn_executor_prepared_event_before_any_process_start',
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
                'app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnExecutor.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessSpawnExecutorTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'spawn_executor_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-process-spawn-executor-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_spawn_executor_contract_template.v1',
            'status' => 'codex_process_spawn_executor_contract_template_ready',
            'mode' => 'read_only_agent_codex_process_spawn_executor_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_executor_contract_template' => $template,
            'codex_process_spawn_executor_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_process_spawn_executor_contract_template_does_not_start_codex',
                'agent_codex_process_spawn_executor_contract_template_does_not_call_codex',
                'agent_codex_process_spawn_executor_contract_template_does_not_spend_tokens',
                'agent_codex_process_spawn_executor_contract_template_does_not_create_spawn_files',
                'agent_codex_process_spawn_executor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex process spawn executor contract template defines the final prepared-spawn shell without starting Codex or spending tokens.',
        ];
    }


public function agentCodexProcessSpawnExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexProcessSpawnExecutorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_process_spawn_executor_contract_template', []);
        $executorClass = AgentCodexProcessSpawnExecutor::class;
        $enablementGateClass = AgentCodexProcessSpawnEnablementGate::class;
        $executorReady = class_exists($executorClass);
        $enablementGateReady = class_exists($enablementGateClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $executorReady ? null : 'codex_process_spawn_executor_missing',
            $enablementGateReady ? null : 'codex_process_spawn_enablement_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_process_spawn_executor_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_process_spawn_executor_contract_template_hash'),
            'source_codex_process_spawn_enablement_status' => data_get($contract, 'source_codex_process_spawn_enablement_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_process_spawn_executor_ready' => $executorReady,
                'codex_process_spawn_enablement_gate_ready' => $enablementGateReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'process_spawn_executor_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_process_spawn_executor',
                'require_codex_process_spawn_enablement_recorded_metadata',
                'require_final_spawn_receipt_supervision_sink_and_liveness_hashes',
                'record_spawn_executor_prepared_event_without_starting_codex',
                'keep_real_process_invocation_for_later_runtime_driver',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnExecutor.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessSpawnExecutorTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_process_spawn_executor',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'spawn_executor_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-process-spawn-executor-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_process_spawn_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_process_spawn_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_executor_preflight' => $preflight,
            'codex_process_spawn_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_process_spawn_executor_preflight_does_not_start_codex',
                'agent_codex_process_spawn_executor_preflight_does_not_call_codex',
                'agent_codex_process_spawn_executor_preflight_does_not_spend_tokens',
                'agent_codex_process_spawn_executor_preflight_does_not_create_spawn_files',
                'agent_codex_process_spawn_executor_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex process spawn executor preflight is ready; real process invocation remains disabled until a runtime driver.'
                : 'Codex process spawn executor preflight is blocked until the executor and runtime storage prerequisites exist.',
        ];
    }


public function agentCodexProcessSpawnExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexProcessSpawnExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_process_spawn_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_process_spawn_executor_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex process spawn executor shell',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnExecutor.php'],
                'acceptance' => 'Executor prepares final spawn metadata and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce final spawn supervision artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexProcessSpawnExecutor.php'],
                'acceptance' => 'Executor requires spawn enablement metadata, final spawn receipt, supervision plan, output sink and liveness probe hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add process spawn executor tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexProcessSpawnExecutorTest.php'],
                'acceptance' => 'Tests prove missing enablement rejection, duplicate executor rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose process spawn executor readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare real process invocation disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_process_spawn_executor_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-PROCESS-SPAWN-EXECUTOR-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex process spawn executor shell that prepares the final spawn stage while still refusing to invoke Codex.',
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
                'spawn_executor_requires_spawn_enablement_recorded_metadata',
                'spawn_executor_requires_operator_final_spawn_receipt_hash',
                'spawn_executor_requires_supervision_sink_and_liveness_hashes',
                'spawn_executor_is_idempotent_for_same_executor_id',
                'spawn_executor_records_prepared_event_without_starting_codex',
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
            'schema_version' => 'atlas.self_construction_agent_codex_process_spawn_executor_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_process_spawn_executor_implementation',
            'mode' => 'read_only_agent_codex_process_spawn_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_executor_implementation_packet' => $packet,
            'codex_process_spawn_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_process_spawn_executor_implementation_packet_does_not_start_codex',
                'agent_codex_process_spawn_executor_implementation_packet_does_not_call_codex',
                'agent_codex_process_spawn_executor_implementation_packet_does_not_spend_tokens',
                'agent_codex_process_spawn_executor_implementation_packet_does_not_create_spawn_files',
                'agent_codex_process_spawn_executor_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex process spawn executor implementation packet is ready; it defines scoped executor work but does not create files, start Codex or spend tokens.',
        ];
    }


public function agentCodexExternalProcessRuntimeDriverContractTemplate(array $options = []): array
    {
        $spawnPayload = $this->section->agentCodexProcessSpawnExecutorPreflight($options);

        $template = [
            'status' => 'codex_external_process_runtime_driver_contract_template_ready',
            'contract_id' => 'CODEX-EXTERNAL-PROCESS-RUNTIME-'.strtoupper(substr(ReadinessHash::stable([
                'codex_process_spawn_executor_preflight_hash' => data_get($spawnPayload, 'codex_process_spawn_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_process_spawn_executor_status' => data_get($spawnPayload, 'status'),
            'source_codex_process_spawn_executor_preflight_hash' => data_get($spawnPayload, 'codex_process_spawn_executor_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexExternalProcessRuntimeDriver',
                'method' => 'prepareExternalRuntime',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'runtime_driver_id',
                    'operator_runtime_receipt_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'runtime_driver_id',
                    'spawn_executor_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'external_runtime_driver_prepared',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'driver_must' => [
                'require_codex_process_spawn_executor_prepared',
                'require_operator_runtime_receipt_hash',
                'require_process_command_hash',
                'require_environment_contract_hash',
                'require_termination_policy_hash',
                'record_append_only_external_runtime_prepared_event_before_any_process_invocation',
            ],
            'driver_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexExternalProcessRuntimeDriver.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessRuntimeDriverTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'external_runtime_driver_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-external-process-runtime-driver-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_runtime_driver_contract_template.v1',
            'status' => 'codex_external_process_runtime_driver_contract_template_ready',
            'mode' => 'read_only_agent_codex_external_process_runtime_driver_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_runtime_driver_contract_template' => $template,
            'codex_external_process_runtime_driver_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_external_process_runtime_driver_contract_template_does_not_start_codex',
                'agent_codex_external_process_runtime_driver_contract_template_does_not_call_codex',
                'agent_codex_external_process_runtime_driver_contract_template_does_not_spend_tokens',
                'agent_codex_external_process_runtime_driver_contract_template_does_not_create_runtime_files',
                'agent_codex_external_process_runtime_driver_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex external process runtime driver contract template defines the prepared runtime shell without starting Codex or spending tokens.',
        ];
    }


public function agentCodexExternalProcessRuntimeDriverPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexExternalProcessRuntimeDriverContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_external_process_runtime_driver_contract_template', []);
        $driverClass = AgentCodexExternalProcessRuntimeDriver::class;
        $spawnExecutorClass = AgentCodexProcessSpawnExecutor::class;
        $driverReady = class_exists($driverClass);
        $spawnExecutorReady = class_exists($spawnExecutorClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $driverReady ? null : 'codex_external_process_runtime_driver_missing',
            $spawnExecutorReady ? null : 'codex_process_spawn_executor_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_external_process_runtime_driver_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_external_process_runtime_driver_contract_template_hash'),
            'source_codex_process_spawn_executor_status' => data_get($contract, 'source_codex_process_spawn_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_external_process_runtime_driver_ready' => $driverReady,
                'codex_process_spawn_executor_ready' => $spawnExecutorReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'external_runtime_driver_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_external_process_runtime_driver',
                'require_codex_process_spawn_executor_prepared_metadata',
                'require_runtime_receipt_command_environment_and_termination_hashes',
                'record_external_runtime_prepared_event_without_starting_codex',
                'keep_real_process_invocation_for_later_invoker_contract',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexExternalProcessRuntimeDriver.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessRuntimeDriverTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_external_process_runtime_driver',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'runtime_driver_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-external-process-runtime-driver-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_runtime_driver_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_external_process_runtime_driver_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_runtime_driver_preflight' => $preflight,
            'codex_external_process_runtime_driver_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_external_process_runtime_driver_preflight_does_not_start_codex',
                'agent_codex_external_process_runtime_driver_preflight_does_not_call_codex',
                'agent_codex_external_process_runtime_driver_preflight_does_not_spend_tokens',
                'agent_codex_external_process_runtime_driver_preflight_does_not_create_runtime_files',
                'agent_codex_external_process_runtime_driver_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex external process runtime driver preflight is ready; real process invocation remains disabled until a later invoker contract.'
                : 'Codex external process runtime driver preflight is blocked until the driver and runtime storage prerequisites exist.',
        ];
    }


public function agentCodexExternalProcessRuntimeDriverImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexExternalProcessRuntimeDriverPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_external_process_runtime_driver_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_external_process_runtime_driver_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex external process runtime driver shell',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexExternalProcessRuntimeDriver.php'],
                'acceptance' => 'Driver prepares external runtime metadata and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce external runtime artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexExternalProcessRuntimeDriver.php'],
                'acceptance' => 'Driver requires spawn executor metadata, runtime receipt hash, command hash, environment hash and termination policy hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add external runtime driver tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessRuntimeDriverTest.php'],
                'acceptance' => 'Tests prove missing spawn executor rejection, duplicate runtime rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose external runtime driver readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare real process invocation disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_external_process_runtime_driver_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-EXTERNAL-PROCESS-RUNTIME-DRIVER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex external process runtime driver shell that prepares runtime metadata while still refusing to invoke Codex.',
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
                'runtime_driver_requires_spawn_executor_prepared_metadata',
                'runtime_driver_requires_runtime_receipt_hash',
                'runtime_driver_requires_command_environment_and_termination_hashes',
                'runtime_driver_is_idempotent_for_same_runtime_id',
                'runtime_driver_records_prepared_event_without_starting_codex',
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
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_runtime_driver_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_external_process_runtime_driver_implementation',
            'mode' => 'read_only_agent_codex_external_process_runtime_driver_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_runtime_driver_implementation_packet' => $packet,
            'codex_external_process_runtime_driver_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_external_process_runtime_driver_implementation_packet_does_not_start_codex',
                'agent_codex_external_process_runtime_driver_implementation_packet_does_not_call_codex',
                'agent_codex_external_process_runtime_driver_implementation_packet_does_not_spend_tokens',
                'agent_codex_external_process_runtime_driver_implementation_packet_does_not_create_runtime_files',
                'agent_codex_external_process_runtime_driver_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex external process runtime driver implementation packet is ready; it defines scoped runtime work but does not create files, start Codex or spend tokens.',
        ];
    }


public function agentCodexExternalProcessInvocationAuthorizationContractTemplate(array $options = []): array
    {
        $runtimePayload = $this->section->agentCodexExternalProcessRuntimeDriverPreflight($options);

        $template = [
            'status' => 'codex_external_process_invocation_authorization_contract_template_ready',
            'contract_id' => 'CODEX-EXTERNAL-PROCESS-INVOCATION-AUTH-'.strtoupper(substr(ReadinessHash::stable([
                'codex_external_process_runtime_driver_preflight_hash' => data_get($runtimePayload, 'codex_external_process_runtime_driver_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_external_process_runtime_driver_status' => data_get($runtimePayload, 'status'),
            'source_codex_external_process_runtime_driver_preflight_hash' => data_get($runtimePayload, 'codex_external_process_runtime_driver_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexExternalProcessInvocationAuthorizationGate',
                'method' => 'authorizeExternalProcessInvocation',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'runtime_driver_id',
                    'invocation_authorization_id',
                    'operator_invocation_receipt_hash',
                    'runtime_driver_contract_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'invocation_authorization_id',
                    'runtime_driver_id',
                    'spawn_executor_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'external_process_invocation_authorized',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'authorization_must' => [
                'require_codex_external_process_runtime_driver_prepared',
                'require_operator_invocation_receipt_hash',
                'require_runtime_driver_contract_hash',
                'require_process_command_hash',
                'require_environment_contract_hash',
                'require_termination_policy_hash',
                'record_append_only_external_process_invocation_authorized_event_before_any_real_invocation',
            ],
            'authorization_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvocationAuthorizationGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessInvocationAuthorizationGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'external_process_invocation_authorization_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-external-process-invocation-authorization-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_invocation_authorization_contract_template.v1',
            'status' => 'codex_external_process_invocation_authorization_contract_template_ready',
            'mode' => 'read_only_agent_codex_external_process_invocation_authorization_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invocation_authorization_contract_template' => $template,
            'codex_external_process_invocation_authorization_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_external_process_invocation_authorization_contract_template_does_not_start_codex',
                'agent_codex_external_process_invocation_authorization_contract_template_does_not_call_codex',
                'agent_codex_external_process_invocation_authorization_contract_template_does_not_spend_tokens',
                'agent_codex_external_process_invocation_authorization_contract_template_does_not_create_invocation_files',
                'agent_codex_external_process_invocation_authorization_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex external process invocation authorization contract template defines the final authorization gate before a future invoker; it does not start Codex or spend tokens.',
        ];
    }


public function agentCodexExternalProcessInvocationAuthorizationPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexExternalProcessInvocationAuthorizationContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_external_process_invocation_authorization_contract_template', []);
        $authorizationClass = AgentCodexExternalProcessInvocationAuthorizationGate::class;
        $runtimeDriverClass = AgentCodexExternalProcessRuntimeDriver::class;
        $authorizationReady = class_exists($authorizationClass);
        $runtimeDriverReady = class_exists($runtimeDriverClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $authorizationReady ? null : 'codex_external_process_invocation_authorization_gate_missing',
            $runtimeDriverReady ? null : 'codex_external_process_runtime_driver_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_external_process_invocation_authorization_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_external_process_invocation_authorization_contract_template_hash'),
            'source_codex_external_process_runtime_driver_status' => data_get($contract, 'source_codex_external_process_runtime_driver_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_external_process_invocation_authorization_gate_ready' => $authorizationReady,
                'codex_external_process_runtime_driver_ready' => $runtimeDriverReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'invocation_authorization_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_external_process_invocation_authorization_gate',
                'require_codex_external_process_runtime_driver_prepared_metadata',
                'require_operator_invocation_receipt_hash',
                'require_runtime_driver_contract_command_environment_and_termination_hashes',
                'record_external_process_invocation_authorized_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvocationAuthorizationGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessInvocationAuthorizationGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_external_process_invocation_authorization',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'invocation_authorization_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-external-process-invocation-authorization-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_invocation_authorization_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_external_process_invocation_authorization_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invocation_authorization_preflight' => $preflight,
            'codex_external_process_invocation_authorization_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_external_process_invocation_authorization_preflight_does_not_start_codex',
                'agent_codex_external_process_invocation_authorization_preflight_does_not_call_codex',
                'agent_codex_external_process_invocation_authorization_preflight_does_not_spend_tokens',
                'agent_codex_external_process_invocation_authorization_preflight_does_not_create_invocation_files',
                'agent_codex_external_process_invocation_authorization_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex external process invocation authorization preflight is ready; real process invocation remains disabled until a later invoker.'
                : 'Codex external process invocation authorization preflight is blocked until the authorization gate and runtime prerequisites exist.',
        ];
    }


public function agentCodexExternalProcessInvocationAuthorizationImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexExternalProcessInvocationAuthorizationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_external_process_invocation_authorization_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_external_process_invocation_authorization_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex external process invocation authorization gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvocationAuthorizationGate.php'],
                'acceptance' => 'Gate records invocation authorization metadata and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce runtime driver and invocation artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvocationAuthorizationGate.php'],
                'acceptance' => 'Gate requires prepared runtime driver metadata, invocation receipt hash, runtime contract hash, command hash, environment hash and termination policy hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add invocation authorization gate tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessInvocationAuthorizationGateTest.php'],
                'acceptance' => 'Tests prove missing runtime driver rejection, duplicate authorization rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose invocation authorization readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare real process invocation disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_external_process_invocation_authorization_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-EXTERNAL-PROCESS-INVOCATION-AUTHORIZATION-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex external process invocation authorization gate that records final authorization while still refusing to invoke Codex.',
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
                'invocation_authorization_requires_external_runtime_driver_prepared_metadata',
                'invocation_authorization_requires_operator_invocation_receipt_hash',
                'invocation_authorization_requires_runtime_driver_contract_hash',
                'invocation_authorization_is_idempotent_for_same_authorization_id',
                'invocation_authorization_records_authorized_event_without_starting_codex',
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
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_invocation_authorization_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_external_process_invocation_authorization_implementation',
            'mode' => 'read_only_agent_codex_external_process_invocation_authorization_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invocation_authorization_implementation_packet' => $packet,
            'codex_external_process_invocation_authorization_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_external_process_invocation_authorization_implementation_packet_does_not_start_codex',
                'agent_codex_external_process_invocation_authorization_implementation_packet_does_not_call_codex',
                'agent_codex_external_process_invocation_authorization_implementation_packet_does_not_spend_tokens',
                'agent_codex_external_process_invocation_authorization_implementation_packet_does_not_create_invocation_files',
                'agent_codex_external_process_invocation_authorization_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex external process invocation authorization implementation packet is ready; it defines scoped authorization work but does not create files, start Codex or spend tokens.',
        ];
    }


public function agentCodexExternalProcessInvokerDryRunContractTemplate(array $options = []): array
    {
        $authorizationPayload = $this->section->agentCodexExternalProcessInvocationAuthorizationPreflight($options);

        $template = [
            'status' => 'codex_external_process_invoker_dry_run_contract_template_ready',
            'contract_id' => 'CODEX-EXTERNAL-PROCESS-INVOKER-DRY-RUN-'.strtoupper(substr(ReadinessHash::stable([
                'codex_external_process_invocation_authorization_preflight_hash' => data_get($authorizationPayload, 'codex_external_process_invocation_authorization_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_external_process_invocation_authorization_status' => data_get($authorizationPayload, 'status'),
            'source_codex_external_process_invocation_authorization_preflight_hash' => data_get($authorizationPayload, 'codex_external_process_invocation_authorization_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexExternalProcessInvokerDryRun',
                'method' => 'prepareDryRun',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'runtime_driver_id',
                    'invocation_authorization_id',
                    'dry_run_id',
                    'operator_dry_run_receipt_hash',
                    'invoker_contract_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'dry_run_id',
                    'invocation_authorization_id',
                    'runtime_driver_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'external_process_invoker_dry_run_prepared',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'dry_run_must' => [
                'require_codex_external_process_invocation_authorization_recorded',
                'require_operator_dry_run_receipt_hash',
                'require_invoker_contract_hash',
                'require_process_command_hash',
                'require_environment_contract_hash',
                'require_termination_policy_hash',
                'require_stdout_stderr_sink_hash',
                'require_liveness_probe_hash',
                'record_append_only_invoker_dry_run_prepared_event_before_any_real_invocation',
            ],
            'dry_run_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvokerDryRun.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessInvokerDryRunTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'external_process_invoker_dry_run_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-external-process-invoker-dry-run-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_invoker_dry_run_contract_template.v1',
            'status' => 'codex_external_process_invoker_dry_run_contract_template_ready',
            'mode' => 'read_only_agent_codex_external_process_invoker_dry_run_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_contract_template' => $template,
            'codex_external_process_invoker_dry_run_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_external_process_invoker_dry_run_contract_template_does_not_start_codex',
                'agent_codex_external_process_invoker_dry_run_contract_template_does_not_call_codex',
                'agent_codex_external_process_invoker_dry_run_contract_template_does_not_spend_tokens',
                'agent_codex_external_process_invoker_dry_run_contract_template_does_not_create_invoker_files',
                'agent_codex_external_process_invoker_dry_run_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex external process invoker dry-run contract template defines the future invoker simulation without starting Codex or spending tokens.',
        ];
    }


public function agentCodexExternalProcessInvokerDryRunPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexExternalProcessInvokerDryRunContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_external_process_invoker_dry_run_contract_template', []);
        $dryRunClass = AgentCodexExternalProcessInvokerDryRun::class;
        $authorizationClass = AgentCodexExternalProcessInvocationAuthorizationGate::class;
        $dryRunReady = class_exists($dryRunClass);
        $authorizationReady = class_exists($authorizationClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $dryRunReady ? null : 'codex_external_process_invoker_dry_run_missing',
            $authorizationReady ? null : 'codex_external_process_invocation_authorization_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_external_process_invoker_dry_run_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_external_process_invoker_dry_run_contract_template_hash'),
            'source_codex_external_process_invocation_authorization_status' => data_get($contract, 'source_codex_external_process_invocation_authorization_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_external_process_invoker_dry_run_ready' => $dryRunReady,
                'codex_external_process_invocation_authorization_gate_ready' => $authorizationReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'invoker_dry_run_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_external_process_invoker_dry_run',
                'require_codex_external_process_invocation_authorization_recorded_metadata',
                'require_operator_dry_run_and_invoker_contract_hashes',
                'require_stdout_stderr_sink_and_liveness_probe_hashes',
                'record_invoker_dry_run_prepared_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvokerDryRun.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessInvokerDryRunTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_external_process_invoker_dry_run',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'invoker_dry_run_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-external-process-invoker-dry-run-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_invoker_dry_run_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_external_process_invoker_dry_run_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_preflight' => $preflight,
            'codex_external_process_invoker_dry_run_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_external_process_invoker_dry_run_preflight_does_not_start_codex',
                'agent_codex_external_process_invoker_dry_run_preflight_does_not_call_codex',
                'agent_codex_external_process_invoker_dry_run_preflight_does_not_spend_tokens',
                'agent_codex_external_process_invoker_dry_run_preflight_does_not_create_invoker_files',
                'agent_codex_external_process_invoker_dry_run_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex external process invoker dry-run preflight is ready; real process invocation remains disabled until a later signed invoker release.'
                : 'Codex external process invoker dry-run preflight is blocked until the dry-run service and authorization prerequisites exist.',
        ];
    }


public function agentCodexExternalProcessInvokerDryRunImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexExternalProcessInvokerDryRunPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_external_process_invoker_dry_run_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_external_process_invoker_dry_run_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex external process invoker dry-run',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvokerDryRun.php'],
                'acceptance' => 'Dry-run records invoker plan metadata and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce authorization and dry-run artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvokerDryRun.php'],
                'acceptance' => 'Dry-run requires invocation authorization metadata, dry-run receipt hash, invoker contract hash, command/environment/termination hashes and observability hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add invoker dry-run tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexExternalProcessInvokerDryRunTest.php'],
                'acceptance' => 'Tests prove missing authorization rejection, duplicate dry-run rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose invoker dry-run readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare real process invocation disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_external_process_invoker_dry_run_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-EXTERNAL-PROCESS-INVOKER-DRY-RUN-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex external process invoker dry-run that records future invoker readiness while still refusing to invoke Codex.',
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
                'invoker_dry_run_requires_invocation_authorization_metadata',
                'invoker_dry_run_requires_operator_dry_run_receipt_hash',
                'invoker_dry_run_requires_invoker_contract_hash',
                'invoker_dry_run_is_idempotent_for_same_dry_run_id',
                'invoker_dry_run_records_prepared_event_without_starting_codex',
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
            'schema_version' => 'atlas.self_construction_agent_codex_external_process_invoker_dry_run_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_external_process_invoker_dry_run_implementation',
            'mode' => 'read_only_agent_codex_external_process_invoker_dry_run_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_implementation_packet' => $packet,
            'codex_external_process_invoker_dry_run_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_external_process_invoker_dry_run_implementation_packet_does_not_start_codex',
                'agent_codex_external_process_invoker_dry_run_implementation_packet_does_not_call_codex',
                'agent_codex_external_process_invoker_dry_run_implementation_packet_does_not_spend_tokens',
                'agent_codex_external_process_invoker_dry_run_implementation_packet_does_not_create_invoker_files',
                'agent_codex_external_process_invoker_dry_run_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex external process invoker dry-run implementation packet is ready; it defines scoped dry-run work but does not create files, start Codex or spend tokens.',
        ];
    }


}
