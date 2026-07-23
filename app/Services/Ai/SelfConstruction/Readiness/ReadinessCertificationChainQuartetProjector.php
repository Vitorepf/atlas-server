<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * Pure contract/preflight/implementation-packet projections for the five
 * handwritten certification-chain quartet families (chain integrity audit,
 * deterministic replay, snapshot store, replay diff, macro-sprint promotion
 * gate), moved VERBATIM off AtlasSelfConstructionReadinessService
 * (GOD-DEBULK Fase 2). Bodies are byte-identical to the god originals
 * (corpus-proved); only $this->stableHash became ReadinessHash::stable and
 * method_exists($this, ...) became an explicit owner-class check
 * (A1-SC-0037). Status routes stay with the god: they construct the real
 * services and are not pure.
 *
 * These payloads deviate from ReadinessCertificationWorkbenchQuartetBuilder
 * on purpose (bespoke invariants/sections with pinned hashes) — do not fold
 * them into the generic catalog without a schema_version bump.
 */
final class ReadinessCertificationChainQuartetProjector
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationContract(array $options = []): array
    {
        $contract = [
            'status' => 'agent_control_plane_chain_integrity_certification_contract_ready',
            'contract_id' => 'AGENT-CONTROL-PLANE-CHAIN-INTEGRITY-CERTIFICATION-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'certification_version' => AgentControlPlaneChainIntegrityAuditService::SCHEMA_VERSION,
            'audit_service' => AgentControlPlaneChainIntegrityAuditService::class,
            'audit_service_method' => 'audit',
            'invariants' => [
                'audit_is_read_only' => true,
                'audit_does_not_start_codex' => true,
                'audit_does_not_call_codex_cli_or_app' => true,
                'audit_does_not_spawn_subprocess' => true,
                'audit_does_not_invoke_adapter' => true,
                'audit_does_not_execute_adapter' => true,
                'audit_does_not_call_provider' => true,
                'audit_does_not_dispatch_work' => true,
                'audit_does_not_spend_tokens' => true,
                'audit_does_not_enable_self_programming' => true,
                'audit_does_not_write_ledger' => true,
                'audit_does_not_advance_pointer' => true,
                'audit_does_not_mark_runtime_flags_true' => true,
                'audit_does_not_promote_completion_claim' => true,
            ],
            'allowed_future_inspection' => [
                'audit_canonical_chain',
                'audit_capability_surface',
                'audit_cli_surface',
                'audit_readiness_surface',
                'audit_invoker_surface',
                'audit_documentation',
                'audit_global_pointers',
                'audit_runtime_safety',
            ],
            'forbidden_even_after_contract' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'mark_runtime_capable',
                'invoke_codex',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_agent_control_plane_chain_integrity_certification_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_chain_integrity_certification_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_control_plane_chain_integrity_certification_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_chain_integrity_certification_contract' => $contract,
            'agent_control_plane_chain_integrity_certification_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_control_plane_chain_integrity_certification_contract_does_not_start_codex',
                'agent_control_plane_chain_integrity_certification_contract_does_not_advance_pointer',
                'agent_control_plane_chain_integrity_certification_contract_does_not_dispatch_work',
                'agent_control_plane_chain_integrity_certification_contract_does_not_execute_adapter',
                'agent_control_plane_chain_integrity_certification_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Chain Integrity Certification contract is ready: it authorizes a read-only structural audit of the Self-Construction chain; it does not advance pointers, dispatch work or enable runtime.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationPreflight(array $options = []): array
    {
        $contractPayload = $this->agentControlPlaneChainIntegrityCertificationContract($options);
        $contract = (array) data_get($contractPayload, 'agent_control_plane_chain_integrity_certification_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_control_plane_chain_integrity_certification_contract_hash');

        $checks = [
            'contract_ready' => data_get($contractPayload, 'status') === 'agent_control_plane_chain_integrity_certification_contract_ready',
            'contract_hash_present' => $contractHash !== '',
            'audit_service_class_exists' => class_exists(AgentControlPlaneChainIntegrityAuditService::class),
            'audit_service_method_exists' => class_exists(AgentControlPlaneChainIntegrityAuditService::class)
                && method_exists(AgentControlPlaneChainIntegrityAuditService::class, 'audit'),
            'agent_control_plane_method_exists' => method_exists(AtlasSelfConstructionReadinessService::class, 'agentControlPlane'),
            'contract_declares_read_only_audit' => (bool) data_get($contract, 'invariants.audit_is_read_only', false),
            'contract_forbids_advance_pointer' => (bool) data_get($contract, 'invariants.audit_does_not_advance_pointer', false),
            'contract_forbids_runtime_writes' => (bool) data_get($contract, 'invariants.audit_does_not_mark_runtime_flags_true', false),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_control_plane_chain_integrity_certification_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-CONTROL-PLANE-CHAIN-INTEGRITY-CERTIFICATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_agent_control_plane_chain_integrity_audit_service',
                'expose_quartet_methods_on_readiness_service',
                'add_cli_options_for_contract_preflight_implementation_packet_status',
                'register_capabilities_in_agent_control_plane',
                'cover_25_plus_assertions_in_dedicated_test_suite',
                'document_certification_in_agent_control_plane_contract_doc',
                'preserve_runtime_disabled_invariants',
                'never_advance_pointer_by_optimism',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'audit_service_call_allowed_by_future_status' => true,
                'pointer_mutation_allowed_here' => false,
                'runtime_flags_mutation_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_chain_integrity_certification_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_chain_integrity_certification_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_control_plane_chain_integrity_certification_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_chain_integrity_certification_preflight' => $preflight,
            'agent_control_plane_chain_integrity_certification_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_control_plane_chain_integrity_certification_preflight_does_not_start_codex',
                'agent_control_plane_chain_integrity_certification_preflight_does_not_advance_pointer',
                'agent_control_plane_chain_integrity_certification_preflight_does_not_dispatch_work',
                'agent_control_plane_chain_integrity_certification_preflight_does_not_execute_adapter',
                'agent_control_plane_chain_integrity_certification_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent Control Plane Chain Integrity Certification preflight is ready; audit service can be invoked through the status projection.'
                : 'Agent Control Plane Chain Integrity Certification preflight is blocked until audit service and quartet are in place.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentControlPlaneChainIntegrityCertificationPreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_control_plane_chain_integrity_certification_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_agent_control_plane_chain_integrity_certification_implementation',
            'packet_id' => 'AGENT-CONTROL-PLANE-CHAIN-INTEGRITY-CERTIFICATION-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneChainIntegrityAuditService.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'task_count' => 6,
            'acceptance_criteria' => [
                'audit_service_returns_schema_v1',
                'audit_service_is_read_only',
                'audit_service_detects_synthetic_broken_slice_when_chain_overridden',
                'audit_service_reports_runtime_safety_all_false',
                'audit_service_aligns_next_required_slice_with_not_yet_runtime_capable_slot_3',
                'readiness_service_exposes_quartet_methods',
                'cli_exposes_quartet_options',
                'capabilities_registered_in_agent_control_plane',
                'documentation_describes_certification_section',
                'dedicated_test_suite_has_thirty_plus_assertions',
            ],
            'non_goals' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'enable_runtime_flags',
                'invoke_codex_or_provider',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
            ],
            'implementation_policy' => [
                'audit_is_read_only' => true,
                'pointer_mutation_allowed_by_packet' => false,
                'runtime_flag_mutation_allowed_by_packet' => false,
                'provider_invocation_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_chain_integrity_certification_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_chain_integrity_certification_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_control_plane_chain_integrity_certification_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_chain_integrity_certification_implementation_packet' => $packet,
            'agent_control_plane_chain_integrity_certification_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_control_plane_chain_integrity_certification_implementation_packet_does_not_start_codex',
                'agent_control_plane_chain_integrity_certification_implementation_packet_does_not_advance_pointer',
                'agent_control_plane_chain_integrity_certification_implementation_packet_does_not_dispatch_work',
                'agent_control_plane_chain_integrity_certification_implementation_packet_does_not_execute_adapter',
                'agent_control_plane_chain_integrity_certification_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Chain Integrity Certification implementation packet is ready: scope is the audit service, readiness quartet, CLI surface, capabilities, docs and tests; no runtime activation allowed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayContract(array $options = []): array
    {
        $contract = [
            'status' => 'agent_control_plane_deterministic_chain_replay_contract_ready',
            'contract_id' => 'AGENT-CONTROL-PLANE-DETERMINISTIC-CHAIN-REPLAY-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'replay_version' => AgentControlPlaneDeterministicChainReplayService::SCHEMA_VERSION,
            'replay_service' => AgentControlPlaneDeterministicChainReplayService::class,
            'replay_service_method' => 'replay',
            'invariants' => [
                'replay_is_read_only' => true,
                'replay_does_not_start_codex' => true,
                'replay_does_not_call_codex_cli_or_app' => true,
                'replay_does_not_spawn_subprocess' => true,
                'replay_does_not_invoke_adapter' => true,
                'replay_does_not_execute_adapter' => true,
                'replay_does_not_call_provider' => true,
                'replay_does_not_dispatch_work' => true,
                'replay_does_not_spend_tokens' => true,
                'replay_does_not_enable_self_programming' => true,
                'replay_does_not_write_ledger' => true,
                'replay_does_not_mutate_pointer' => true,
                'replay_emits_deterministic_hash' => true,
                'replay_emits_proof_bundle' => true,
            ],
            'allowed_future_inspection' => [
                'replay_chain_integrity_summary',
                'replay_control_plane_summary',
                'replay_capability_summary',
                'replay_readiness_summary',
                'replay_cli_summary',
                'replay_invoker_summary',
                'replay_docs_summary',
                'replay_runtime_safety_summary',
                'replay_cycle_summary',
                'replay_terminal_horizon_summary',
                'replay_regression_matrix_summary',
                'replay_next_safe_macro_batch',
            ],
            'forbidden_even_after_contract' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'mark_runtime_capable',
                'invoke_codex',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'declare_atlas_self_construction_os_complete',
            ],
            'next_required_slice' => 'activate_agent_control_plane_deterministic_chain_replay_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_deterministic_chain_replay_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_control_plane_deterministic_chain_replay_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_deterministic_chain_replay_contract' => $contract,
            'agent_control_plane_deterministic_chain_replay_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_control_plane_deterministic_chain_replay_contract_does_not_start_codex',
                'agent_control_plane_deterministic_chain_replay_contract_does_not_advance_pointer',
                'agent_control_plane_deterministic_chain_replay_contract_does_not_dispatch_work',
                'agent_control_plane_deterministic_chain_replay_contract_does_not_execute_adapter',
                'agent_control_plane_deterministic_chain_replay_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Deterministic Chain Replay contract is ready: it authorizes a read-only, deterministic replay of the chain integrity audit with a proof bundle; it does not advance pointers, dispatch work or enable runtime.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayPreflight(array $options = []): array
    {
        $contractPayload = $this->agentControlPlaneDeterministicChainReplayContract($options);
        $contract = (array) data_get($contractPayload, 'agent_control_plane_deterministic_chain_replay_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_control_plane_deterministic_chain_replay_contract_hash');

        $checks = [
            'contract_ready' => data_get($contractPayload, 'status') === 'agent_control_plane_deterministic_chain_replay_contract_ready',
            'contract_hash_present' => $contractHash !== '',
            'replay_service_class_exists' => class_exists(AgentControlPlaneDeterministicChainReplayService::class),
            'replay_service_method_exists' => class_exists(AgentControlPlaneDeterministicChainReplayService::class)
                && method_exists(AgentControlPlaneDeterministicChainReplayService::class, 'replay'),
            'chain_integrity_audit_service_exists' => class_exists(AgentControlPlaneChainIntegrityAuditService::class),
            'agent_control_plane_method_exists' => method_exists(AtlasSelfConstructionReadinessService::class, 'agentControlPlane'),
            'contract_declares_read_only_replay' => (bool) data_get($contract, 'invariants.replay_is_read_only', false),
            'contract_forbids_pointer_mutation' => (bool) data_get($contract, 'invariants.replay_does_not_mutate_pointer', false),
            'contract_emits_deterministic_hash' => (bool) data_get($contract, 'invariants.replay_emits_deterministic_hash', false),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_control_plane_deterministic_chain_replay_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-CONTROL-PLANE-DETERMINISTIC-CHAIN-REPLAY-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_agent_control_plane_deterministic_chain_replay_service',
                'expose_quartet_methods_on_readiness_service',
                'add_cli_options_for_contract_preflight_implementation_packet_status',
                'register_capabilities_in_agent_control_plane',
                'cover_thirty_five_plus_tests_in_dedicated_suite',
                'document_replay_in_agent_control_plane_contract_doc',
                'preserve_runtime_disabled_invariants',
                'never_advance_pointer_via_replay',
                'emit_stable_deterministic_replay_hash',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'replay_service_call_allowed_by_future_status' => true,
                'pointer_mutation_allowed_here' => false,
                'runtime_flags_mutation_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_deterministic_chain_replay_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_deterministic_chain_replay_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_control_plane_deterministic_chain_replay_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_deterministic_chain_replay_preflight' => $preflight,
            'agent_control_plane_deterministic_chain_replay_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_control_plane_deterministic_chain_replay_preflight_does_not_start_codex',
                'agent_control_plane_deterministic_chain_replay_preflight_does_not_advance_pointer',
                'agent_control_plane_deterministic_chain_replay_preflight_does_not_dispatch_work',
                'agent_control_plane_deterministic_chain_replay_preflight_does_not_execute_adapter',
                'agent_control_plane_deterministic_chain_replay_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent Control Plane Deterministic Chain Replay preflight is ready; replay service can be invoked through the status projection.'
                : 'Agent Control Plane Deterministic Chain Replay preflight is blocked until replay service and quartet are in place.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentControlPlaneDeterministicChainReplayPreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_control_plane_deterministic_chain_replay_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_agent_control_plane_deterministic_chain_replay_implementation',
            'packet_id' => 'AGENT-CONTROL-PLANE-DETERMINISTIC-CHAIN-REPLAY-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneDeterministicChainReplayService.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneChainIntegrityAuditService.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneDeterministicChainReplayTest.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'task_count' => 7,
            'acceptance_criteria' => [
                'replay_service_returns_schema_v1',
                'replay_service_is_read_only',
                'replay_service_emits_stable_deterministic_replay_hash',
                'replay_service_emits_proof_bundle_hash',
                'replay_service_reports_runtime_safety_all_false',
                'readiness_service_exposes_quartet_methods',
                'cli_exposes_quartet_options',
                'capabilities_registered_in_agent_control_plane',
                'documentation_describes_replay_section',
                'dedicated_test_suite_covers_thirty_five_plus_scenarios',
                'replay_does_not_advance_pointer',
                'replay_does_not_write_ledger',
            ],
            'non_goals' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'enable_runtime_flags',
                'invoke_codex_or_provider',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
            ],
            'implementation_policy' => [
                'replay_is_read_only' => true,
                'pointer_mutation_allowed_by_packet' => false,
                'runtime_flag_mutation_allowed_by_packet' => false,
                'provider_invocation_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_deterministic_chain_replay_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_deterministic_chain_replay_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_control_plane_deterministic_chain_replay_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_deterministic_chain_replay_implementation_packet' => $packet,
            'agent_control_plane_deterministic_chain_replay_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_control_plane_deterministic_chain_replay_implementation_packet_does_not_start_codex',
                'agent_control_plane_deterministic_chain_replay_implementation_packet_does_not_advance_pointer',
                'agent_control_plane_deterministic_chain_replay_implementation_packet_does_not_dispatch_work',
                'agent_control_plane_deterministic_chain_replay_implementation_packet_does_not_execute_adapter',
                'agent_control_plane_deterministic_chain_replay_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Deterministic Chain Replay implementation packet is ready: scope is the replay service, readiness quartet, CLI surface, capabilities, docs and tests; no runtime activation allowed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreContract(array $options = []): array
    {
        $contract = [
            'status' => 'agent_control_plane_replay_snapshot_store_contract_ready',
            'contract_id' => 'AGENT-CONTROL-PLANE-REPLAY-SNAPSHOT-STORE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'snapshot_schema_version' => AgentControlPlaneReplaySnapshotStore::SCHEMA_VERSION,
            'snapshot_store_class' => AgentControlPlaneReplaySnapshotStore::class,
            'storage_prefix' => AgentControlPlaneReplaySnapshotStore::STORAGE_PREFIX,
            'registry_path' => AgentControlPlaneReplaySnapshotStore::REGISTRY_PATH,
            'keep_default' => AgentControlPlaneReplaySnapshotStore::DEFAULT_KEEP,
            'invariants' => [
                'snapshot_store_is_read_only_for_replay' => true,
                'snapshot_store_does_not_advance_pointer' => true,
                'snapshot_store_does_not_mutate_replay' => true,
                'snapshot_store_does_not_call_codex_cli_or_app' => true,
                'snapshot_store_does_not_start_codex' => true,
                'snapshot_store_does_not_invoke_adapter' => true,
                'snapshot_store_does_not_execute_adapter' => true,
                'snapshot_store_does_not_call_provider' => true,
                'snapshot_store_does_not_dispatch_work' => true,
                'snapshot_store_does_not_spend_tokens' => true,
                'snapshot_store_does_not_enable_self_programming' => true,
                'snapshot_store_does_not_write_ledger' => true,
                'snapshot_store_uses_local_disk_only' => true,
                'snapshot_store_caps_registry' => true,
                'snapshot_store_persists_deterministic_replay_hash' => true,
            ],
            'forbidden_even_after_contract' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'invoke_codex',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'declare_atlas_self_construction_os_complete',
                'mutate_replay_payload',
            ],
            'next_required_slice' => 'activate_agent_control_plane_replay_snapshot_store_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_snapshot_store_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_control_plane_replay_snapshot_store_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_snapshot_store_contract' => $contract,
            'agent_control_plane_replay_snapshot_store_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_snapshot_store_contract_does_not_start_codex',
                'agent_control_plane_replay_snapshot_store_contract_does_not_advance_pointer',
                'agent_control_plane_replay_snapshot_store_contract_does_not_dispatch_work',
                'agent_control_plane_replay_snapshot_store_contract_does_not_execute_adapter',
                'agent_control_plane_replay_snapshot_store_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Replay Snapshot Store contract is ready; it authorizes persisting deterministic replay snapshots locally with a capped registry while keeping runtime disabled.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStorePreflight(array $options = []): array
    {
        $contractPayload = $this->agentControlPlaneReplaySnapshotStoreContract($options);
        $contract = (array) data_get($contractPayload, 'agent_control_plane_replay_snapshot_store_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_control_plane_replay_snapshot_store_contract_hash');

        $checks = [
            'contract_ready' => data_get($contractPayload, 'status') === 'agent_control_plane_replay_snapshot_store_contract_ready',
            'contract_hash_present' => $contractHash !== '',
            'snapshot_store_class_exists' => class_exists(AgentControlPlaneReplaySnapshotStore::class),
            'snapshot_store_put_method_exists' => class_exists(AgentControlPlaneReplaySnapshotStore::class)
                && method_exists(AgentControlPlaneReplaySnapshotStore::class, 'put'),
            'snapshot_store_get_method_exists' => class_exists(AgentControlPlaneReplaySnapshotStore::class)
                && method_exists(AgentControlPlaneReplaySnapshotStore::class, 'get'),
            'snapshot_store_latest_method_exists' => class_exists(AgentControlPlaneReplaySnapshotStore::class)
                && method_exists(AgentControlPlaneReplaySnapshotStore::class, 'latest'),
            'snapshot_store_registry_method_exists' => class_exists(AgentControlPlaneReplaySnapshotStore::class)
                && method_exists(AgentControlPlaneReplaySnapshotStore::class, 'registry'),
            'snapshot_store_prune_method_exists' => class_exists(AgentControlPlaneReplaySnapshotStore::class)
                && method_exists(AgentControlPlaneReplaySnapshotStore::class, 'prune'),
            'contract_declares_local_disk_only' => (bool) data_get($contract, 'invariants.snapshot_store_uses_local_disk_only', false),
            'contract_caps_registry' => (bool) data_get($contract, 'invariants.snapshot_store_caps_registry', false),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_control_plane_replay_snapshot_store_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-CONTROL-PLANE-REPLAY-SNAPSHOT-STORE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_agent_control_plane_replay_snapshot_store_service',
                'expose_quartet_methods_on_readiness_service',
                'add_cli_options_for_contract_preflight_implementation_packet_status',
                'register_capabilities_in_agent_control_plane',
                'cover_snapshot_store_in_dedicated_test_suite',
                'document_snapshot_store_in_agent_control_plane_contract_doc',
                'preserve_runtime_disabled_invariants',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'snapshot_store_writes_local_disk_only' => true,
                'pointer_mutation_allowed_here' => false,
                'runtime_flags_mutation_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_replay_snapshot_store_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_snapshot_store_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_control_plane_replay_snapshot_store_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_snapshot_store_preflight' => $preflight,
            'agent_control_plane_replay_snapshot_store_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_snapshot_store_preflight_does_not_start_codex',
                'agent_control_plane_replay_snapshot_store_preflight_does_not_advance_pointer',
                'agent_control_plane_replay_snapshot_store_preflight_does_not_dispatch_work',
                'agent_control_plane_replay_snapshot_store_preflight_does_not_execute_adapter',
                'agent_control_plane_replay_snapshot_store_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent Control Plane Replay Snapshot Store preflight is ready; the store can persist snapshots on the local disk.'
                : 'Agent Control Plane Replay Snapshot Store preflight is blocked until the store service and quartet are in place.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentControlPlaneReplaySnapshotStorePreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_control_plane_replay_snapshot_store_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_agent_control_plane_replay_snapshot_store_implementation',
            'packet_id' => 'AGENT-CONTROL-PLANE-REPLAY-SNAPSHOT-STORE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneReplaySnapshotStore.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReplaySnapshotStoreTest.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'task_count' => 6,
            'acceptance_criteria' => [
                'snapshot_store_returns_schema_v1',
                'snapshot_store_persists_replay_hashes',
                'snapshot_store_persists_pointer',
                'snapshot_store_persists_runtime_safety_flag',
                'snapshot_store_caps_registry_to_keep_default',
                'snapshot_store_prune_removes_old_entries',
                'snapshot_store_is_read_only_for_replay_payload',
                'readiness_service_exposes_quartet_methods',
                'cli_exposes_quartet_options',
                'capabilities_registered_in_agent_control_plane',
                'documentation_describes_snapshot_store_section',
                'dedicated_test_suite_covers_snapshot_store',
            ],
            'non_goals' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'enable_runtime_flags',
                'invoke_codex_or_provider',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'mutate_replay_payload',
            ],
            'implementation_policy' => [
                'snapshot_store_is_read_only_for_replay' => true,
                'pointer_mutation_allowed_by_packet' => false,
                'runtime_flag_mutation_allowed_by_packet' => false,
                'provider_invocation_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_replay_snapshot_store_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_snapshot_store_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_control_plane_replay_snapshot_store_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_snapshot_store_implementation_packet' => $packet,
            'agent_control_plane_replay_snapshot_store_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_snapshot_store_implementation_packet_does_not_start_codex',
                'agent_control_plane_replay_snapshot_store_implementation_packet_does_not_advance_pointer',
                'agent_control_plane_replay_snapshot_store_implementation_packet_does_not_dispatch_work',
                'agent_control_plane_replay_snapshot_store_implementation_packet_does_not_execute_adapter',
                'agent_control_plane_replay_snapshot_store_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Replay Snapshot Store implementation packet is ready: scope is the snapshot store, readiness quartet, CLI surface, capabilities, docs and tests; no runtime activation allowed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffContract(array $options = []): array
    {
        $contract = [
            'status' => 'agent_control_plane_replay_diff_contract_ready',
            'contract_id' => 'AGENT-CONTROL-PLANE-REPLAY-DIFF-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'diff_schema_version' => AgentControlPlaneReplayDiffService::SCHEMA_VERSION,
            'diff_service_class' => AgentControlPlaneReplayDiffService::class,
            'invariants' => [
                'diff_is_read_only' => true,
                'diff_does_not_advance_pointer' => true,
                'diff_does_not_mutate_replay' => true,
                'diff_does_not_call_codex_cli_or_app' => true,
                'diff_does_not_start_codex' => true,
                'diff_does_not_invoke_adapter' => true,
                'diff_does_not_execute_adapter' => true,
                'diff_does_not_call_provider' => true,
                'diff_does_not_dispatch_work' => true,
                'diff_does_not_spend_tokens' => true,
                'diff_does_not_enable_self_programming' => true,
                'diff_does_not_write_ledger' => true,
                'diff_emits_deterministic_diff_hash' => true,
                'diff_classifies_status_into_canonical_buckets' => true,
            ],
            'status_buckets' => [
                'no_baseline',
                'no_target',
                'unchanged',
                'improved',
                'regressed',
                'changed_with_warnings',
                'changed',
            ],
            'forbidden_even_after_contract' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'invoke_codex',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'declare_atlas_self_construction_os_complete',
            ],
            'next_required_slice' => 'activate_agent_control_plane_replay_diff_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_diff_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_control_plane_replay_diff_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_diff_contract' => $contract,
            'agent_control_plane_replay_diff_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_diff_contract_does_not_start_codex',
                'agent_control_plane_replay_diff_contract_does_not_advance_pointer',
                'agent_control_plane_replay_diff_contract_does_not_dispatch_work',
                'agent_control_plane_replay_diff_contract_does_not_execute_adapter',
                'agent_control_plane_replay_diff_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Replay Diff contract is ready; it authorizes a deterministic before/after diff of replays while keeping runtime disabled.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffPreflight(array $options = []): array
    {
        $contractPayload = $this->agentControlPlaneReplayDiffContract($options);
        $contract = (array) data_get($contractPayload, 'agent_control_plane_replay_diff_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_control_plane_replay_diff_contract_hash');

        $checks = [
            'contract_ready' => data_get($contractPayload, 'status') === 'agent_control_plane_replay_diff_contract_ready',
            'contract_hash_present' => $contractHash !== '',
            'diff_service_class_exists' => class_exists(AgentControlPlaneReplayDiffService::class),
            'diff_service_method_exists' => class_exists(AgentControlPlaneReplayDiffService::class)
                && method_exists(AgentControlPlaneReplayDiffService::class, 'diff'),
            'snapshot_store_class_exists' => class_exists(AgentControlPlaneReplaySnapshotStore::class),
            'replay_service_class_exists' => class_exists(AgentControlPlaneDeterministicChainReplayService::class),
            'contract_emits_deterministic_diff_hash' => (bool) data_get($contract, 'invariants.diff_emits_deterministic_diff_hash', false),
            'contract_status_buckets_declared' => is_array(data_get($contract, 'status_buckets')) && count((array) data_get($contract, 'status_buckets')) >= 5,
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_control_plane_replay_diff_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-CONTROL-PLANE-REPLAY-DIFF-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_agent_control_plane_replay_diff_service',
                'expose_quartet_methods_on_readiness_service',
                'add_cli_options_for_contract_preflight_implementation_packet_status',
                'register_capabilities_in_agent_control_plane',
                'cover_diff_in_dedicated_test_suite',
                'document_diff_in_agent_control_plane_contract_doc',
                'preserve_runtime_disabled_invariants',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'diff_does_not_mutate_pointer' => true,
                'pointer_mutation_allowed_here' => false,
                'runtime_flags_mutation_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_replay_diff_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_diff_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_control_plane_replay_diff_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_diff_preflight' => $preflight,
            'agent_control_plane_replay_diff_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_diff_preflight_does_not_start_codex',
                'agent_control_plane_replay_diff_preflight_does_not_advance_pointer',
                'agent_control_plane_replay_diff_preflight_does_not_dispatch_work',
                'agent_control_plane_replay_diff_preflight_does_not_execute_adapter',
                'agent_control_plane_replay_diff_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent Control Plane Replay Diff preflight is ready; the diff service can be invoked through the status projection.'
                : 'Agent Control Plane Replay Diff preflight is blocked until the diff service and quartet are in place.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentControlPlaneReplayDiffPreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_control_plane_replay_diff_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_agent_control_plane_replay_diff_implementation',
            'packet_id' => 'AGENT-CONTROL-PLANE-REPLAY-DIFF-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneReplayDiffService.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneReplaySnapshotStore.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneDeterministicChainReplayService.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReplayDiffTest.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'task_count' => 8,
            'acceptance_criteria' => [
                'diff_service_returns_schema_v1',
                'diff_classifies_no_baseline_unchanged_improved_regressed_changed_with_warnings',
                'diff_accepts_snapshot_ids_and_arrays',
                'diff_emits_stable_diff_hash',
                'diff_reports_pointer_change_intentional_reentry',
                'diff_reports_runtime_safety_change',
                'diff_reports_capability_cli_readiness_invoker_doc_matrix_changes',
                'readiness_service_exposes_quartet_methods',
                'cli_exposes_quartet_options',
                'capabilities_registered_in_agent_control_plane',
                'documentation_describes_diff_section',
                'dedicated_test_suite_covers_diff_scenarios',
            ],
            'non_goals' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'enable_runtime_flags',
                'invoke_codex_or_provider',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
            ],
            'implementation_policy' => [
                'diff_is_read_only' => true,
                'pointer_mutation_allowed_by_packet' => false,
                'runtime_flag_mutation_allowed_by_packet' => false,
                'provider_invocation_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_replay_diff_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_diff_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_control_plane_replay_diff_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_diff_implementation_packet' => $packet,
            'agent_control_plane_replay_diff_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_diff_implementation_packet_does_not_start_codex',
                'agent_control_plane_replay_diff_implementation_packet_does_not_advance_pointer',
                'agent_control_plane_replay_diff_implementation_packet_does_not_dispatch_work',
                'agent_control_plane_replay_diff_implementation_packet_does_not_execute_adapter',
                'agent_control_plane_replay_diff_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Replay Diff implementation packet is ready: scope is the diff service, readiness quartet, CLI surface, capabilities, docs and tests; no runtime activation allowed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGateContract(array $options = []): array
    {
        $contract = [
            'status' => 'agent_control_plane_macro_sprint_promotion_gate_contract_ready',
            'contract_id' => 'AGENT-CONTROL-PLANE-MACRO-SPRINT-PROMOTION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'gate_schema_version' => AgentControlPlaneMacroSprintPromotionGate::SCHEMA_VERSION,
            'gate_service_class' => AgentControlPlaneMacroSprintPromotionGate::class,
            'invariants' => [
                'gate_is_read_only' => true,
                'gate_does_not_advance_pointer' => true,
                'gate_does_not_promote_completion_claim' => true,
                'gate_does_not_authorize_runtime_execution' => true,
                'gate_does_not_authorize_provider_call' => true,
                'gate_does_not_authorize_dispatch' => true,
                'gate_does_not_authorize_self_programming' => true,
                'gate_does_not_call_codex_cli_or_app' => true,
                'gate_does_not_start_codex' => true,
                'gate_does_not_invoke_adapter' => true,
                'gate_does_not_execute_adapter' => true,
                'gate_does_not_dispatch_work' => true,
                'gate_does_not_spend_tokens' => true,
                'gate_does_not_write_ledger' => true,
                'gate_classifies_status_into_passed_warning_blocked_no_baseline' => true,
            ],
            'status_buckets' => [
                'no_baseline',
                'passed',
                'warning',
                'blocked',
            ],
            'forbidden_even_after_contract' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'mark_runtime_capable',
                'invoke_codex',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'declare_atlas_self_construction_os_complete',
                'promote_completion_claim',
            ],
            'next_required_slice' => 'activate_agent_control_plane_macro_sprint_promotion_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_control_plane_macro_sprint_promotion_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_macro_sprint_promotion_gate_contract' => $contract,
            'agent_control_plane_macro_sprint_promotion_gate_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_control_plane_macro_sprint_promotion_gate_contract_does_not_start_codex',
                'agent_control_plane_macro_sprint_promotion_gate_contract_does_not_advance_pointer',
                'agent_control_plane_macro_sprint_promotion_gate_contract_does_not_dispatch_work',
                'agent_control_plane_macro_sprint_promotion_gate_contract_does_not_execute_adapter',
                'agent_control_plane_macro_sprint_promotion_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Macro-Sprint Promotion Gate contract is ready; it authorizes a read-only macro-sprint certification check while keeping runtime, completion claim and provider call disabled.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentControlPlaneMacroSprintPromotionGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_control_plane_macro_sprint_promotion_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_control_plane_macro_sprint_promotion_gate_contract_hash');

        $checks = [
            'contract_ready' => data_get($contractPayload, 'status') === 'agent_control_plane_macro_sprint_promotion_gate_contract_ready',
            'contract_hash_present' => $contractHash !== '',
            'gate_service_class_exists' => class_exists(AgentControlPlaneMacroSprintPromotionGate::class),
            'gate_service_method_exists' => class_exists(AgentControlPlaneMacroSprintPromotionGate::class)
                && method_exists(AgentControlPlaneMacroSprintPromotionGate::class, 'evaluate'),
            'diff_service_class_exists' => class_exists(AgentControlPlaneReplayDiffService::class),
            'audit_service_class_exists' => class_exists(AgentControlPlaneChainIntegrityAuditService::class),
            'replay_service_class_exists' => class_exists(AgentControlPlaneDeterministicChainReplayService::class),
            'contract_declares_no_completion_claim' => (bool) data_get($contract, 'invariants.gate_does_not_promote_completion_claim', false),
            'contract_declares_no_runtime_execution' => (bool) data_get($contract, 'invariants.gate_does_not_authorize_runtime_execution', false),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_control_plane_macro_sprint_promotion_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-CONTROL-PLANE-MACRO-SPRINT-PROMOTION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_agent_control_plane_macro_sprint_promotion_gate_service',
                'expose_quartet_methods_on_readiness_service',
                'add_cli_options_for_contract_preflight_implementation_packet_status',
                'register_capabilities_in_agent_control_plane',
                'cover_promotion_gate_in_dedicated_test_suite',
                'document_promotion_gate_in_agent_control_plane_contract_doc',
                'preserve_runtime_disabled_invariants',
                'preserve_completion_claim_disabled_invariant',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'gate_does_not_mutate_pointer' => true,
                'gate_does_not_promote_completion_claim' => true,
                'pointer_mutation_allowed_here' => false,
                'runtime_flags_mutation_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_macro_sprint_promotion_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_control_plane_macro_sprint_promotion_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_macro_sprint_promotion_gate_preflight' => $preflight,
            'agent_control_plane_macro_sprint_promotion_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_control_plane_macro_sprint_promotion_gate_preflight_does_not_start_codex',
                'agent_control_plane_macro_sprint_promotion_gate_preflight_does_not_advance_pointer',
                'agent_control_plane_macro_sprint_promotion_gate_preflight_does_not_dispatch_work',
                'agent_control_plane_macro_sprint_promotion_gate_preflight_does_not_execute_adapter',
                'agent_control_plane_macro_sprint_promotion_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent Control Plane Macro-Sprint Promotion Gate preflight is ready; the gate service can be invoked through the status projection.'
                : 'Agent Control Plane Macro-Sprint Promotion Gate preflight is blocked until the gate service and quartet are in place.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentControlPlaneMacroSprintPromotionGatePreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_control_plane_macro_sprint_promotion_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_agent_control_plane_macro_sprint_promotion_gate_implementation',
            'packet_id' => 'AGENT-CONTROL-PLANE-MACRO-SPRINT-PROMOTION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneMacroSprintPromotionGate.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneReplayDiffService.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneReplaySnapshotStore.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneDeterministicChainReplayService.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneChainIntegrityAuditService.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneMacroSprintPromotionGateTest.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'task_count' => 9,
            'acceptance_criteria' => [
                'promotion_gate_returns_schema_v1',
                'promotion_gate_classifies_no_baseline_passed_warning_blocked',
                'promotion_gate_blocks_on_violations_and_regressions',
                'promotion_gate_blocks_on_runtime_safety_drop',
                'promotion_gate_emits_warnings_when_warning_count_increases',
                'promotion_gate_reports_promotion_allowed_only_when_clean',
                'promotion_gate_supports_optional_docs_health_and_architecture_validate_inputs',
                'promotion_gate_never_promotes_completion_claim',
                'readiness_service_exposes_quartet_methods',
                'cli_exposes_quartet_options',
                'capabilities_registered_in_agent_control_plane',
                'documentation_describes_promotion_gate_section',
                'dedicated_test_suite_covers_promotion_blockers',
            ],
            'non_goals' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'enable_runtime_flags',
                'invoke_codex_or_provider',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'promote_completion_claim',
            ],
            'implementation_policy' => [
                'gate_is_read_only' => true,
                'pointer_mutation_allowed_by_packet' => false,
                'runtime_flag_mutation_allowed_by_packet' => false,
                'provider_invocation_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
                'completion_claim_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_agent_control_plane_macro_sprint_promotion_gate_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_control_plane_macro_sprint_promotion_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_macro_sprint_promotion_gate_implementation_packet' => $packet,
            'agent_control_plane_macro_sprint_promotion_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_control_plane_macro_sprint_promotion_gate_implementation_packet_does_not_start_codex',
                'agent_control_plane_macro_sprint_promotion_gate_implementation_packet_does_not_advance_pointer',
                'agent_control_plane_macro_sprint_promotion_gate_implementation_packet_does_not_dispatch_work',
                'agent_control_plane_macro_sprint_promotion_gate_implementation_packet_does_not_execute_adapter',
                'agent_control_plane_macro_sprint_promotion_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Macro-Sprint Promotion Gate implementation packet is ready: scope is the gate service, readiness quartet, CLI surface, capabilities, docs and tests; no runtime activation and no completion claim allowed.',
        ];
    }
}
