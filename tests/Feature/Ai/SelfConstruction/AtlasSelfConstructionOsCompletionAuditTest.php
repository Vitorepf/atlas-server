<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopOperationalProofService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOsCompletionAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_completion_audit_blocks_false_completion_claims(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $this->assertSame('atlas.self_construction.os_completion_audit.v1', $audit['schema_version']);
        $this->assertSame('incomplete', $audit['status']);
        $this->assertFalse($audit['completion_allowed']);
        $this->assertFalse($audit['completion_claim_allowed']);
        $this->assertGreaterThan(0, $audit['failed_count']);
        $this->assertNotNull(collect($audit['criteria'])->firstWhere('id', 'runtime_gap_matrix_all_runtime_y'));
        $this->assertContains('human_signed_os_complete_receipt_present', $audit['failed_criteria']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $audit['failed_criteria']);
        $this->assertNotContains('forge_self_improvement_integration_smoke_green', $audit['failed_criteria']);
        $this->assertSame('passed', data_get(collect($audit['criteria'])->firstWhere('id', 'forge_self_improvement_integration_smoke_green'), 'evidence.status'));
        $this->assertSame('continue_implementation_until_failed_completion_criteria_have_real_evidence', $audit['next_action']);
        $this->assertSame('atlas.self_construction.completion_operator_action_packet.v1', data_get($audit, 'operator_action_packet.schema_version'));
        $this->assertSame('operator_action_required', data_get($audit, 'operator_action_packet.status'));
        $this->assertContains('human_signed_os_complete_receipt', data_get($audit, 'operator_action_packet.missing_operator_artifacts'));
        $this->assertContains('real_provider_claim_to_completion_smoke', data_get($audit, 'operator_action_packet.missing_operator_artifacts'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($audit, 'operator_action_packet.operator_action_packet_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit['completion_audit_hash']);

        $runtimeCriterion = collect($audit['criteria'])->firstWhere('id', 'runtime_gap_matrix_all_runtime_y');
        $humanCriterion = collect($audit['criteria'])->firstWhere('id', 'human_signed_os_complete_receipt_present');
        $smokeCriterion = collect($audit['criteria'])->firstWhere('id', 'end_to_end_real_provider_smoke_green');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($runtimeCriterion, 'evidence.expected_runtime_gap_matrix_hash_for_promotion_receipt'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($runtimeCriterion, 'evidence.runtime_promotion_basis_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($runtimeCriterion, 'evidence.runtime_promotion_closure_basis_hash'));
        if (! (bool) data_get($runtimeCriterion, 'passed')) {
            $this->assertSame('human', data_get($runtimeCriterion, 'blocker_type'));
            $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', (string) data_get($runtimeCriterion, 'remediation_command'));
        }
        $this->assertSame('human', data_get($humanCriterion, 'blocker_type'));
        $this->assertSame('real_provider', data_get($smokeCriterion, 'blocker_type'));
        $this->assertStringContainsString('--atlas-self-construction-human-completion-receipt-draft-status', (string) data_get($humanCriterion, 'remediation_command'));
        $this->assertStringContainsString('--atlas-self-construction-real-provider-smoke-draft-status', (string) data_get($smokeCriterion, 'remediation_command'));
        $this->assertSame('atlas.self_construction.runtime_promotion_receipt.v1', data_get($runtimeCriterion, 'expected_receipt_schema'));
        $this->assertSame('atlas.self_construction.human_signed_completion_receipt.v1', data_get($humanCriterion, 'expected_receipt_schema'));
        $this->assertSame('atlas.self_construction.real_provider_smoke_certification.v1', data_get($smokeCriterion, 'expected_receipt_schema'));

        $batchCriterion = collect($audit['criteria'])->firstWhere('id', 'certification_status_batch_green');
        $this->assertTrue((bool) data_get($batchCriterion, 'evidence.full_batch_required'));
        $this->assertGreaterThanOrEqual(49, (int) data_get($batchCriterion, 'evidence.checked_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($batchCriterion, 'evidence.hash'));

        $releaseCriterion = collect($audit['criteria'])->firstWhere('id', 'release_dossier_green');
        $replayCriterion = collect($audit['criteria'])->firstWhere('id', 'replay_diff_against_completion_snapshot_green');
        $humanTemplate = (array) data_get($audit, 'operator_action_packet.human_completion_receipt_template', []);
        $this->assertSame((string) data_get($releaseCriterion, 'evidence.hash'), (string) ($humanTemplate['release_dossier_hash'] ?? ''));
        $this->assertSame((string) data_get($replayCriterion, 'evidence.diff_hash'), (string) ($humanTemplate['replay_diff_hash'] ?? ''));
        $this->assertSame((string) data_get($batchCriterion, 'evidence.hash'), (string) ($humanTemplate['certification_status_batch_hash'] ?? ''));

        $runtimeRows = (array) data_get($audit, 'operator_action_packet.runtime_promotion_receipt_template.promoted_gap_ids', []);
        $this->assertSame((array) data_get($runtimeCriterion, 'evidence.blocked_gap_ids'), $runtimeRows);
    }

    public function test_completion_audit_exposes_prompt_to_artifact_checklist(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();
        $requirements = array_column($audit['prompt_to_artifact_checklist'], 'requirement');

        $this->assertContains('Atlas Self-Construction OS complete', $requirements);
        $this->assertContains('all runtime gaps closed', $requirements);
        $this->assertContains('release dossier green', $requirements);
        $this->assertContains('human signed completion receipt', $requirements);
        $this->assertContains('real provider end-to-end smoke', $requirements);
        $this->assertContains('Forge/Self-Improvement integration smoke', $requirements);
        $this->assertSame($audit['checklist_count'], count($audit['prompt_to_artifact_checklist']));
    }

    public function test_completion_audit_exposes_detailed_blockers_and_audit_blocks(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $this->assertSame($audit['failed_count'], count($audit['failed_criteria_detailed']));
        $runtime = collect($audit['failed_criteria_detailed'])->firstWhere('id', 'runtime_gap_matrix_all_runtime_y');
        $human = collect($audit['failed_criteria_detailed'])->firstWhere('id', 'human_signed_os_complete_receipt_present');
        $smoke = collect($audit['failed_criteria_detailed'])->firstWhere('id', 'end_to_end_real_provider_smoke_green');

        if ($runtime !== null) {
            $this->assertSame('human', data_get($runtime, 'blocker_type'));
            $this->assertNotSame('', (string) data_get($runtime, 'remediation_command'));
            $this->assertNotSame('', (string) data_get($runtime, 'expected_receipt_schema'));
        }
        $this->assertSame('human', data_get($human, 'blocker_type'));
        $this->assertStringContainsString('human-completion-receipt-draft-status', (string) data_get($human, 'remediation_command'));
        $this->assertSame('atlas.self_construction.human_signed_completion_receipt.v1', (string) data_get($human, 'expected_receipt_schema'));
        $this->assertSame('real_provider', data_get($smoke, 'blocker_type'));
        $this->assertStringContainsString('real-provider-smoke-draft-status', (string) data_get($smoke, 'remediation_command'));
        $this->assertSame('atlas.self_construction.real_provider_smoke_certification.v1', (string) data_get($smoke, 'expected_receipt_schema'));

        $this->assertContains('human_signed_os_complete_receipt_present', data_get($audit, 'blocker_classification.human_blockers'));
        $this->assertContains('end_to_end_real_provider_smoke_green', data_get($audit, 'blocker_classification.real_provider_blockers'));
        $this->assertFalse((bool) data_get($audit, 'blocker_classification.completion_allowed'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($audit, 'blocker_classification.human_blocker_count'));
        $this->assertSame(1, (int) data_get($audit, 'blocker_classification.real_provider_blocker_count'));

        foreach ([
            'release_dossier_block',
            'replay_diff_block',
            'promotion_gate_block',
            'mutation_guard_block',
            'chain_integrity_block',
            'runtime_gap_block',
            'certification_status_batch_block',
            'terminal_loop_block',
            'human_signed_receipt_block',
            'real_provider_smoke_block',
            'forge_self_improvement_integration_smoke_block',
        ] as $block) {
            $this->assertArrayHasKey($block, $audit['audit_blocks'], "missing audit_blocks.{$block}");
            $blockPayload = (array) $audit['audit_blocks'][$block];
            foreach (['status', 'blocker_type', 'remediation_command', 'doc_anchor', 'expected_receipt_schema'] as $required) {
                $this->assertArrayHasKey($required, $blockPayload, "block {$block} missing {$required}");
            }
        }

        $this->assertSame(
            data_get($audit, 'agent_control_plane_terminal_loop_certification.certification_hash'),
            data_get($audit, 'audit_blocks.terminal_loop_block.observed_hash'),
        );
        $this->assertSame('blocked', data_get($audit, 'audit_blocks.human_signed_receipt_block.status'));
        $this->assertSame('human', data_get($audit, 'audit_blocks.human_signed_receipt_block.blocker_type'));
        $this->assertSame('blocked', data_get($audit, 'audit_blocks.real_provider_smoke_block.status'));
        $this->assertSame('real_provider', data_get($audit, 'audit_blocks.real_provider_smoke_block.blocker_type'));
        // Release dossier + chain integrity status depend on the snapshot store presence; honour whatever the
        // backing services compute — the test only proves the audit_blocks shape is honest.
        foreach (['release_dossier_block', 'chain_integrity_block'] as $derivedBlock) {
            $this->assertContains(
                (string) data_get($audit, "audit_blocks.{$derivedBlock}.status"),
                ['green', 'blocked', 'unknown'],
                "audit_blocks.{$derivedBlock}.status must be one of green|blocked|unknown",
            );
        }
    }

    public function test_completion_audit_passed_criteria_detailed_carries_evidence_hash(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $this->assertSame($audit['passed_count'], count($audit['passed_criteria_detailed']));
        // Pick any passed criterion present in this environment and check the shape.
        // Storage::fake('local') in setUp can make some technical criteria blocked,
        // so we don't assume which specific criterion will be passed — only that the
        // shape carries id, requirement, evidence_hash, doc_anchor, expected_receipt_schema.
        $this->assertGreaterThan(0, count($audit['passed_criteria_detailed']));
        foreach ((array) $audit['passed_criteria_detailed'] as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayHasKey('requirement', $entry);
            $this->assertArrayHasKey('evidence_hash', $entry);
            $this->assertArrayHasKey('doc_anchor', $entry);
            $this->assertArrayHasKey('expected_receipt_schema', $entry);
        }
    }

    public function test_operator_action_packet_exposes_blockers_receipts_and_human_reasons(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();
        $packet = (array) ($audit['operator_action_packet'] ?? []);

        $this->assertGreaterThanOrEqual(2, (int) ($packet['blocker_count'] ?? 0));
        $blockerIds = array_column((array) ($packet['blockers'] ?? []), 'id');
        $this->assertContains('human_signed_os_complete_receipt', $blockerIds);
        $this->assertContains('real_provider_claim_to_completion_smoke', $blockerIds);

        $humanBlocker = collect((array) $packet['blockers'])->firstWhere('id', 'human_signed_os_complete_receipt');
        $this->assertSame('human', $humanBlocker['blocker_type']);
        $this->assertNotSame('', (string) $humanBlocker['why_not_automatic']);
        $this->assertNotSame('', (string) $humanBlocker['expected_receipt_command']);
        $this->assertNotSame('', (string) $humanBlocker['persist_command']);
        $this->assertSame('atlas.self_construction.human_signed_completion_receipt.v1', (string) $humanBlocker['expected_receipt_schema']);

        $providerBlocker = collect((array) $packet['blockers'])->firstWhere('id', 'real_provider_claim_to_completion_smoke');
        $this->assertSame('real_provider', $providerBlocker['blocker_type']);
        $this->assertStringContainsString('Atlas never starts a provider process', (string) $providerBlocker['why_not_automatic']);

        $schemas = (array) ($packet['expected_receipt_schemas'] ?? []);
        $this->assertArrayHasKey('runtime_promotion_receipt', $schemas);
        $this->assertArrayHasKey('human_signed_os_complete_receipt', $schemas);
        $this->assertArrayHasKey('real_provider_smoke', $schemas);
        $this->assertArrayHasKey('real_provider_smoke_runbook', $schemas);
        $this->assertArrayHasKey('completion_audit', $schemas);
        $this->assertArrayHasKey('release_dossier', $schemas);

        $this->assertContains('atlas_never_self_promotes_os_complete', (array) ($packet['human_judgment_required_reasons'] ?? []));
        $this->assertContains('real_provider_call_is_outside_atlas_token_budget_and_kill_switch_belongs_to_operator', (array) ($packet['human_judgment_required_reasons'] ?? []));

        $smokeRunbook = (array) ($packet['real_provider_smoke_runbook'] ?? []);
        $this->assertArrayHasKey('preflight', $smokeRunbook);
        $this->assertArrayHasKey('kill_switch', $smokeRunbook);
        $this->assertArrayHasKey('rollback_expectations', $smokeRunbook);
        $this->assertArrayHasKey('token_cost_capture_requirements', $smokeRunbook);
        $this->assertArrayHasKey('work_product_collection_requirements', $smokeRunbook);
        $this->assertContains('persist_passed_real_provider_smoke', (array) data_get($smokeRunbook, 'kill_switch.forbidden_after_abort', []));
        $this->assertTrue((bool) data_get($smokeRunbook, 'rollback_expectations.no_atlas_owned_state_mutated'));
    }

    public function test_audit_doc_anchors_point_at_real_contract_doc(): void
    {
        $contractPath = base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md');
        $this->assertFileExists($contractPath);

        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        foreach ((array) $audit['failed_criteria_detailed'] as $entry) {
            $anchor = (string) ($entry['doc_anchor'] ?? '');
            $this->assertNotSame('', $anchor, 'failed criterion '.($entry['id'] ?? '').' missing doc_anchor');
            $this->assertStringContainsString('agent-control-plane-contract.md', $anchor);
        }
        foreach ((array) $audit['passed_criteria_detailed'] as $entry) {
            $anchor = (string) ($entry['doc_anchor'] ?? '');
            $this->assertNotSame('', $anchor, 'passed criterion '.($entry['id'] ?? '').' missing doc_anchor');
        }
        foreach ((array) $audit['audit_blocks'] as $key => $block) {
            $this->assertNotSame('', (string) ($block['doc_anchor'] ?? ''), "block {$key} missing doc_anchor");
        }
    }

    public function test_command_exposes_completion_audit_quartet(): void
    {
        foreach ([
            '--atlas-self-construction-os-completion-audit-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_contract.v1',
            '--atlas-self-construction-os-completion-audit-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_preflight.v1',
            '--atlas-self-construction-os-completion-audit-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_implementation_packet.v1',
            '--atlas-self-construction-os-completion-audit-status' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_status.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }

    public function test_agent_control_plane_lists_completion_audit_capabilities(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_os_completion_audit_contract',
            'atlas_self_construction_os_completion_audit_preflight',
            'atlas_self_construction_os_completion_audit_implementation_packet',
            'atlas_self_construction_os_completion_audit_service',
            'atlas_self_construction_os_completion_audit_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    public function test_audit_includes_agent_control_plane_terminal_loop_certification_block(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $block = $audit['agent_control_plane_terminal_loop_certification'] ?? null;
        $this->assertIsArray($block);
        $this->assertSame(
            AtlasSelfConstructionOsCompletionAuditService::TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION,
            $block['schema_version'] ?? null,
        );
        $this->assertSame(8, $block['module_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $block['certification_hash']);

        $moduleIds = array_column($block['modules'], 'id');
        foreach ([
            'task_auto_replenishment_status',
            'task_queue_claim_next_status',
            'task_queue_complete_dry_run_status',
            'one_shot_worker_packet_status',
            'terminal_worker_bootstrap_status',
            'terminal_loop_health_digest_status',
            'task_lease_recovery_status',
            'multi_agent_loop_certification_status',
        ] as $expected) {
            $this->assertContains($expected, $moduleIds);
        }

        foreach ([
            'runtime_safety_all_false',
            'queue_transition_policy_enforced',
            'no_legacy_reservation_claim',
            'no_legacy_reservation_completion',
            'claim_requires_lease_id_and_agent_id',
            'completion_requires_active_lease',
            'recovery_handles_orphaned_leases',
            'completion_does_not_mark_real_os_completion',
            'completion_evidence_files_within_scope',
            'terminal_loop_health_digest_present',
            'terminal_loop_fleet_launch_plan_present',
            'terminal_loop_fleet_launch_plan_ready_path_verified',
            'terminal_loop_fleet_replenishment_plan_present',
            'terminal_loop_fleet_resume_rollup_present',
            'terminal_loop_fleet_resume_recovery_path_verified',
            'terminal_loop_fleet_metadata_orphan_recovery_verified',
            'terminal_loop_fleet_released_task_requeue_verified',
            'terminal_loop_fleet_evidence_rollup_present',
            'terminal_loop_fleet_evidence_rollup_green_path_verified',
            'terminal_loop_fleet_operator_handoff_present',
            'terminal_loop_fleet_operator_handoff_recovery_priority_verified',
            'terminal_loop_fleet_lane_isolation_present',
            'terminal_loop_fleet_lane_bound_commands_verified',
            'terminal_loop_fleet_lane_no_cross_lane_launch_verified',
            'terminal_loop_cycle_supervisor_present',
            'terminal_loop_cycle_supervisor_launch_path_verified',
            'terminal_loop_cycle_supervisor_evidence_review_path_verified',
        ] as $invariant) {
            $this->assertArrayHasKey($invariant, $block['invariants']);
        }

        $criterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertNotNull($criterion);
        $this->assertSame((bool) $block['passed'], (bool) $criterion['passed']);
    }

    public function test_terminal_loop_block_passes_when_all_modules_and_invariants_green(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();
        $block = $audit['agent_control_plane_terminal_loop_certification'];

        $this->assertTrue($block['passed']);
        $this->assertSame('available', $block['status']);
        $this->assertSame(8, $block['modules_passed']);
        $this->assertSame(0, $block['modules_blocked']);
        $this->assertSame([], $block['invariant_violations']);
        foreach ($block['modules'] as $module) {
            $this->assertTrue($module['passed'], "module {$module['id']} expected passed");
            $this->assertSame([], $module['missing_artifacts']);
            $this->assertTrue($module['readiness_method_available']);
            $this->assertTrue($module['service_class_exists']);
            $this->assertTrue($module['doc_bullet_exists']);
            $this->assertTrue($module['cli_surface_exists']);
        }
    }

    public function test_command_binds_terminal_loop_operational_proof_json_to_completion_audit(): void
    {
        $proofHash = str_repeat('b', 64);
        $proof = [
            'status' => 'passed',
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
            'completion_real_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'post_cycle_cycle_supervisor' => [
                'status' => 'cycle_evidence_review_ready',
                'cycle_state' => 'review_evidence',
                'next_command_purpose' => 'review_completed_dry_run_evidence_and_rerun_digest',
                'hash' => str_repeat('c', 64),
            ],
            'post_cycle_cleanup_state' => $this->terminalLoopCleanupState(),
            'terminal_loop_operational_proof_hash' => $proofHash,
        ];

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-audit-status' => true,
            '--agent-control-plane-terminal-loop-operational-proof-json' => json_encode($proof, JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = $payload['agent_control_plane_atlas_self_construction_os_completion_audit_status'];
        $audit = $payload['agent_control_plane_atlas_self_construction_os_completion_audit'];

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $status['terminal_loop_operational_proof_status']);
        $this->assertTrue($status['terminal_loop_operational_proof_supplied']);
        $this->assertTrue($status['terminal_loop_operational_proof_passed']);
        $this->assertSame($proofHash, $status['terminal_loop_operational_proof_hash']);
        $this->assertSame('cycle_evidence_review_ready', $status['terminal_loop_operational_proof_post_cycle_cycle_supervisor_status']);
        $this->assertSame(str_repeat('c', 64), $status['terminal_loop_operational_proof_post_cycle_cycle_supervisor_hash']);
        $this->assertSame(0, data_get($status, 'terminal_loop_operational_proof_post_cycle_cleanup_state.claimed_task_count'));
        $this->assertSame(0, data_get($status, 'terminal_loop_operational_proof_post_cycle_cleanup_state.active_lease_count'));
        $this->assertSame(0, data_get($status, 'terminal_loop_operational_proof_post_cycle_cleanup_state.recoverable_lease_count'));
        $this->assertFalse($status['terminal_loop_operational_proof_dispatch_allowed']);
        $this->assertFalse($status['terminal_loop_operational_proof_adapter_execution_allowed']);
        $this->assertFalse($status['terminal_loop_operational_proof_self_programming_allowed']);

        $criterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertSame('passed', data_get($criterion, 'evidence.operational_proof_status'));
        $this->assertTrue((bool) data_get($criterion, 'evidence.operational_proof_supplied'));
        $this->assertTrue((bool) data_get($criterion, 'evidence.operational_proof_passed'));
        $this->assertSame($proofHash, data_get($criterion, 'evidence.operational_proof_hash'));
    }

    public function test_command_unwraps_terminal_loop_operational_proof_binding_packet_json(): void
    {
        $proof = (new AgentControlPlaneTerminalLoopOperationalProofService)->prove([
            'actor' => 'command-binding-agent',
            'proof_id' => 'command-binding-proof',
        ]);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-audit-status' => true,
            '--agent-control-plane-terminal-loop-operational-proof-json' => json_encode($proof['completion_audit_binding_packet'], JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = $payload['agent_control_plane_atlas_self_construction_os_completion_audit_status'];

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $status['terminal_loop_operational_proof_status']);
        $this->assertTrue($status['terminal_loop_operational_proof_supplied']);
        $this->assertTrue($status['terminal_loop_operational_proof_passed']);
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], $status['terminal_loop_operational_proof_hash']);
    }

    public function test_command_unwraps_full_terminal_loop_operational_proof_status_json(): void
    {
        $proof = (new AgentControlPlaneTerminalLoopOperationalProofService)->prove([
            'actor' => 'command-full-status-binding-agent',
            'proof_id' => 'command-full-status-binding-proof',
        ]);
        $fullStatusEnvelope = [
            'schema_version' => 'atlas.self_construction_agent_control_plane_terminal_loop_operational_proof_status.v1',
            'status' => 'passed',
            'agent_control_plane_terminal_loop_operational_proof' => $proof,
        ];

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-audit-status' => true,
            '--agent-control-plane-terminal-loop-operational-proof-json' => json_encode($fullStatusEnvelope, JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = $payload['agent_control_plane_atlas_self_construction_os_completion_audit_status'];

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $status['terminal_loop_operational_proof_status']);
        $this->assertTrue($status['terminal_loop_operational_proof_supplied']);
        $this->assertTrue($status['terminal_loop_operational_proof_passed']);
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], $status['terminal_loop_operational_proof_hash']);
        $this->assertSame('cycle_evidence_review_ready', $status['terminal_loop_operational_proof_post_cycle_cycle_supervisor_status']);
    }

    public function test_command_rejects_invalid_terminal_loop_operational_proof_json_with_structured_violations(): void
    {
        $proof = [
            'status' => 'passed',
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
            'completion_real_allowed' => false,
            'provider_call_allowed' => true,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'post_cycle_cycle_supervisor' => [
                'status' => 'cycle_evidence_review_ready',
                'cycle_state' => 'review_evidence',
                'next_command_purpose' => 'review_completed_dry_run_evidence_and_rerun_digest',
                'hash' => str_repeat('c', 64),
            ],
            'post_cycle_cleanup_state' => $this->terminalLoopCleanupState(),
            'terminal_loop_operational_proof_hash' => 'not-a-sha',
        ];

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-audit-status' => true,
            '--agent-control-plane-terminal-loop-operational-proof-json' => json_encode($proof, JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = $payload['agent_control_plane_atlas_self_construction_os_completion_audit_status'];
        $audit = $payload['agent_control_plane_atlas_self_construction_os_completion_audit'];

        $this->assertSame(0, $exit);
        $this->assertSame('supplied_but_not_accepted', $status['terminal_loop_operational_proof_status']);
        $this->assertTrue($status['terminal_loop_operational_proof_supplied']);
        $this->assertFalse($status['terminal_loop_operational_proof_passed']);
        $this->assertSame(2, $status['terminal_loop_operational_proof_validation_violation_count']);
        $this->assertContains('provider_call_allowed_true', $status['terminal_loop_operational_proof_validation_violations']);
        $this->assertContains('invalid_or_missing_operational_proof_hash', $status['terminal_loop_operational_proof_validation_violations']);

        $criterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertSame('supplied_but_not_accepted', data_get($criterion, 'evidence.operational_proof_status'));
        $this->assertFalse((bool) data_get($criterion, 'evidence.operational_proof_passed'));
        $this->assertStringContainsString('rejected', $audit['agent_control_plane_terminal_loop_operational_proof_evidence']['note']);
    }

    public function test_command_rejects_terminal_loop_operational_proof_without_post_cycle_evidence_review(): void
    {
        $proof = [
            'status' => 'passed',
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
            'completion_real_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'post_cycle_cycle_supervisor' => [
                'status' => 'cycle_worker_launch_ready',
                'cycle_state' => 'launch_or_continue_workers',
                'next_command_purpose' => 'launch_lane_bound_terminal_worker',
                'hash' => str_repeat('d', 64),
            ],
            'post_cycle_cleanup_state' => $this->terminalLoopCleanupState(),
            'terminal_loop_operational_proof_hash' => str_repeat('e', 64),
        ];

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-audit-status' => true,
            '--agent-control-plane-terminal-loop-operational-proof-json' => json_encode($proof, JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = $payload['agent_control_plane_atlas_self_construction_os_completion_audit_status'];

        $this->assertSame(0, $exit);
        $this->assertSame('supplied_but_not_accepted', $status['terminal_loop_operational_proof_status']);
        $this->assertTrue($status['terminal_loop_operational_proof_supplied']);
        $this->assertFalse($status['terminal_loop_operational_proof_passed']);
        $this->assertSame(1, $status['terminal_loop_operational_proof_validation_violation_count']);
        $this->assertContains('post_cycle_cycle_supervisor_not_review_evidence', $status['terminal_loop_operational_proof_validation_violations']);
        $this->assertSame('cycle_worker_launch_ready', $status['terminal_loop_operational_proof_post_cycle_cycle_supervisor_status']);
        $this->assertSame(str_repeat('d', 64), $status['terminal_loop_operational_proof_post_cycle_cycle_supervisor_hash']);
    }

    public function test_command_rejects_terminal_loop_operational_proof_with_unclean_post_cycle_state(): void
    {
        $proof = [
            'status' => 'passed',
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
            'completion_real_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'post_cycle_cycle_supervisor' => [
                'status' => 'cycle_evidence_review_ready',
                'cycle_state' => 'review_evidence',
                'next_command_purpose' => 'review_completed_dry_run_evidence_and_rerun_digest',
                'hash' => str_repeat('c', 64),
            ],
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => 1,
                'active_lease_count' => 1,
                'recoverable_lease_count' => 1,
            ],
            'terminal_loop_operational_proof_hash' => str_repeat('f', 64),
        ];

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-audit-status' => true,
            '--agent-control-plane-terminal-loop-operational-proof-json' => json_encode($proof, JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = $payload['agent_control_plane_atlas_self_construction_os_completion_audit_status'];

        $this->assertSame(0, $exit);
        $this->assertSame('supplied_but_not_accepted', $status['terminal_loop_operational_proof_status']);
        $this->assertFalse($status['terminal_loop_operational_proof_passed']);
        $this->assertSame(3, $status['terminal_loop_operational_proof_validation_violation_count']);
        $this->assertContains('post_cycle_claimed_tasks_not_zero', $status['terminal_loop_operational_proof_validation_violations']);
        $this->assertContains('post_cycle_active_leases_not_zero', $status['terminal_loop_operational_proof_validation_violations']);
        $this->assertContains('post_cycle_recoverable_leases_not_zero', $status['terminal_loop_operational_proof_validation_violations']);
    }

    public function test_audit_can_bind_supplied_terminal_loop_operational_proof_without_running_it(): void
    {
        $proofHash = str_repeat('a', 64);
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit([
            'agent_control_plane_terminal_loop_operational_proof' => [
                'status' => 'passed',
                'invariants_all_true' => true,
                'operational_readiness_matrix' => ['all_true' => true],
                'completion_real_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
                'post_cycle_cycle_supervisor' => [
                    'status' => 'cycle_evidence_review_ready',
                    'cycle_state' => 'review_evidence',
                    'next_command_purpose' => 'review_completed_dry_run_evidence_and_rerun_digest',
                    'hash' => str_repeat('c', 64),
                ],
                'post_cycle_cleanup_state' => $this->terminalLoopCleanupState(),
                'terminal_loop_operational_proof_hash' => $proofHash,
            ],
        ]);

        $evidence = $audit['agent_control_plane_terminal_loop_operational_proof_evidence'];
        $this->assertSame('passed', $evidence['status']);
        $this->assertTrue($evidence['supplied']);
        $this->assertTrue($evidence['passed']);
        $this->assertSame($proofHash, $evidence['proof_hash']);
        $this->assertSame('cycle_evidence_review_ready', $evidence['post_cycle_cycle_supervisor_status']);
        $this->assertSame('review_evidence', $evidence['post_cycle_cycle_supervisor_cycle_state']);
        $this->assertSame('review_completed_dry_run_evidence_and_rerun_digest', $evidence['post_cycle_cycle_supervisor_next_command_purpose']);
        $this->assertSame(str_repeat('c', 64), $evidence['post_cycle_cycle_supervisor_hash']);
        $this->assertSame(0, data_get($evidence, 'post_cycle_cleanup_state.claimed_task_count'));
        $this->assertSame(0, data_get($evidence, 'post_cycle_cleanup_state.active_lease_count'));
        $this->assertSame(0, data_get($evidence, 'post_cycle_cleanup_state.recoverable_lease_count'));
        $this->assertFalse($evidence['dispatch_allowed']);
        $this->assertFalse($evidence['adapter_execution_allowed']);
        $this->assertFalse($evidence['self_programming_allowed']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', $evidence['expected_command']);

        $criterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertSame('passed', data_get($criterion, 'evidence.operational_proof_status'));
        $this->assertTrue((bool) data_get($criterion, 'evidence.operational_proof_supplied'));
        $this->assertTrue((bool) data_get($criterion, 'evidence.operational_proof_passed'));
        $this->assertSame($proofHash, data_get($criterion, 'evidence.operational_proof_hash'));
    }

    public function test_audit_accepts_terminal_loop_operational_proof_binding_packet_payload(): void
    {
        $proof = (new AgentControlPlaneTerminalLoopOperationalProofService)->prove([
            'actor' => 'audit-binding-agent',
            'proof_id' => 'audit-binding-proof',
        ]);

        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit([
            'agent_control_plane_terminal_loop_operational_proof' => data_get($proof, 'completion_audit_binding_packet.proof_payload'),
        ]);

        $evidence = $audit['agent_control_plane_terminal_loop_operational_proof_evidence'];
        $this->assertSame('passed', $evidence['status']);
        $this->assertTrue($evidence['supplied']);
        $this->assertTrue($evidence['passed']);
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], $evidence['proof_hash']);

        $criterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertSame('passed', data_get($criterion, 'evidence.operational_proof_status'));
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], data_get($criterion, 'evidence.operational_proof_hash'));
    }

    public function test_audit_accepts_whole_terminal_loop_operational_proof_binding_packet(): void
    {
        $proof = (new AgentControlPlaneTerminalLoopOperationalProofService)->prove([
            'actor' => 'audit-whole-binding-agent',
            'proof_id' => 'audit-whole-binding-proof',
        ]);

        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit([
            'agent_control_plane_terminal_loop_operational_proof' => $proof['completion_audit_binding_packet'],
        ]);

        $evidence = $audit['agent_control_plane_terminal_loop_operational_proof_evidence'];
        $this->assertSame('passed', $evidence['status']);
        $this->assertTrue($evidence['passed']);
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], $evidence['proof_hash']);
    }

    public function test_audit_accepts_full_terminal_loop_operational_proof_envelope(): void
    {
        $proof = (new AgentControlPlaneTerminalLoopOperationalProofService)->prove([
            'actor' => 'audit-full-envelope-binding-agent',
            'proof_id' => 'audit-full-envelope-binding-proof',
        ]);

        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit([
            'agent_control_plane_terminal_loop_operational_proof' => [
                'schema_version' => 'atlas.self_construction_agent_control_plane_terminal_loop_operational_proof_status.v1',
                'status' => 'passed',
                'agent_control_plane_terminal_loop_operational_proof' => $proof,
            ],
        ]);

        $evidence = $audit['agent_control_plane_terminal_loop_operational_proof_evidence'];
        $this->assertSame('passed', $evidence['status']);
        $this->assertTrue($evidence['passed']);
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], $evidence['proof_hash']);
        $this->assertSame('cycle_evidence_review_ready', $evidence['post_cycle_cycle_supervisor_status']);
    }

    public function test_audit_reports_terminal_loop_operational_proof_not_supplied_without_blocking_read_only_audit(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $evidence = $audit['agent_control_plane_terminal_loop_operational_proof_evidence'];
        $this->assertSame('not_supplied_to_read_only_audit', $evidence['status']);
        $this->assertFalse($evidence['supplied']);
        $this->assertFalse($evidence['passed']);
        $this->assertSame('', $evidence['proof_hash']);
        $this->assertStringContainsString('does not run the operational proof', $evidence['note']);

        $criterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertSame('not_supplied_to_read_only_audit', data_get($criterion, 'evidence.operational_proof_status'));
        $this->assertFalse((bool) data_get($criterion, 'evidence.operational_proof_supplied'));
        $this->assertTrue((bool) $criterion['passed']);
    }

    public function test_terminal_loop_block_fails_when_module_artifact_synthetically_missing(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit([
            'agent_control_plane_terminal_loop_certification' => [
                'module_overrides' => [
                    'task_auto_replenishment_status' => [
                        'service_class_exists' => false,
                    ],
                ],
            ],
        ]);

        $block = $audit['agent_control_plane_terminal_loop_certification'];
        $this->assertFalse($block['passed']);
        $this->assertSame('blocked', $block['status']);
        $this->assertGreaterThanOrEqual(1, $block['modules_blocked']);

        $module = collect($block['modules'])->firstWhere('id', 'task_auto_replenishment_status');
        $this->assertFalse($module['passed']);
        $this->assertContains('service_class', $module['missing_artifacts']);

        $criterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertFalse((bool) $criterion['passed']);
        $this->assertContains('agent_control_plane_terminal_loop_certification_green', $audit['failed_criteria']);
    }

    public function test_terminal_loop_block_fails_when_invariant_violated(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit([
            'agent_control_plane_terminal_loop_certification' => [
                'invariant_overrides' => [
                    'no_legacy_reservation_claim' => false,
                ],
            ],
        ]);

        $block = $audit['agent_control_plane_terminal_loop_certification'];
        $this->assertFalse($block['passed']);
        $this->assertContains('no_legacy_reservation_claim', $block['invariant_violations']);
        $this->assertContains('agent_control_plane_terminal_loop_certification_green', $audit['failed_criteria']);
    }

    public function test_audit_runtime_flags_all_false(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $this->assertTrue($audit['runtime_safety']['runtime_safety_all_false']);
        $this->assertFalse($audit['runtime_safety']['execution_allowed']);
        $this->assertFalse($audit['runtime_safety']['dispatch_allowed']);
        $this->assertFalse($audit['runtime_safety']['provider_call_allowed']);
        $this->assertFalse($audit['runtime_safety']['token_spend_allowed']);
        $this->assertFalse($audit['runtime_safety']['adapter_execution_allowed']);
        $this->assertFalse($audit['runtime_safety']['self_programming_allowed']);

        $loop = $audit['agent_control_plane_terminal_loop_certification']['runtime_safety'];
        $this->assertTrue($loop['runtime_safety_all_false']);
        $this->assertFalse($loop['execution_allowed']);
        $this->assertFalse($loop['dispatch_allowed']);
        $this->assertFalse($loop['provider_call_allowed']);
        $this->assertFalse($loop['token_spend_allowed']);
        $this->assertFalse($loop['self_programming_allowed']);
    }

    public function test_completion_remains_incomplete_when_terminal_loop_green_but_human_blockers_remain(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $loopCriterion = collect($audit['criteria'])->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green');
        $this->assertTrue((bool) $loopCriterion['passed']);

        $this->assertSame('incomplete', $audit['status']);
        $this->assertFalse($audit['completion_allowed']);
        $this->assertFalse($audit['completion_claim_allowed']);
        $this->assertGreaterThan(0, $audit['failed_count']);
        $this->assertContains('human_signed_os_complete_receipt_present', $audit['failed_criteria']);
        $this->assertFalse((bool) data_get($audit, 'blocker_classification.completion_allowed'));
    }

    public function test_checklist_includes_terminal_loop_row(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $artifacts = array_column($audit['prompt_to_artifact_checklist'], 'artifact');
        $this->assertContains(
            AtlasSelfConstructionOsCompletionAuditService::TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION,
            $artifacts,
        );
    }

    /**
     * @return array<string, int>
     */
    private function terminalLoopCleanupState(): array
    {
        return [
            'claimed_task_count' => 0,
            'active_lease_count' => 0,
            'recoverable_lease_count' => 0,
        ];
    }
}
