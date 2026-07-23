<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartLivenessMonitor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartReceiptContractBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 07 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate
 *           .. agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateImplementationPacket
 */
final class CodexPart07SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartReceiptContractBuilderPreflight($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_receipt_contract_builder_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-EVIDENCE-RECEIPT-WRITER-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_receipt_contract_builder_preflight_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_receipt_contract_builder_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_receipt_contract_builder_status' => data_get($contractPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartEvidenceReceiptWriter',
                'method' => 'writePostStartEvidenceReceipt',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'real_invoker_process_starter_readiness_gate_id',
                    'real_invoker_start_execution_gate_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'post_start_receipt_contract_id',
                    'post_start_evidence_receipt_id',
                    'external_process_identity_contract_hash',
                    'startup_evidence_contract_hash',
                    'terminal_pid_capture_contract_hash',
                    'post_start_cost_meter_contract_hash',
                    'external_process_identity_evidence_hash',
                    'startup_evidence_hash',
                    'terminal_pid_capture_hash',
                    'post_start_liveness_probe_hash',
                    'post_start_cost_meter_evidence_hash',
                    'operator_external_start_attestation_hash',
                    'no_atlas_process_spawn_attestation_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'post_start_evidence_receipt_id',
                    'agent_run_id',
                    'run_key',
                    'run_status',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_evidence_receipt_must' => [
                'require_codex_real_invoker_post_start_receipt_contract',
                'require_external_process_identity_evidence_hash',
                'require_startup_evidence_hash',
                'require_terminal_pid_capture_hash',
                'require_post_start_liveness_probe_hash',
                'require_post_start_cost_meter_evidence_hash',
                'require_operator_external_start_attestation_hash',
                'require_no_atlas_process_spawn_attestation_hash',
                'record_append_only_evidence_event',
            ],
            'real_invoker_post_start_evidence_receipt_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'mark_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceReceiptWriter.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartEvidenceReceiptWriterTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_evidence_receipt_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'external_process_evidence_acceptance_allowed_by_future_writer' => true,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-evidence-receipt-writer-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_evidence_receipt_writer_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_evidence_receipt_writer_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_receipt_writer_contract_template' => $template,
            'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start evidence receipt writer contract template defines external-start evidence acceptance only; it does not start Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartEvidenceReceiptWriterPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template', []);
        $writerClass = AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class;
        $builderClass = AgentCodexRealInvokerPostStartReceiptContractBuilder::class;
        $writerReady = class_exists($writerClass);
        $builderReady = class_exists($builderClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $writerReady ? null : 'codex_real_invoker_post_start_evidence_receipt_writer_missing',
            $builderReady ? null : 'codex_real_invoker_post_start_receipt_contract_builder_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_evidence_receipt_writer_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_hash'),
            'source_codex_real_invoker_post_start_receipt_contract_builder_status' => data_get($contract, 'source_codex_real_invoker_post_start_receipt_contract_builder_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_evidence_receipt_writer_ready' => $writerReady,
                'codex_real_invoker_post_start_receipt_contract_builder_ready' => $builderReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_evidence_receipt_writer_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_evidence_receipt_writer',
                'require_codex_real_invoker_post_start_receipt_contract_metadata',
                'require_external_start_evidence_hashes',
                'require_no_atlas_process_spawn_attestation_hash',
                'record_post_start_evidence_receipt_without_calling_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceReceiptWriter.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartEvidenceReceiptWriterTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_evidence_receipt_writer',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_evidence_receipt_writer_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-evidence-receipt-writer-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_evidence_receipt_writer_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_evidence_receipt_writer_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_receipt_writer_preflight' => $preflight,
            'codex_real_invoker_post_start_evidence_receipt_writer_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start evidence receipt writer is ready; it accepts operator evidence but still does not call Codex or dispatch work.'
                : 'Codex real invoker post-start evidence receipt writer is blocked until writer and contract prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartEvidenceReceiptWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartEvidenceReceiptWriterPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_evidence_receipt_writer_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_evidence_receipt_writer_preflight_hash');

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker post-start evidence receipt writer',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceReceiptWriter.php'],
                'acceptance' => 'Writer records operator-provided external-start evidence from the receipt contract and never starts Codex, spends tokens or dispatches work.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce post-start contract and no-spawn attestation',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceReceiptWriter.php'],
                'acceptance' => 'Writer requires post-start receipt contract metadata plus external identity, startup, PID, liveness, cost and no-spawn evidence hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add post-start evidence receipt tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartEvidenceReceiptWriterTest.php'],
                'acceptance' => 'Tests prove missing contract rejection, no-spawn hash rejection, duplicate rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose post-start evidence receipt readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare Codex invocation disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_evidence_receipt_writer_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-EVIDENCE-RECEIPT-WRITER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start evidence receipt writer that accepts operator external-start evidence without allowing Atlas to start Codex, spend tokens or dispatch work.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_start_codex_process',
                'do_not_dispatch_work_to_codex',
                'do_not_mark_runs_running_or_terminal',
            ],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'process_start',
                'dispatch_runtime',
                'merge_runtime',
                'hot_kernel_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'post_start_evidence_receipt_requires_post_start_receipt_contract_metadata',
                'post_start_evidence_receipt_requires_no_atlas_process_spawn_attestation_hash',
                'post_start_evidence_receipt_records_external_process_started_as_external_evidence_only',
                'post_start_evidence_receipt_is_idempotent_for_same_receipt_id',
                'post_start_evidence_receipt_records_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'post_start_evidence_receipt_write_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_evidence_receipt_writer_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet' => $packet,
            'codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_evidence_receipt_writer_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start evidence receipt writer implementation packet is ready; it records external evidence only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeContractTemplate(array $options = []): array
    {
        $handoffPayload = $this->section->agentCodexRealInvokerPostStartOperatorStartHandoffBuilderPreflight($options);
        $receiptPayload = $this->section->agentCodexRealInvokerPostStartReceiptContractBuilderPreflight($options);
        $evidencePayload = $this->section->agentCodexRealInvokerPostStartEvidenceReceiptWriterPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-EVIDENCE-ACCEPTANCE-BRIDGE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_operator_start_handoff_builder_preflight_hash' => data_get($handoffPayload, 'codex_real_invoker_post_start_operator_start_handoff_builder_preflight_hash'),
                'post_start_receipt_contract_builder_preflight_hash' => data_get($receiptPayload, 'codex_real_invoker_post_start_receipt_contract_builder_preflight_hash'),
                'post_start_evidence_receipt_writer_preflight_hash' => data_get($evidencePayload, 'codex_real_invoker_post_start_evidence_receipt_writer_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_operator_start_handoff_builder_status' => data_get($handoffPayload, 'status'),
            'source_codex_real_invoker_post_start_receipt_contract_builder_status' => data_get($receiptPayload, 'status'),
            'source_codex_real_invoker_post_start_evidence_receipt_writer_status' => data_get($evidencePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge',
                'method' => 'acceptPostStartEvidence',
                'input_contract' => [
                    'run_key',
                    'post_start_evidence_acceptance_bridge_id',
                    'post_start_operator_start_handoff_id',
                    'post_start_receipt_contract_id',
                    'post_start_evidence_receipt_id',
                    'codex_execution_id',
                    'real_invoker_process_starter_readiness_gate_id',
                    'real_invoker_start_execution_gate_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'external_process_identity_contract_hash',
                    'startup_evidence_contract_hash',
                    'terminal_pid_capture_contract_hash',
                    'post_start_cost_meter_contract_hash',
                    'external_process_identity_evidence_hash',
                    'startup_evidence_hash',
                    'terminal_pid_capture_hash',
                    'post_start_liveness_probe_hash',
                    'post_start_cost_meter_evidence_hash',
                    'operator_external_start_attestation_hash',
                    'no_atlas_process_spawn_attestation_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'post_start_evidence_acceptance_bridge_id',
                    'post_start_receipt_contract_built',
                    'post_start_evidence_receipt_recorded',
                    'operator_external_start_attested',
                    'atlas_process_spawned',
                    'actual_process_start_allowed',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_evidence_acceptance_must' => [
                'require_codex_real_invoker_post_start_operator_start_handoff',
                'build_codex_real_invoker_post_start_receipt_contract',
                'write_codex_real_invoker_post_start_evidence_receipt',
                'require_external_process_identity_evidence_hash',
                'require_operator_external_start_attestation_hash',
                'require_no_atlas_process_spawn_attestation_hash',
                'record_acceptance_bridge_metadata_before_liveness_monitoring',
            ],
            'real_invoker_post_start_evidence_acceptance_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'mark_actual_process_start_allowed',
                'mark_run_running_or_terminal',
                'bypass_post_start_liveness_monitor',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartEvidenceAcceptanceBridgeTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_evidence_acceptance_allowed_by_future_bridge' => true,
                'post_start_receipt_contract_built_by_future_bridge' => true,
                'post_start_evidence_receipt_recorded_by_future_bridge' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_liveness_monitor' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-evidence-acceptance-bridge-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template' => $template,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start evidence acceptance bridge contract template links handoff, receipt contract and evidence receipt without starting Codex or dispatching work.',
        ];
    }


public function agentCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template', []);
        $bridgeReady = class_exists(AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class);
        $handoffReady = class_exists(AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class);
        $receiptBuilderReady = class_exists(AgentCodexRealInvokerPostStartReceiptContractBuilder::class);
        $evidenceWriterReady = class_exists(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $bridgeReady ? null : 'codex_real_invoker_post_start_evidence_acceptance_bridge_missing',
            $handoffReady ? null : 'codex_real_invoker_post_start_operator_start_handoff_builder_missing',
            $receiptBuilderReady ? null : 'codex_real_invoker_post_start_receipt_contract_builder_missing',
            $evidenceWriterReady ? null : 'codex_real_invoker_post_start_evidence_receipt_writer_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_hash'),
            'source_codex_real_invoker_post_start_operator_start_handoff_builder_status' => data_get($contract, 'source_codex_real_invoker_post_start_operator_start_handoff_builder_status'),
            'source_codex_real_invoker_post_start_receipt_contract_builder_status' => data_get($contract, 'source_codex_real_invoker_post_start_receipt_contract_builder_status'),
            'source_codex_real_invoker_post_start_evidence_receipt_writer_status' => data_get($contract, 'source_codex_real_invoker_post_start_evidence_receipt_writer_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' => $bridgeReady,
                'codex_real_invoker_post_start_operator_start_handoff_builder_ready' => $handoffReady,
                'codex_real_invoker_post_start_receipt_contract_builder_ready' => $receiptBuilderReady,
                'codex_real_invoker_post_start_evidence_receipt_writer_ready' => $evidenceWriterReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_evidence_acceptance_bridge_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_evidence_acceptance_bridge',
                'require_post_start_operator_start_handoff_metadata',
                'delegate_to_post_start_receipt_contract_builder',
                'delegate_to_post_start_evidence_receipt_writer',
                'record_acceptance_bridge_metadata_without_dispatch',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartEvidenceAcceptanceBridgeTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_evidence_acceptance_bridge',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_evidence_acceptance_bridge_file_creation_allowed_here' => false,
                'post_start_evidence_acceptance_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_liveness_monitor' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-evidence-acceptance-bridge-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_preflight' => $preflight,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start evidence acceptance bridge is ready; it accepts external-start evidence as governed evidence only and still does not call Codex.'
                : 'Codex real invoker post-start evidence acceptance bridge is blocked until bridge, handoff, contract, evidence and storage prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_evidence_acceptance_bridge_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start evidence acceptance bridge', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge.php'], 'acceptance' => 'Bridge validates post-start operator handoff metadata and never starts Codex, spends tokens or dispatches work.'],
            ['id' => 'T2', 'title' => 'Delegate receipt contract and evidence receipt writes atomically', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge.php'], 'acceptance' => 'Bridge builds the post-start receipt contract, writes post-start evidence receipt and rolls back both if persistence fails.'],
            ['id' => 'T3', 'title' => 'Add post-start evidence acceptance bridge tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartEvidenceAcceptanceBridgeTest.php'], 'acceptance' => 'Tests prove idempotency, missing handoff rejection, dispatch rejection, no-spawn attestation requirement, rollback and no process/token/dispatch side effects.'],
            ['id' => 'T4', 'title' => 'Expose post-start evidence acceptance readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare liveness monitoring as the next required stage.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-EVIDENCE-ACCEPTANCE-BRIDGE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start evidence acceptance bridge that links post-start handoff, receipt contract and evidence receipt before liveness monitoring, while forbidding Atlas-owned Codex start, token spend and dispatch.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_mark_actual_process_start_allowed', 'do_not_bypass_liveness_monitoring'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'adapter_execution_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_evidence_acceptance_requires_operator_start_handoff_metadata', 'post_start_evidence_acceptance_builds_post_start_receipt_contract', 'post_start_evidence_acceptance_writes_post_start_evidence_receipt', 'post_start_evidence_acceptance_requires_no_atlas_process_spawn_attestation_hash', 'post_start_evidence_acceptance_is_idempotent_for_same_bridge_id', 'post_start_evidence_acceptance_does_not_start_codex_or_dispatch_work'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_evidence_acceptance_allowed_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_liveness_monitor' => true],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet' => $packet,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start evidence acceptance bridge implementation packet is ready; it accepts external-start evidence only as governed evidence before liveness monitoring.',
        ];
    }


public function agentCodexRealInvokerPostStartLivenessMonitorContractTemplate(array $options = []): array
    {
        $template = [
            'status' => 'codex_real_invoker_post_start_liveness_monitor_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-LIVENESS-MONITOR-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_evidence_acceptance_bridge_service' => AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class,
                'post_start_liveness_monitor_service' => AgentCodexRealInvokerPostStartLivenessMonitor::class,
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status' => class_exists(AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class) ? 'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartLivenessMonitor',
                'method' => 'recordPostStartLiveness',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'post_start_receipt_contract_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'post_start_evidence_receipt_id',
                    'post_start_liveness_monitor_id',
                    'observed_liveness_state',
                    'liveness_observation_hash',
                    'heartbeat_observation_hash',
                    'progress_observation_hash',
                    'operator_visibility_attestation_hash',
                    'no_provider_call_attestation_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'post_start_liveness_monitor_id',
                    'agent_run_id',
                    'run_key',
                    'run_status',
                    'observed_liveness_state',
                    'external_process_started',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_liveness_must' => [
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge',
                'require_codex_real_invoker_post_start_evidence_receipt',
                'require_observed_liveness_state_alive_silent_stale_or_orphaned',
                'require_liveness_observation_hash',
                'require_heartbeat_observation_hash',
                'require_progress_observation_hash',
                'require_operator_visibility_attestation_hash',
                'require_no_provider_call_attestation_hash',
                'record_append_only_liveness_event',
            ],
            'real_invoker_post_start_liveness_must_not' => [
                'call_codex_cli_or_codex_app',
                'probe_provider_process_directly',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'mark_run_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartLivenessMonitor.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartLivenessMonitorTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_liveness_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-liveness-monitor-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_liveness_monitor_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_liveness_monitor_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_liveness_monitor_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_liveness_monitor_contract_template' => $template,
            'codex_real_invoker_post_start_liveness_monitor_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_liveness_monitor_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_liveness_monitor_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_liveness_monitor_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_liveness_monitor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start liveness monitor contract template defines external observation only; it does not call Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartLivenessMonitorPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartLivenessMonitorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_liveness_monitor_contract_template', []);
        $monitorClass = AgentCodexRealInvokerPostStartLivenessMonitor::class;
        $acceptanceBridgeClass = AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class;
        $monitorReady = class_exists($monitorClass);
        $acceptanceBridgeReady = class_exists($acceptanceBridgeClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $monitorReady ? null : 'codex_real_invoker_post_start_liveness_monitor_missing',
            $acceptanceBridgeReady ? null : 'codex_real_invoker_post_start_evidence_acceptance_bridge_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_liveness_monitor_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_liveness_monitor_contract_template_hash'),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status' => data_get($contract, 'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_liveness_monitor_ready' => $monitorReady,
                'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' => $acceptanceBridgeReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_liveness_monitor_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_liveness_monitor',
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge_metadata',
                'require_codex_real_invoker_post_start_evidence_receipt_metadata',
                'classify_alive_silent_stale_or_orphaned_from_external_observation',
                'record_liveness_without_calling_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartLivenessMonitor.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartLivenessMonitorTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_liveness_monitor',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_liveness_monitor_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-liveness-monitor-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_liveness_monitor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_liveness_monitor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_liveness_monitor_preflight' => $preflight,
            'codex_real_invoker_post_start_liveness_monitor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start liveness monitor is ready; it records external observation but still does not call Codex or dispatch work.'
                : 'Codex real invoker post-start liveness monitor is blocked until monitor and receipt prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartLivenessMonitorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartLivenessMonitorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_liveness_monitor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_liveness_monitor_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start liveness monitor', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartLivenessMonitor.php'], 'acceptance' => 'Monitor records external liveness observation and never calls Codex, probes a process directly, spends tokens or dispatches work.'],
            ['id' => 'T2', 'title' => 'Enforce liveness observation contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartLivenessMonitor.php'], 'acceptance' => 'Monitor requires post-start evidence acceptance bridge plus evidence receipt metadata and accepts only alive, silent, stale or orphaned liveness states.'],
            ['id' => 'T3', 'title' => 'Add post-start liveness monitor tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartLivenessMonitorTest.php'], 'acceptance' => 'Tests prove missing acceptance bridge rejection, missing receipt rejection, invalid liveness rejection, duplicate rejection, rollback and no process/token/dispatch side effects.'],
            ['id' => 'T4', 'title' => 'Expose post-start liveness readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare Codex invocation disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_liveness_monitor_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-LIVENESS-MONITOR-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start liveness monitor that records external alive/silent/stale/orphaned observations without allowing Atlas to call Codex, spend tokens or dispatch work.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_probe_provider_process_directly', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_mark_runs_terminal'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'direct_process_probe', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_liveness_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_liveness_requires_post_start_evidence_receipt_metadata', 'post_start_liveness_accepts_only_alive_silent_stale_or_orphaned', 'post_start_liveness_records_observation_without_calling_codex', 'post_start_liveness_is_idempotent_for_same_monitor_id'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_liveness_write_allowed_by_packet' => false, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_liveness_monitor_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_liveness_monitor_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_liveness_monitor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_liveness_monitor_implementation_packet' => $packet,
            'codex_real_invoker_post_start_liveness_monitor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start liveness monitor implementation packet is ready; it records external liveness only and does not call Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchReleaseGateContractTemplate(array $options = []): array
    {
        $livenessPayload = $this->section->agentCodexRealInvokerPostStartLivenessMonitorPreflight($options);
        $liveness = (array) data_get($livenessPayload, 'codex_real_invoker_post_start_liveness_monitor_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_dispatch_release_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-DISPATCH-RELEASE-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_liveness_monitor_preflight_hash' => data_get($livenessPayload, 'codex_real_invoker_post_start_liveness_monitor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_liveness_monitor_status' => data_get($livenessPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartDispatchReleaseGate',
                'method' => 'preparePostStartDispatchRelease',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'post_start_receipt_contract_id',
                    'post_start_evidence_receipt_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'post_start_liveness_monitor_id',
                    'dispatch_release_gate_id',
                    'dispatch_scope_hash',
                    'continuation_summary_hash',
                    'context_pack_hash',
                    'signed_dispatch_policy_hash',
                    'no_direct_provider_call_attestation_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'dispatch_release_gate_id',
                    'post_start_liveness_monitor_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'agent_run_id',
                    'run_key',
                    'run_status',
                    'dispatch_release_gate_ready',
                    'future_dispatch_release_candidate',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_dispatch_release_must' => [
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge',
                'require_codex_real_invoker_post_start_liveness_monitor',
                'require_liveness_state_alive',
                'require_dispatch_scope_hash',
                'require_continuation_summary_hash',
                'require_context_pack_hash',
                'require_signed_dispatch_policy_hash',
                'require_no_direct_provider_call_attestation_hash',
                'record_append_only_dispatch_release_gate_event',
            ],
            'real_invoker_post_start_dispatch_release_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'call_provider_process',
                'mark_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReleaseGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReleaseGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_dispatch_release_gate_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_signed_dispatch_authorization' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-dispatch-release-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_release_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_dispatch_release_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_release_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_release_gate_contract_template' => $template,
            'codex_real_invoker_post_start_dispatch_release_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_dispatch_release_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_dispatch_release_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_dispatch_release_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_dispatch_release_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start dispatch release gate contract template defines a future dispatch release guard only; it does not dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartDispatchReleaseGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_dispatch_release_gate_contract_template', []);
        $gateClass = AgentCodexRealInvokerPostStartDispatchReleaseGate::class;
        $bridgeClass = AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class;
        $monitorClass = AgentCodexRealInvokerPostStartLivenessMonitor::class;
        $gateReady = class_exists($gateClass);
        $bridgeReady = class_exists($bridgeClass);
        $monitorReady = class_exists($monitorClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_dispatch_release_gate_missing',
            $bridgeReady ? null : 'codex_real_invoker_post_start_evidence_acceptance_bridge_missing',
            $monitorReady ? null : 'codex_real_invoker_post_start_liveness_monitor_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_dispatch_release_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_dispatch_release_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_liveness_monitor_status' => data_get($contract, 'source_codex_real_invoker_post_start_liveness_monitor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_dispatch_release_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' => $bridgeReady,
                'codex_real_invoker_post_start_liveness_monitor_ready' => $monitorReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_dispatch_release_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_dispatch_release_gate',
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge_metadata',
                'require_codex_real_invoker_post_start_liveness_monitor_metadata',
                'require_liveness_state_alive_before_future_dispatch_release',
                'record_dispatch_release_gate_without_dispatching_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReleaseGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReleaseGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_dispatch_release_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_dispatch_release_gate_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_signed_dispatch_authorization' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-dispatch-release-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_release_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_dispatch_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start dispatch release gate is ready; it gates future dispatch but still does not dispatch work.'
                : 'Codex real invoker post-start dispatch release gate is blocked until gate and liveness prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartDispatchReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_dispatch_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_dispatch_release_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start dispatch release gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReleaseGate.php'], 'acceptance' => 'Gate prepares future dispatch release only when post-start liveness is alive and never dispatches work.'],
            ['id' => 'T2', 'title' => 'Enforce dispatch release input and liveness contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReleaseGate.php'], 'acceptance' => 'Gate requires liveness metadata, accepted evidence bridge id, context pack hash, continuation summary hash, dispatch scope hash, signed policy hash and provider-call attestation.'],
            ['id' => 'T3', 'title' => 'Add post-start dispatch release gate tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReleaseGateTest.php'], 'acceptance' => 'Tests prove missing liveness rejection, non-alive rejection, duplicate rejection, rollback and no process/token/dispatch side effects.'],
            ['id' => 'T4', 'title' => 'Expose post-start dispatch release readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_dispatch_release_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-DISPATCH-RELEASE-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start dispatch release gate that prepares future dispatch only after alive liveness, while forbidding Atlas from calling Codex, spending tokens or dispatching work.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_mark_runs_running_or_terminal'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_dispatch_release_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_dispatch_release_gate_requires_liveness_monitor_metadata', 'post_start_dispatch_release_gate_requires_liveness_alive', 'post_start_dispatch_release_gate_requires_context_and_continuation_hashes', 'post_start_dispatch_release_gate_does_not_dispatch_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_dispatch_release_gate_write_allowed_by_packet' => false, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_dispatch_release_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_release_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start dispatch release gate implementation packet is ready; it prepares a future dispatch release guard and does not dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateContractTemplate(array $options = []): array
    {
        $releasePayload = $this->section->agentCodexRealInvokerPostStartDispatchReleaseGatePreflight($options);
        $release = (array) data_get($releasePayload, 'codex_real_invoker_post_start_dispatch_release_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-SIGNED-DISPATCH-AUTHORIZATION-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_dispatch_release_gate_preflight_hash' => data_get($releasePayload, 'codex_real_invoker_post_start_dispatch_release_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_dispatch_release_gate_status' => data_get($releasePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate',
                'method' => 'authorizePostStartSignedDispatch',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'post_start_receipt_contract_id',
                    'post_start_evidence_receipt_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'post_start_liveness_monitor_id',
                    'dispatch_release_gate_id',
                    'signed_dispatch_authorization_id',
                    'signed_dispatch_receipt_hash',
                    'human_dispatch_signature_hash',
                    'signed_dispatch_policy_hash',
                    'dispatch_window_hash',
                    'dispatch_scope_hash',
                    'continuation_summary_hash',
                    'context_pack_hash',
                    'dispatch_replay_guard_hash',
                    'dispatch_kill_switch_hash',
                    'no_direct_provider_call_attestation_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'signed_dispatch_authorization_id',
                    'dispatch_release_gate_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'agent_run_id',
                    'run_key',
                    'run_status',
                    'signed_dispatch_authorization_recorded',
                    'future_dispatch_authorized',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_signed_dispatch_authorization_must' => [
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge',
                'require_codex_real_invoker_post_start_dispatch_release_gate',
                'require_liveness_state_alive',
                'require_signed_dispatch_receipt_hash',
                'require_human_dispatch_signature_hash',
                'require_signed_dispatch_policy_hash',
                'require_dispatch_window_hash',
                'require_context_pack_hash',
                'require_continuation_summary_hash',
                'record_append_only_signed_dispatch_authorization_event',
            ],
            'real_invoker_post_start_signed_dispatch_authorization_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'call_provider_process',
                'mark_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSignedDispatchAuthorizationGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_signed_dispatch_authorization_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_dispatch_executor_handoff' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-signed-dispatch-authorization-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template' => $template,
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start signed dispatch authorization gate contract template records authorization only; it does not dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template', []);
        $authorizationClass = AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class;
        $bridgeClass = AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class;
        $releaseClass = AgentCodexRealInvokerPostStartDispatchReleaseGate::class;
        $authorizationReady = class_exists($authorizationClass);
        $bridgeReady = class_exists($bridgeClass);
        $releaseReady = class_exists($releaseClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $authorizationReady ? null : 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_missing',
            $bridgeReady ? null : 'codex_real_invoker_post_start_evidence_acceptance_bridge_missing',
            $releaseReady ? null : 'codex_real_invoker_post_start_dispatch_release_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_dispatch_release_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_release_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_signed_dispatch_authorization_gate_ready' => $authorizationReady,
                'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' => $bridgeReady,
                'codex_real_invoker_post_start_dispatch_release_gate_ready' => $releaseReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_signed_dispatch_authorization_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge_metadata',
                'require_codex_real_invoker_post_start_dispatch_release_gate_metadata',
                'require_signed_dispatch_receipt_and_human_signature_hashes',
                'record_signed_dispatch_authorization_without_dispatching_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSignedDispatchAuthorizationGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_signed_dispatch_authorization_gate_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_dispatch_executor_handoff' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-signed-dispatch-authorization-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start signed dispatch authorization gate is ready; it records authorization but still does not dispatch work.'
                : 'Codex real invoker post-start signed dispatch authorization gate is blocked until authorization and release prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start signed dispatch authorization gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate.php'], 'acceptance' => 'Gate records signed dispatch authorization after release gate and never dispatches work.'],
            ['id' => 'T2', 'title' => 'Enforce signed dispatch authorization contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate.php'], 'acceptance' => 'Gate requires dispatch release metadata, accepted evidence bridge id, alive liveness, signed receipt hash, human signature hash, window hash, policy hash and no-provider-call attestation.'],
            ['id' => 'T3', 'title' => 'Add post-start signed dispatch authorization tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSignedDispatchAuthorizationGateTest.php'], 'acceptance' => 'Tests prove missing release gate rejection, non-alive rejection, missing signature rejection, duplicate rejection, rollback and no process/token/dispatch side effects.'],
            ['id' => 'T4', 'title' => 'Expose post-start signed dispatch authorization readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-SIGNED-DISPATCH-AUTHORIZATION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start signed dispatch authorization gate that records a human/policy signed authorization for a future executor handoff while forbidding Atlas from dispatching work.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_mark_runs_running_or_terminal'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_signed_dispatch_authorization_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_signed_dispatch_authorization_requires_dispatch_release_gate_metadata', 'post_start_signed_dispatch_authorization_requires_liveness_alive', 'post_start_signed_dispatch_authorization_requires_signature_and_receipt_hashes', 'post_start_signed_dispatch_authorization_does_not_dispatch_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_signed_dispatch_authorization_write_allowed_by_packet' => false, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start signed dispatch authorization gate implementation packet is ready; it records authorization and does not dispatch work.',
        ];
    }


}
