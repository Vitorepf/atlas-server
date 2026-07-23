<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvokerDryRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorPlan;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerImplementationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 03 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerReleasePreflightContractTemplate
 *           .. agentCodexRealInvokerExecutorPlanImplementationPacket
 */
final class CodexPart03SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerReleasePreflightContractTemplate(array $options = []): array
    {
        $dryRunPayload = $this->section->agentCodexExternalProcessInvokerDryRunPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_release_preflight_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-RELEASE-PREFLIGHT-'.strtoupper(substr(ReadinessHash::stable([
                'codex_external_process_invoker_dry_run_preflight_hash' => data_get($dryRunPayload, 'codex_external_process_invoker_dry_run_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_external_process_invoker_dry_run_status' => data_get($dryRunPayload, 'status'),
            'source_codex_external_process_invoker_dry_run_preflight_hash' => data_get($dryRunPayload, 'codex_external_process_invoker_dry_run_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerReleasePreflight',
                'method' => 'recordPreflight',
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
                    'real_invoker_release_preflight_id',
                    'operator_release_preflight_receipt_hash',
                    'real_invoker_contract_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'rollback_plan_hash',
                    'max_runtime_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_release_preflight_id',
                    'dry_run_id',
                    'invocation_authorization_id',
                    'runtime_driver_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_release_preflight_passed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_release_preflight_must' => [
                'require_codex_external_process_invoker_dry_run_prepared',
                'require_operator_release_preflight_receipt_hash',
                'require_real_invoker_contract_hash',
                'require_process_command_hash',
                'require_environment_contract_hash',
                'require_termination_policy_hash',
                'require_stdout_stderr_sink_hash',
                'require_liveness_probe_hash',
                'require_rollback_plan_hash',
                'require_max_runtime_policy_hash',
                'record_append_only_real_invoker_release_preflight_event_before_any_real_invocation',
            ],
            'real_invoker_release_preflight_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerReleasePreflight.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerReleasePreflightTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'real_invoker_release_preflight_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-release-preflight-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_release_preflight_contract_template.v1',
            'status' => 'codex_real_invoker_release_preflight_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_release_preflight_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_contract_template' => $template,
            'codex_real_invoker_release_preflight_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_release_preflight_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_release_preflight_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_release_preflight_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_release_preflight_contract_template_does_not_create_release_files',
                'agent_codex_real_invoker_release_preflight_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker release preflight contract template defines the final pre-release gate without starting Codex or spending tokens.',
        ];
    }


public function agentCodexRealInvokerReleasePreflightPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerReleasePreflightContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_release_preflight_contract_template', []);
        $preflightClass = AgentCodexRealInvokerReleasePreflight::class;
        $dryRunClass = AgentCodexExternalProcessInvokerDryRun::class;
        $preflightReady = class_exists($preflightClass);
        $dryRunReady = class_exists($dryRunClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $preflightReady ? null : 'codex_real_invoker_release_preflight_missing',
            $dryRunReady ? null : 'codex_external_process_invoker_dry_run_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_release_preflight_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_release_preflight_contract_template_hash'),
            'source_codex_external_process_invoker_dry_run_status' => data_get($contract, 'source_codex_external_process_invoker_dry_run_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_release_preflight_ready' => $preflightReady,
                'codex_external_process_invoker_dry_run_ready' => $dryRunReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'real_invoker_release_preflight_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_release_preflight',
                'require_codex_external_process_invoker_dry_run_prepared_metadata',
                'require_operator_release_preflight_and_real_invoker_contract_hashes',
                'require_rollback_and_max_runtime_policy_hashes',
                'record_real_invoker_release_preflight_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerReleasePreflight.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerReleasePreflightTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_release_preflight',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'real_invoker_release_preflight_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-release-preflight-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_release_preflight_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_release_preflight_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_preflight' => $preflight,
            'codex_real_invoker_release_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_release_preflight_preflight_does_not_start_codex',
                'agent_codex_real_invoker_release_preflight_preflight_does_not_call_codex',
                'agent_codex_real_invoker_release_preflight_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_release_preflight_preflight_does_not_create_release_files',
                'agent_codex_real_invoker_release_preflight_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker release preflight is ready; real process invocation remains disabled until a later signed release.'
                : 'Codex real invoker release preflight is blocked until the release preflight service and dry-run prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerReleasePreflightImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerReleasePreflightPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_release_preflight_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_release_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker release preflight',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerReleasePreflight.php'],
                'acceptance' => 'Release preflight records future real invoker readiness and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce dry-run and release artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerReleasePreflight.php'],
                'acceptance' => 'Release preflight requires invoker dry-run metadata, release receipt hash, real invoker contract hash, rollback plan hash and max runtime policy hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add real invoker release preflight tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerReleasePreflightTest.php'],
                'acceptance' => 'Tests prove missing dry-run rejection, duplicate release preflight rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose real invoker release preflight readiness commands',
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
            'status' => 'ready_for_scoped_codex_real_invoker_release_preflight_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-RELEASE-PREFLIGHT-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker release preflight that records future real invocation readiness while still refusing to invoke Codex.',
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
                'real_invoker_release_preflight_requires_invoker_dry_run_metadata',
                'real_invoker_release_preflight_requires_operator_release_preflight_receipt_hash',
                'real_invoker_release_preflight_requires_real_invoker_contract_hash',
                'real_invoker_release_preflight_is_idempotent_for_same_preflight_id',
                'real_invoker_release_preflight_records_preflight_event_without_starting_codex',
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
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_release_preflight_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_release_preflight_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_release_preflight_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_implementation_packet' => $packet,
            'codex_real_invoker_release_preflight_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_release_preflight_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_release_preflight_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_release_preflight_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_release_preflight_implementation_packet_does_not_create_release_files',
                'agent_codex_real_invoker_release_preflight_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker release preflight implementation packet is ready; it defines scoped pre-release work but does not create release files, start Codex or spend tokens.',
        ];
    }


