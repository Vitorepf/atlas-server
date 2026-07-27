<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\WriteSetOverlap;
use App\Models\AtlasSelfConstructionAgentCostEvent;
use App\Models\AtlasSelfConstructionAgentWorkProduct;

/**
 * GOD-DEBULK extracted stateful packet-queue/session/automatic-policy family from AtlasSelfConstructionReadinessService (complete packet, ai session bootstrap, packet queue, collision matrix, agent automatic cost-import policy, agent automatic work-product-collection policy).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionPacketQueuePolicySection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionPacketQueuePolicySection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function completePacket(array $options = []): array
    {
        $packetId = (string) ($options['packet'] ?? '');
        $completion = $this->reservations->complete(
            packetId: $packetId,
            actor: $this->reservationActor($options),
            session: $this->reservationSession($options),
            reason: (string) ($options['reason'] ?? 'operator_reported_packet_complete'),
            evidenceHash: $options['evidence_hash'] ?? null,
        );

        return [
            'schema_version' => 'atlas.self_construction_complete_packet.v1',
            'status' => data_get($completion, 'status') === 'completed' ? 'completed' : 'blocked',
            'mode' => 'durable_local_packet_completion',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'completion_persisted' => data_get($completion, 'status') === 'completed',
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'completion' => [
                ...$completion,
                'packet_id' => $packetId,
            ],
            'completion_hash' => $this->stableHash($completion),
            'non_execution_guarantees' => [
                'complete_packet_does_not_approve_code',
                'complete_packet_does_not_dispatch_work',
                'complete_packet_does_not_enable_execution',
                'complete_packet_does_not_auto_merge',
            ],
            'human_summary' => data_get($completion, 'status') === 'completed'
                ? 'Packet reservation was durably marked completed in the local ledger. This records packet state only; it does not approve code or bypass gates.'
                : 'Packet completion was blocked because no active owner-matching reservation was found.',
        ];
    }

    public function aiSessionBootstrap(array $options = []): array
    {
        $assignmentPayload = $this->assignmentPreview($options);
        $reservationPayload = $this->reservationLedgerPreview($options);
        $runbookPayload = $this->packetRunbook($options);
        $scopePayload = $this->scopeValidator($options);
        $evidencePayload = $this->packetEvidenceReport($options);
        $completionGatePayload = $this->packetCompletionGate($options);

        $bootstrap = [
            'bootstrap_id' => 'BOOTSTRAP-SELF-CONSTRUCTION-AI-SESSION-0001',
            'session_mode' => 'read_only_ai_bootstrap',
            'selected_packet_id' => data_get($assignmentPayload, 'assignment.selected_packet_id'),
            'assignment_hash' => data_get($assignmentPayload, 'assignment_hash'),
            'reservation_hash' => data_get($reservationPayload, 'reservation_hash'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'scope_validator_status' => data_get($scopePayload, 'status'),
            'evidence_report_status' => data_get($evidencePayload, 'status'),
            'completion_gate_status' => data_get($completionGatePayload, 'status'),
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                $this->packetCommand('ai-session-bootstrap', data_get($assignmentPayload, 'assignment.selected_packet_id')),
                $this->packetCommand('scope-validator', data_get($assignmentPayload, 'assignment.selected_packet_id')),
            ],
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) data_get($assignmentPayload, 'assignment.stop_conditions', []),
                (array) data_get($reservationPayload, 'reservation.stop_conditions', []),
                [
                    'bootstrap_hash_changed',
                    'reservation_hash_changed',
                    'scope_validator_blocked',
                    'packet_completion_gate_blocked',
                    'operator_evidence_missing',
                ],
            ))),
            'forbidden_hot_scopes' => $this->hotForbiddenFiles(),
            'payload_refs' => [
                'assignment_schema' => data_get($assignmentPayload, 'schema_version'),
                'reservation_schema' => data_get($reservationPayload, 'schema_version'),
                'runbook_schema' => data_get($runbookPayload, 'schema_version'),
                'scope_validator_schema' => data_get($scopePayload, 'schema_version'),
                'evidence_report_schema' => data_get($evidencePayload, 'schema_version'),
                'completion_gate_schema' => data_get($completionGatePayload, 'schema_version'),
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_ai_session_bootstrap.v1',
            'status' => data_get($assignmentPayload, 'status') === 'claim_preview_ready'
                ? 'bootstrap_ready'
                : 'blocked_by_assignment',
            'mode' => 'read_only_ai_session_bootstrap',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'bootstrap' => $bootstrap,
            'bootstrap_hash' => $this->stableHash($bootstrap),
            'non_execution_guarantees' => [
                'ai_session_bootstrap_does_not_persist_claim',
                'ai_session_bootstrap_does_not_write_ledger',
                'ai_session_bootstrap_does_not_apply_patch',
                'ai_session_bootstrap_does_not_enable_execution',
            ],
            'human_summary' => 'AI session bootstrap is ready: a new AI can read one canonical packet, but claims, ledger writes, execution and completion remain disabled.',
        ];
    }

    public function packetQueue(array $options = []): array
    {
        $splitter = $this->workSplitter($options);
        $packets = (array) data_get($splitter, 'split.packets', []);
        $withheld = (array) data_get($splitter, 'split.withheld_work', []);
        $activeReservations = $this->reservations->activeByPacket();
        $completedReservations = $this->reservations->completedByPacket();

        $entries = [];
        foreach ($packets as $index => $packet) {
            $dependsOn = (array) data_get($packet, 'depends_on', []);
            $packetId = (string) data_get($packet, 'packet_id');
            $activeReservation = $activeReservations[$packetId] ?? null;
            $completedReservation = $completedReservations[$packetId] ?? null;
            $dependenciesComplete = $dependsOn === []
                || count(array_diff($dependsOn, array_keys($completedReservations))) === 0;
            $queueState = match (true) {
                is_array($completedReservation) => 'completed',
                is_array($activeReservation) => 'claimed',
                $dependenciesComplete => 'available',
                default => 'blocked_by_dependency',
            };

            $entries[] = [
                'packet_id' => $packetId,
                'lane' => data_get($packet, 'lane'),
                'objective' => data_get($packet, 'objective'),
                'queue_state' => $queueState,
                'rank' => $queueState === 'available' ? $index + 1 : null,
                'active_reservation_id' => data_get($activeReservation, 'reservation_id'),
                'active_reservation_actor' => data_get($activeReservation, 'actor'),
                'active_reservation_session' => data_get($activeReservation, 'session'),
                'lease_expires_at' => data_get($activeReservation, 'lease_expires_at'),
                'completed_reservation_id' => data_get($completedReservation, 'reservation_id'),
                'completed_at' => data_get($completedReservation, 'completed_at'),
                'completion_actor' => data_get($completedReservation, 'actor'),
                'claim_policy' => data_get($packet, 'claim_policy'),
                'collision_risk' => data_get($packet, 'collision_risk'),
                'recommended' => false,
                'depends_on' => $dependsOn,
                'allowed_files' => (array) data_get($packet, 'allowed_files', []),
                'forbidden_files' => (array) data_get($packet, 'forbidden_files', []),
                'required_bootstrap_command' => $this->packetCommand('ai-session-bootstrap', data_get($packet, 'packet_id')),
            ];
        }

        foreach ($withheld as $index => $item) {
            $entries[] = [
                'packet_id' => data_get($item, 'id'),
                'lane' => 'withheld_hot_external',
                'objective' => data_get($item, 'reason'),
                'queue_state' => 'withheld',
                'rank' => null,
                'claim_policy' => 'not_assignable',
                'collision_risk' => 'blocked',
                'recommended' => false,
                'depends_on' => [],
                'allowed_files' => [],
                'forbidden_files' => [data_get($item, 'forbidden_scope')],
                'withheld_order' => $index + 1,
            ];
        }

        $firstAvailableIndex = null;
        foreach ($entries as $index => $entry) {
            if ($entry['queue_state'] === 'available') {
                $firstAvailableIndex = $index;
                break;
            }
        }
        if ($firstAvailableIndex !== null) {
            $entries[$firstAvailableIndex]['recommended'] = true;
        }

        $queue = [
            'queue_id' => 'PACKET-QUEUE-SELF-CONSTRUCTION-READ-ONLY-0001',
            'source_split_hash' => data_get($splitter, 'split_hash'),
            'entry_count' => count($entries),
            'available_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'available')),
            'blocked_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'blocked_by_dependency')),
            'claimed_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'claimed')),
            'completed_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'completed')),
            'withheld_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'withheld')),
            'recommended_packet_id' => data_get(collect($entries)->firstWhere('recommended', true), 'packet_id'),
            'entries' => $entries,
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_queue.v1',
            'status' => 'packet_queue_ready',
            'mode' => 'read_only_packet_queue',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
            'queue' => $queue,
            'queue_hash' => $this->stableHash($queue),
            'non_execution_guarantees' => [
                'packet_queue_does_not_persist_claim',
                'packet_queue_does_not_write_ledger',
                'packet_queue_does_not_dispatch_work',
                'packet_queue_does_not_enable_execution',
            ],
            'human_summary' => 'Packet queue is ready: available, blocked and withheld packets are visible without claims, dispatch, ledger writes or execution.',
        ];
    }

    public function collisionMatrix(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $pairs = [];

        for ($left = 0; $left < count($entries); $left++) {
            for ($right = $left + 1; $right < count($entries); $right++) {
                $leftEntry = $entries[$left];
                $rightEntry = $entries[$right];
                $leftAllowed = array_filter((array) data_get($leftEntry, 'allowed_files', []));
                $rightAllowed = array_filter((array) data_get($rightEntry, 'allowed_files', []));
                $overlap = WriteSetOverlap::collidingPaths($leftAllowed, $rightAllowed); // A5/MF-12: prefix-aware dir-vs-file
                $leftDepends = (array) data_get($leftEntry, 'depends_on', []);
                $rightDepends = (array) data_get($rightEntry, 'depends_on', []);
                $dependencyRelated = in_array(data_get($leftEntry, 'packet_id'), $rightDepends, true)
                    || in_array(data_get($rightEntry, 'packet_id'), $leftDepends, true);
                $hotScopePresent = data_get($leftEntry, 'queue_state') === 'withheld'
                    || data_get($rightEntry, 'queue_state') === 'withheld'
                    || $this->hasHotScope($leftAllowed)
                    || $this->hasHotScope($rightAllowed);
                $collision = $overlap !== [] || $dependencyRelated || $hotScopePresent;

                $pairs[] = [
                    'left_packet_id' => data_get($leftEntry, 'packet_id'),
                    'right_packet_id' => data_get($rightEntry, 'packet_id'),
                    'overlap' => $overlap,
                    'dependency_related' => $dependencyRelated,
                    'hot_scope_present' => $hotScopePresent,
                    'collision' => $collision,
                    'decision' => $collision ? 'blocked' : 'parallel_safe',
                ];
            }
        }

        $safePackets = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'),
        ));

        $matrix = [
            'matrix_id' => 'COLLISION-MATRIX-SELF-CONSTRUCTION-READ-ONLY-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'entry_count' => count($entries),
            'pair_count' => count($pairs),
            'safe_pair_count' => count(array_filter($pairs, fn (array $pair): bool => $pair['decision'] === 'parallel_safe')),
            'blocked_pair_count' => count(array_filter($pairs, fn (array $pair): bool => $pair['decision'] === 'blocked')),
            'pairs' => $pairs,
            'safe_parallel_groups' => [
                [
                    'group_id' => 'SAFE-PARALLEL-GROUP-001',
                    'packet_ids' => $safePackets,
                    'execution_allowed' => false,
                    'claim_persisted' => false,
                ],
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_collision_matrix.v1',
            'status' => 'collision_matrix_ready',
            'mode' => 'read_only_collision_matrix',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'matrix' => $matrix,
            'matrix_hash' => $this->stableHash($matrix),
            'non_execution_guarantees' => [
                'collision_matrix_does_not_persist_claim',
                'collision_matrix_does_not_write_ledger',
                'collision_matrix_does_not_dispatch_work',
                'collision_matrix_does_not_enable_execution',
            ],
            'human_summary' => 'Collision matrix is ready: packet overlap and hot scopes are visible without claims, dispatch, ledger writes or execution.',
        ];
    }

    public function agentAutomaticCostImportPolicy(array $options = []): array
    {
        $processSupervisionPayload = $this->agentProviderProcessSupervisionPolicy($options);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'provider_process_supervision_policy' => data_get($processSupervisionPayload, 'status') === 'agent_provider_process_supervision_policy_ready',
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'agent_cost_events_table' => $runtimeTables['atlas_self_construction_agent_cost_events'],
            'agent_cost_event_model' => class_exists(AtlasSelfConstructionAgentCostEvent::class),
            'manual_cost_event_writer' => method_exists($this->mother, 'agentCostEvent'),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_automatic_cost_import_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-AUTOMATIC-COST-IMPORT-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'provider_process_supervision_policy' => data_get($processSupervisionPayload, 'agent_provider_process_supervision_policy_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'import_sources_allowed_after_release' => [
                'provider_adapter_usage_payload',
                'supervised_process_usage_summary',
                'operator_supplied_cost_event',
                'provider_usage_export_reconciled_by_run_key',
            ],
            'normalization_contract' => [
                'required_identity' => ['run_key', 'provider', 'model', 'occurred_at', 'source_hash'],
                'required_metrics' => ['input_tokens', 'output_tokens', 'cost_usd'],
                'required_metadata' => ['packet_id', 'actor', 'session', 'import_source', 'import_policy_hash'],
                'idempotency_key' => 'sha256(run_key|provider|model|occurred_at|source_hash)',
            ],
            'cost_quality_guards' => [
                'reject_negative_tokens',
                'reject_negative_cost',
                'dedupe_by_idempotency_key',
                'attach_cost_to_existing_agent_run_only',
                'preserve_raw_usage_hash_without_storing_sensitive_prompt_text',
                'mark_unpriced_usage_as_zero_cost_with_pricing_missing_reason',
            ],
            'allowed_now' => [
                'automatic_cost_import_policy_projection',
                'cost_schema_readiness_check',
                'manual_cost_writer_contract_reference',
            ],
            'forbidden_now' => [
                'read_provider_billing_api',
                'parse_live_provider_logs',
                'write_cost_events_automatically',
                'mutate_run_totals_from_import_policy',
                'start_or_supervise_provider_process',
                'spend_provider_tokens',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'automatic_import_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'billing_api_access_allowed_here' => false,
                'requires_future_signed_cost_import_execution_gate' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_policy'
                : 'repair_automatic_cost_import_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_cost_import_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_automatic_cost_import_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'automatic_import_allowed' => false,
            'billing_api_access_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_cost_import_policy' => $policy,
            'agent_automatic_cost_import_policy_hash' => $this->stableHash($policy),
            'non_execution_guarantees' => [
                'agent_automatic_cost_import_policy_does_not_call_billing_apis',
                'agent_automatic_cost_import_policy_does_not_parse_live_provider_logs',
                'agent_automatic_cost_import_policy_does_not_write_cost_events',
                'agent_automatic_cost_import_policy_does_not_start_providers',
                'agent_automatic_cost_import_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic cost import policy is ready: Atlas has the normalization and guard contract, but automatic import remains disabled until a signed execution gate.'
                : 'Automatic cost import policy is blocked until every cost import prerequisite is ready.',
        ];
    }

    public function agentAutomaticWorkProductCollectionPolicy(array $options = []): array
    {
        $costImportPayload = $this->agentAutomaticCostImportPolicy($options);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'automatic_cost_import_policy' => data_get($costImportPayload, 'status') === 'agent_automatic_cost_import_policy_ready',
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'agent_work_products_table' => $runtimeTables['atlas_self_construction_agent_work_products'],
            'agent_work_product_model' => class_exists(AtlasSelfConstructionAgentWorkProduct::class),
            'manual_work_product_registry' => method_exists($this->mother, 'agentWorkProduct'),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_automatic_work_product_collection_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-AUTOMATIC-WORK-PRODUCT-COLLECTION-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'automatic_cost_import_policy' => data_get($costImportPayload, 'agent_automatic_cost_import_policy_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'collection_sources_allowed_after_release' => [
                'provider_adapter_return_envelope',
                'supervised_process_output_manifest',
                'operator_supplied_artifact_pointer',
                'workspace_diff_manifest',
                'test_and_gate_output_manifest',
            ],
            'normalization_contract' => [
                'required_identity' => ['run_key', 'packet_id', 'artifact_type', 'artifact_hash', 'source_hash'],
                'required_metadata' => ['actor', 'session', 'provider', 'collection_source', 'collection_policy_hash'],
                'idempotency_key' => 'sha256(run_key|packet_id|artifact_type|artifact_hash|source_hash)',
                'artifact_hash_algorithm' => 'sha256',
            ],
            'work_product_quality_guards' => [
                'reject_artifact_without_existing_agent_run',
                'reject_path_outside_allowed_workspace_scope',
                'require_hash_for_patch_diff_test_output_and_report_artifacts',
                'dedupe_by_idempotency_key',
                'store_pointer_and_hash_not_sensitive_raw_payload_by_default',
                'link_artifact_to_packet_run_actor_provider_and_evidence_chain',
            ],
            'allowed_now' => [
                'automatic_work_product_collection_policy_projection',
                'work_product_schema_readiness_check',
                'manual_work_product_registry_contract_reference',
            ],
            'forbidden_now' => [
                'scan_workspace_files_automatically',
                'read_provider_output_streams',
                'write_work_products_automatically',
                'mutate_packet_completion_state',
                'start_or_supervise_provider_process',
                'dispatch_work_to_provider',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'automatic_collection_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'workspace_scan_allowed_here' => false,
                'requires_future_signed_work_product_collection_execution_gate' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_runtime_execution_gate'
                : 'repair_automatic_work_product_collection_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_work_product_collection_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_automatic_work_product_collection_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'automatic_collection_allowed' => false,
            'workspace_scan_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_work_product_collection_policy' => $policy,
            'agent_automatic_work_product_collection_policy_hash' => $this->stableHash($policy),
            'non_execution_guarantees' => [
                'agent_automatic_work_product_collection_policy_does_not_scan_workspace',
                'agent_automatic_work_product_collection_policy_does_not_read_provider_streams',
                'agent_automatic_work_product_collection_policy_does_not_write_work_products',
                'agent_automatic_work_product_collection_policy_does_not_start_providers',
                'agent_automatic_work_product_collection_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic work product collection policy is ready: Atlas has the artifact normalization and guard contract, but automatic collection remains disabled until a signed execution gate.'
                : 'Automatic work product collection policy is blocked until every artifact collection prerequisite is ready.',
        ];
    }

}