public function agentCodexSignedRealInvokerReleaseGateContractTemplate(array $options = []): array
    {
        $releasePreflightPayload = $this->section->agentCodexRealInvokerReleasePreflightPreflight($options);

        $template = [
            'status' => 'codex_signed_real_invoker_release_gate_contract_template_ready',
            'contract_id' => 'CODEX-SIGNED-REAL-INVOKER-RELEASE-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_release_preflight_hash' => data_get($releasePreflightPayload, 'codex_real_invoker_release_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_release_preflight_status' => data_get($releasePreflightPayload, 'status'),
            'source_codex_real_invoker_release_preflight_hash' => data_get($releasePreflightPayload, 'codex_real_invoker_release_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexSignedRealInvokerReleaseGate',
                'method' => 'authorizeSignedRelease',
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
                    'real_invoker_release_preflight_id',
                    'signed_real_invoker_release_id',
                    'operator_signed_release_receipt_hash',
                    'signature_verification_report_hash',
                    'real_invoker_contract_hash',
                    'release_policy_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'rollback_plan_hash',
                    'max_runtime_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'signed_real_invoker_release_id',
                    'real_invoker_release_preflight_id',
                    'dry_run_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'signed_real_invoker_release_authorized',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'signed_real_invoker_release_gate_must' => [
                'require_codex_real_invoker_release_preflight_passed',
                'require_operator_signed_release_receipt_hash',
                'require_signature_verification_report_hash',
                'require_real_invoker_contract_hash',
                'require_release_policy_hash',
                'require_rollback_and_max_runtime_policy_hashes',
                'record_append_only_signed_real_invoker_release_authorized_event_before_any_real_invocation',
            ],
            'signed_real_invoker_release_gate_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexSignedRealInvokerReleaseGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexSignedRealInvokerReleaseGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'signed_real_invoker_release_gate_implementation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-signed-real-invoker-release-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_signed_real_invoker_release_gate_contract_template.v1',
            'status' => 'codex_signed_real_invoker_release_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_signed_real_invoker_release_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_contract_template' => $template,
            'codex_signed_real_invoker_release_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_signed_real_invoker_release_gate_contract_template_does_not_start_codex',
                'agent_codex_signed_real_invoker_release_gate_contract_template_does_not_call_codex',
                'agent_codex_signed_real_invoker_release_gate_contract_template_does_not_spend_tokens',
                'agent_codex_signed_real_invoker_release_gate_contract_template_does_not_create_release_files',
                'agent_codex_signed_real_invoker_release_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex signed real invoker release gate contract template defines signed authorization without starting Codex or spending tokens.',
        ];
    }


public function agentCodexSignedRealInvokerReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexSignedRealInvokerReleaseGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_signed_real_invoker_release_gate_contract_template', []);
        $gateClass = AgentCodexSignedRealInvokerReleaseGate::class;
        $preflightClass = AgentCodexRealInvokerReleasePreflight::class;
        $gateReady = class_exists($gateClass);
        $preflightReady = class_exists($preflightClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_signed_real_invoker_release_gate_missing',
            $preflightReady ? null : 'codex_real_invoker_release_preflight_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_signed_real_invoker_release_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_signed_real_invoker_release_gate_contract_template_hash'),
            'source_codex_real_invoker_release_preflight_status' => data_get($contract, 'source_codex_real_invoker_release_preflight_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_signed_real_invoker_release_gate_ready' => $gateReady,
                'codex_real_invoker_release_preflight_ready' => $preflightReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'signed_real_invoker_release_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_signed_real_invoker_release_gate',
                'require_codex_real_invoker_release_preflight_passed_metadata',
                'require_operator_signed_release_and_signature_verification_hashes',
                'require_release_policy_hash',
                'record_signed_real_invoker_release_authorized_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexSignedRealInvokerReleaseGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexSignedRealInvokerReleaseGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_signed_real_invoker_release_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'signed_real_invoker_release_gate_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-signed-real-invoker-release-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_signed_real_invoker_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_signed_real_invoker_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_preflight' => $preflight,
            'codex_signed_real_invoker_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_signed_real_invoker_release_gate_preflight_does_not_start_codex',
                'agent_codex_signed_real_invoker_release_gate_preflight_does_not_call_codex',
                'agent_codex_signed_real_invoker_release_gate_preflight_does_not_spend_tokens',
                'agent_codex_signed_real_invoker_release_gate_preflight_does_not_create_release_files',
                'agent_codex_signed_real_invoker_release_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex signed real invoker release gate is ready; real process invocation remains disabled until a later invoker implementation.'
                : 'Codex signed real invoker release gate is blocked until the gate service and release preflight prerequisites exist.',
        ];
    }


public function agentCodexSignedRealInvokerReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexSignedRealInvokerReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_signed_real_invoker_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_signed_real_invoker_release_gate_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex signed real invoker release gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexSignedRealInvokerReleaseGate.php'],
                'acceptance' => 'Signed release gate records authorization and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce signed release artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexSignedRealInvokerReleaseGate.php'],
                'acceptance' => 'Signed release gate requires release preflight metadata, signed receipt hash, signature verification report hash and release policy hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add signed real invoker release gate tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexSignedRealInvokerReleaseGateTest.php'],
                'acceptance' => 'Tests prove missing preflight rejection, duplicate signed release rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose signed release gate readiness commands',
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
            'status' => 'ready_for_scoped_codex_signed_real_invoker_release_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-SIGNED-REAL-INVOKER-RELEASE-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex signed real invoker release gate that authorizes a future invoker implementation while still refusing to invoke Codex.',
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
                'signed_real_invoker_release_gate_requires_real_invoker_release_preflight_metadata',
                'signed_real_invoker_release_gate_requires_operator_signed_release_receipt_hash',
                'signed_real_invoker_release_gate_requires_signature_verification_report_hash',
                'signed_real_invoker_release_gate_is_idempotent_for_same_release_id',
                'signed_real_invoker_release_gate_records_authorized_event_without_starting_codex',
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
            'schema_version' => 'atlas.self_construction_agent_codex_signed_real_invoker_release_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_signed_real_invoker_release_gate_implementation',
            'mode' => 'read_only_agent_codex_signed_real_invoker_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_implementation_packet' => $packet,
            'codex_signed_real_invoker_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_signed_real_invoker_release_gate_implementation_packet_does_not_start_codex',
                'agent_codex_signed_real_invoker_release_gate_implementation_packet_does_not_call_codex',
                'agent_codex_signed_real_invoker_release_gate_implementation_packet_does_not_spend_tokens',
                'agent_codex_signed_real_invoker_release_gate_implementation_packet_does_not_create_release_files',
                'agent_codex_signed_real_invoker_release_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex signed real invoker release gate implementation packet is ready; it defines scoped signed release work but does not create release files, start Codex or spend tokens.',
        ];
    }


public function agentCodexRealInvokerImplementationBoundaryContractTemplate(array $options = []): array
    {
        $signedGatePayload = $this->section->agentCodexSignedRealInvokerReleaseGatePreflight($options);

        $template = [
            'status' => 'codex_real_invoker_implementation_boundary_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-IMPLEMENTATION-BOUNDARY-'.strtoupper(substr(ReadinessHash::stable([
                'codex_signed_real_invoker_release_gate_preflight_hash' => data_get($signedGatePayload, 'codex_signed_real_invoker_release_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_signed_real_invoker_release_gate_status' => data_get($signedGatePayload, 'status'),
            'source_codex_signed_real_invoker_release_gate_preflight_hash' => data_get($signedGatePayload, 'codex_signed_real_invoker_release_gate_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerImplementationBoundary',
                'method' => 'prepareBoundary',
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
                    'real_invoker_release_preflight_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_implementation_boundary_id',
                    'operator_implementation_boundary_receipt_hash',
                    'real_invoker_contract_hash',
                    'release_policy_hash',
                    'implementation_plan_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'rollback_plan_hash',
                    'max_runtime_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_implementation_boundary_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_release_preflight_id',
                    'dry_run_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_implementation_boundary_prepared',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_implementation_boundary_must' => [
                'require_codex_signed_real_invoker_release_authorized',
                'require_operator_implementation_boundary_receipt_hash',
                'require_real_invoker_contract_hash',
                'require_release_policy_hash',
                'require_implementation_plan_hash',
                'require_runtime_observability_and_rollback_hashes',
                'record_append_only_real_invoker_implementation_boundary_event_before_any_real_invocation',
            ],
            'real_invoker_implementation_boundary_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
                'grant_unbounded_shell_or_provider_authority',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerImplementationBoundary.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerImplementationBoundaryTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'real_invoker_implementation_boundary_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-implementation-boundary-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_implementation_boundary_contract_template.v1',
            'status' => 'codex_real_invoker_implementation_boundary_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_implementation_boundary_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_contract_template' => $template,
            'codex_real_invoker_implementation_boundary_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_implementation_boundary_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_implementation_boundary_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_implementation_boundary_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_implementation_boundary_contract_template_does_not_create_boundary_files',
                'agent_codex_real_invoker_implementation_boundary_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker implementation boundary contract template defines the implementation envelope without starting Codex or spending tokens.',
        ];
    }


public function agentCodexRealInvokerImplementationBoundaryPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerImplementationBoundaryContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_implementation_boundary_contract_template', []);
        $boundaryClass = AgentCodexRealInvokerImplementationBoundary::class;
        $signedGateClass = AgentCodexSignedRealInvokerReleaseGate::class;
        $boundaryReady = class_exists($boundaryClass);
        $signedGateReady = class_exists($signedGateClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $boundaryReady ? null : 'codex_real_invoker_implementation_boundary_missing',
            $signedGateReady ? null : 'codex_signed_real_invoker_release_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_implementation_boundary_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_implementation_boundary_contract_template_hash'),
            'source_codex_signed_real_invoker_release_gate_status' => data_get($contract, 'source_codex_signed_real_invoker_release_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
                'codex_signed_real_invoker_release_gate_ready' => $signedGateReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'real_invoker_implementation_boundary_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_implementation_boundary',
                'require_codex_signed_real_invoker_release_authorized_metadata',
                'require_operator_implementation_boundary_receipt_hash',
                'require_implementation_plan_hash',
                'record_real_invoker_implementation_boundary_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerImplementationBoundary.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerImplementationBoundaryTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_implementation_boundary',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'real_invoker_implementation_boundary_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-implementation-boundary-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_implementation_boundary_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_implementation_boundary_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_preflight' => $preflight,
            'codex_real_invoker_implementation_boundary_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_implementation_boundary_preflight_does_not_start_codex',
                'agent_codex_real_invoker_implementation_boundary_preflight_does_not_call_codex',
                'agent_codex_real_invoker_implementation_boundary_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_implementation_boundary_preflight_does_not_create_boundary_files',
                'agent_codex_real_invoker_implementation_boundary_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker implementation boundary is ready; real executor remains disabled until a later explicit executor stage.'
                : 'Codex real invoker implementation boundary is blocked until the boundary service and signed release prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerImplementationBoundaryImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerImplementationBoundaryPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_implementation_boundary_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_implementation_boundary_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker implementation boundary',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerImplementationBoundary.php'],
                'acceptance' => 'Boundary records implementation envelope and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce signed release and implementation artifacts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerImplementationBoundary.php'],
                'acceptance' => 'Boundary requires signed release metadata, implementation boundary receipt hash and implementation plan hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add real invoker implementation boundary tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerImplementationBoundaryTest.php'],
                'acceptance' => 'Tests prove missing signed release rejection, duplicate boundary rejection, rollback and no Codex/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose real invoker implementation boundary readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare real executor disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_implementation_boundary_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-IMPLEMENTATION-BOUNDARY-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker implementation boundary that prepares a future executor envelope while still refusing to invoke Codex.',
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
                'real_invoker_implementation_boundary_requires_signed_real_invoker_release_metadata',
                'real_invoker_implementation_boundary_requires_operator_boundary_receipt_hash',
                'real_invoker_implementation_boundary_requires_implementation_plan_hash',
                'real_invoker_implementation_boundary_is_idempotent_for_same_boundary_id',
                'real_invoker_implementation_boundary_records_prepared_event_without_starting_codex',
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
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_implementation_boundary_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_implementation_boundary_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_implementation_boundary_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_implementation_packet' => $packet,
            'codex_real_invoker_implementation_boundary_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_implementation_boundary_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_implementation_boundary_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_implementation_boundary_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_implementation_boundary_implementation_packet_does_not_create_boundary_files',
                'agent_codex_real_invoker_implementation_boundary_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker implementation boundary implementation packet is ready; it defines scoped boundary work but does not start Codex or spend tokens.',
        ];
    }


public function agentCodexRealInvokerExecutorPlanContractTemplate(array $options = []): array
    {
        $boundaryPayload = $this->section->agentCodexRealInvokerImplementationBoundaryPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_executor_plan_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-EXECUTOR-PLAN-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_implementation_boundary_preflight_hash' => data_get($boundaryPayload, 'codex_real_invoker_implementation_boundary_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_implementation_boundary_status' => data_get($boundaryPayload, 'status'),
            'source_codex_real_invoker_implementation_boundary_preflight_hash' => data_get($boundaryPayload, 'codex_real_invoker_implementation_boundary_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerExecutorPlan',
                'method' => 'prepareExecutorPlan',
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
                    'real_invoker_release_preflight_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_implementation_boundary_id',
                    'real_invoker_executor_plan_id',
                    'operator_executor_plan_receipt_hash',
                    'real_invoker_contract_hash',
                    'release_policy_hash',
                    'implementation_plan_hash',
                    'executor_binary_contract_hash',
                    'executor_observability_contract_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'rollback_plan_hash',
                    'max_runtime_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_executor_plan_id',
                    'real_invoker_implementation_boundary_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_release_preflight_id',
                    'dry_run_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_executor_plan_prepared',
                    'executor_enabled',
                    'fresh_release_required_before_start',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_executor_plan_must' => [
                'require_codex_real_invoker_implementation_boundary_prepared',
                'require_operator_executor_plan_receipt_hash',
                'require_executor_binary_contract_hash',
                'require_executor_observability_contract_hash',
                'require_fresh_release_before_any_future_start',
                'record_append_only_real_invoker_executor_plan_event_before_any_real_invocation',
            ],
            'real_invoker_executor_plan_must_not' => [
                'enable_executor_from_contract_template',
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorPlan.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorPlanTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'real_invoker_executor_plan_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-executor-plan-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_plan_contract_template.v1',
            'status' => 'codex_real_invoker_executor_plan_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_executor_plan_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_contract_template' => $template,
            'codex_real_invoker_executor_plan_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_plan_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_executor_plan_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_executor_plan_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_plan_contract_template_does_not_enable_executor',
                'agent_codex_real_invoker_executor_plan_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker executor plan contract template defines the disabled executor plan without starting Codex or spending tokens.',
        ];
    }


public function agentCodexRealInvokerExecutorPlanPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerExecutorPlanContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_executor_plan_contract_template', []);
        $executorPlanClass = AgentCodexRealInvokerExecutorPlan::class;
        $boundaryClass = AgentCodexRealInvokerImplementationBoundary::class;
        $executorPlanReady = class_exists($executorPlanClass);
        $boundaryReady = class_exists($boundaryClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $executorPlanReady ? null : 'codex_real_invoker_executor_plan_missing',
            $boundaryReady ? null : 'codex_real_invoker_implementation_boundary_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_executor_plan_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_executor_plan_contract_template_hash'),
            'source_codex_real_invoker_implementation_boundary_status' => data_get($contract, 'source_codex_real_invoker_implementation_boundary_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
                'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'real_invoker_executor_plan_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_executor_plan',
                'require_codex_real_invoker_implementation_boundary_metadata',
                'require_operator_executor_plan_receipt_hash',
                'require_executor_binary_and_observability_contract_hashes',
                'record_real_invoker_executor_plan_event_without_enabling_executor',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorPlan.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorPlanTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_executor_plan',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'real_invoker_executor_plan_file_creation_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-executor-plan-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_plan_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_executor_plan_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_preflight' => $preflight,
            'codex_real_invoker_executor_plan_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_plan_preflight_does_not_start_codex',
                'agent_codex_real_invoker_executor_plan_preflight_does_not_call_codex',
                'agent_codex_real_invoker_executor_plan_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_plan_preflight_does_not_enable_executor',
                'agent_codex_real_invoker_executor_plan_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker executor plan is ready; executor remains disabled and requires a later fresh release before any start.'
                : 'Codex real invoker executor plan is blocked until the executor plan service and boundary prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerExecutorPlanImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerExecutorPlanPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_executor_plan_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_executor_plan_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker executor plan',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorPlan.php'],
                'acceptance' => 'Executor plan records disabled executor metadata and never starts Codex, shell or subprocesses.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce boundary and executor contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorPlan.php'],
                'acceptance' => 'Executor plan requires implementation boundary metadata, executor receipt hash, binary contract hash and observability contract hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add real invoker executor plan tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorPlanTest.php'],
                'acceptance' => 'Tests prove missing boundary rejection, duplicate plan rejection, rollback and no executor/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose real invoker executor plan readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare executor disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_executor_plan_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-EXECUTOR-PLAN-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the disabled Codex real invoker executor plan that prepares executor contracts while still refusing to enable or start Codex.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_enable_executor',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'executor_enablement',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'real_invoker_executor_plan_requires_implementation_boundary_metadata',
                'real_invoker_executor_plan_requires_operator_executor_plan_receipt_hash',
                'real_invoker_executor_plan_requires_executor_binary_contract_hash',
                'real_invoker_executor_plan_is_idempotent_for_same_plan_id',
                'real_invoker_executor_plan_records_disabled_executor_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_enable_executor',
                'need_to_modify_file_outside_allowed_files',
                'need_to_allow_provider_token_spend',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'executor_enablement_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_plan_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_executor_plan_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_executor_plan_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_implementation_packet' => $packet,
            'codex_real_invoker_executor_plan_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_plan_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_executor_plan_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_executor_plan_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_plan_implementation_packet_does_not_enable_executor',
                'agent_codex_real_invoker_executor_plan_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker executor plan implementation packet is ready; it defines scoped disabled-executor work but does not start Codex or spend tokens.',
        ];
    }


}
