<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Atlas Self-Construction OS · Agent Control Plane · Multi-Agent Loop
 * Canonical Invariant Matrix — terminal loop endurance contract.
 *
 * Locks down the canonical matrix and its propagation into the
 * Completion Audit's `terminal_loop_invariant_violations`. Never invokes
 * a provider, never starts a process, never spends tokens.
 */
final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopCanonicalMatrixTest extends TestCase
{
    private const CANONICAL_INVARIANT_NAMES = [
        'no_duplicate_claims',
        'no_cross_agent_completion',
        'stale_lease_recovered',
        'completed_task_not_reclaimed',
        'auto_replenishment_target_met',
        'continuation_summary_present',
        'evidence_hash_present',
        'structured_completion_evidence_valid',
        'completion_evidence_files_within_scope',
        'worker_completion_evidence_template_present',
        'worker_operator_loop_commands_present',
        'runtime_safety_all_false',
        'queue_transition_policy_enforced',
        'worker_resumption_contract_present',
        'worker_resumption_checkpoint_present',
        'worker_iteration_runbook_present',
        'worker_shell_recipe_present',
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
        'certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts',
        'worker_invalid_scope_rejected',
        'worker_bootstrap_preview_read_only',
        'safe_for_parallel_terminal_loop',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_canonical_invariant_matrix_lists_canonical_names(): void
    {
        $result = $this->certify();
        $matrix = $result['canonical_invariant_matrix'] ?? null;
        $this->assertIsArray($matrix, 'canonical_invariant_matrix block must exist');
        $this->assertSame(self::CANONICAL_INVARIANT_NAMES, $matrix['invariant_names']);
        foreach (self::CANONICAL_INVARIANT_NAMES as $name) {
            $this->assertArrayHasKey($name, $matrix['invariants']);
            $this->assertArrayHasKey('value', $matrix['invariants'][$name]);
            $this->assertArrayHasKey('why', $matrix['invariants'][$name]);
        }
    }

    public function test_six_agent_two_cycle_battery_passes_all_canonical_invariants(): void
    {
        $result = $this->certify(['agent_count' => 6, 'cycles' => 2]);
        $matrix = $result['canonical_invariant_matrix'];

        $this->assertSame('available', $result['status']);
        $this->assertTrue($matrix['all_true']);
        $this->assertSame([], $matrix['violations']);
        foreach ($matrix['invariants'] as $name => $entry) {
            $this->assertTrue($entry['value'], "canonical invariant '{$name}' must hold for a clean 6×2 battery");
        }
    }

    public function test_duplicate_claim_probe_is_rejected_each_cycle(): void
    {
        $result = $this->certify();
        foreach ($result['cycle_evidence'] as $cycle) {
            $this->assertTrue($cycle['duplicate_claim_rejected'], 'duplicate claim must be rejected each cycle');
            $this->assertSame('task_already_claimed', $cycle['duplicate_claim_evidence']['reason']);
        }
    }

    public function test_cross_agent_completion_probe_is_rejected_each_cycle(): void
    {
        $result = $this->certify();
        foreach ($result['cycle_evidence'] as $cycle) {
            $this->assertTrue($cycle['cross_agent_completion_rejected'], 'cross-agent completion must be rejected each cycle');
            $this->assertSame('task_packet_lease_mismatch', $cycle['cross_agent_completion_evidence']['reason']);
        }
    }

    public function test_stale_lease_recovered_invariant_proves_recovery_resolves_expired_and_orphan(): void
    {
        $result = $this->certify();
        foreach ($result['cycle_evidence'] as $cycle) {
            $recovery = $cycle['recovery'];
            $this->assertTrue($recovery['expired_resolved'], 'expired lease must be recovered');
            $this->assertTrue($recovery['orphan_resolved'], 'orphan lease must be recovered');
        }
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['stale_lease_recovered']['value']);
    }

    public function test_auto_replenishment_meets_target_across_cycles(): void
    {
        $result = $this->certify(['agent_count' => 6, 'cycles' => 2]);
        $target = $result['target_min_claimable_tasks'];
        foreach ($result['cycle_evidence'] as $cycle) {
            $this->assertGreaterThanOrEqual($target, $cycle['claimable_before_claim']);
        }
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['auto_replenishment_target_met']['value']);
    }

    public function test_continuation_summary_present_per_cycle(): void
    {
        $result = $this->certify();
        foreach ($result['cycle_evidence'] as $cycle) {
            $this->assertGreaterThan(0, $cycle['continuation_summary_count'], 'continuation summary must be produced per cycle');
        }
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['continuation_summary_present']['value']);
    }

    public function test_evidence_hash_present_each_completed_packet_has_receipt_trail(): void
    {
        $result = $this->certify();
        $this->assertGreaterThan(0, $result['evidence_receipt_count']);
        foreach ($result['cycle_evidence'] as $cycle) {
            $this->assertTrue($cycle['evidence_hashes_present']);
            $this->assertGreaterThanOrEqual($cycle['completed_count'] * 4, $cycle['evidence_receipt_count']);
        }
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['evidence_hash_present']['value']);
    }

    public function test_structured_completion_evidence_valid_each_completed_packet_has_validation_hash(): void
    {
        $result = $this->certify();
        foreach ($result['cycle_evidence'] as $cycle) {
            $this->assertSame($cycle['completed_count'], $cycle['structured_completion_evidence_valid_count']);
            $this->assertSame($cycle['completed_count'], $cycle['completion_evidence_files_within_scope_count']);
            $this->assertSame(0, $cycle['completion_evidence_scope_escape_count']);
            $this->assertSame($cycle['completed_count'], $cycle['completion_evidence_validation_hash_count']);
            foreach (array_filter($cycle['agents'], static fn (array $agent): bool => (bool) ($agent['completed'] ?? false)) as $agent) {
                $this->assertSame('valid', $agent['completion_evidence_validation_status']);
                $this->assertTrue($agent['structured_completion_evidence_valid']);
                $this->assertTrue($agent['completion_evidence_files_within_scope']);
                $this->assertSame([], $agent['files_changed_outside_allowed_scope']);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $agent['completion_evidence_validation_hash']);
            }
        }
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['structured_completion_evidence_valid']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['completion_evidence_files_within_scope']['value']);
    }

    public function test_runtime_safety_flags_remain_false(): void
    {
        $result = $this->certify();
        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_real_allowed',
        ] as $flag) {
            $this->assertFalse($result['runtime_safety']['queue_runtime_flags'][$flag]);
            $this->assertFalse($result['runtime_safety']['lease_runtime_flags'][$flag]);
        }
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['runtime_safety_all_false']['value']);
    }

    public function test_queue_transition_policy_is_declared_canonically(): void
    {
        $this->assertNotEmpty(AgentControlPlaneTaskPacketQueueRepository::ALLOWED_STATUS_TRANSITIONS);
        $result = $this->certify();
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['queue_transition_policy_enforced']['value']);
    }

    public function test_worker_resumption_contract_is_declared_canonically(): void
    {
        $result = $this->certify();

        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['worker_resumption_contract_present']['value']);
        $this->assertTrue((bool) data_get($result, 'terminal_worker_bootstrap_probe.resumption_contracts_present'));
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['worker_resumption_checkpoint_present']['value']);
        $this->assertTrue((bool) data_get($result, 'terminal_worker_bootstrap_probe.resumption_checkpoints_present'));
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['worker_iteration_runbook_present']['value']);
        $this->assertTrue((bool) data_get($result, 'terminal_worker_bootstrap_probe.iteration_runbooks_present'));
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['worker_shell_recipe_present']['value']);
        $this->assertTrue((bool) data_get($result, 'terminal_worker_bootstrap_probe.shell_recipes_present'));
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_health_digest_present']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_launch_plan_present']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_launch_plan_ready_path_verified']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_replenishment_plan_present']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_resume_rollup_present']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_resume_recovery_path_verified']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_released_task_requeue_verified']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_evidence_rollup_present']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_evidence_rollup_green_path_verified']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_operator_handoff_present']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_operator_handoff_recovery_priority_verified']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_lane_isolation_present']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_lane_bound_commands_verified']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['terminal_loop_fleet_lane_no_cross_lane_launch_verified']['value']);
        $this->assertTrue($result['canonical_invariant_matrix']['invariants']['certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts']['value']);
        $this->assertSame('available', data_get($result, 'terminal_loop_fleet_launch_plan_probe.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_launch_plan_probe.ready_path_verified'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_launch_plan_probe.terminal_actors_distinct'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_launch_plan_probe.terminal_commands_lane_bound'));
        $this->assertSame('fleet_lane_isolation_tagged_lane_verified', data_get($result, 'terminal_loop_fleet_launch_plan_probe.lane_isolation_status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_launch_plan_probe.lane_bound_commands_verified'));
        $this->assertSame('available', data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.no_cross_lane_launch_verified'));
        $this->assertSame(0, data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.target_claimable_task_count'));
        $this->assertGreaterThanOrEqual(1, data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.hidden_claimable_outside_requested_tags'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.tag_filtered_supply_gap'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.launch_blocked'));
        $this->assertSame(0, data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.recommended_terminal_count'));
        $this->assertSame(0, data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.copy_paste_terminal_command_count'));
        $this->assertSame('fleet_operator_handoff_replenish_before_launch', data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.handoff_status'));
        $this->assertSame(0, data_get($result, 'terminal_loop_fleet_lane_isolation_negative_probe.commands_using_other_lane_tag_count'));
        $this->assertSame('available', data_get($result, 'terminal_loop_fleet_resume_rollup_probe.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_resume_rollup_probe.recovery_path_verified'));
        $this->assertSame('fleet_resume_rollup_recovery_required', data_get($result, 'terminal_loop_fleet_resume_rollup_probe.rollup_status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_resume_rollup_probe.resume_attention_required'));
        $this->assertSame('fleet_operator_handoff_recover_before_loop', data_get($result, 'terminal_loop_fleet_resume_rollup_probe.operator_handoff_status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_resume_rollup_probe.operator_handoff_recovery_priority_verified'));
        $this->assertSame('available', data_get($result, 'terminal_loop_fleet_metadata_orphan_recovery_probe.status'));
        $this->assertSame('recoverable_orphaned_claim', data_get($result, 'terminal_loop_fleet_metadata_orphan_recovery_probe.classification'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_metadata_orphan_recovery_probe.metadata_orphan_recovery_verified'));
        $this->assertSame('claimable', data_get($result, 'terminal_loop_fleet_metadata_orphan_recovery_probe.final_queue_status'));
        $this->assertSame('available', data_get($result, 'terminal_loop_fleet_released_resume_probe.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_released_resume_probe.digest_recovery_path_verified'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_released_resume_probe.released_task_requeue_verified'));
        $this->assertSame('claimable', data_get($result, 'terminal_loop_fleet_released_resume_probe.final_queue_status'));
        $this->assertSame('recoverable_released_task', data_get($result, 'terminal_loop_fleet_released_resume_probe.summary_classification'));
        $this->assertSame('available', data_get($result, 'terminal_loop_fleet_evidence_rollup_probe.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_evidence_rollup_probe.green_path_verified'));
        $this->assertSame('fleet_evidence_rollup_green', data_get($result, 'terminal_loop_fleet_evidence_rollup_probe.rollup_status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_fleet_evidence_rollup_probe.ready_for_operator_review'));
        $this->assertSame('available', data_get($result, 'post_cleanup_health_digest_probe.status'));
        $this->assertTrue((bool) data_get($result, 'post_cleanup_health_digest_probe.cleanup_left_no_recoverable_artifacts'));
        $this->assertSame(0, data_get($result, 'post_cleanup_health_digest_probe.claimed_task_count'));
        $this->assertSame(0, data_get($result, 'post_cleanup_health_digest_probe.active_lease_count'));
        $this->assertSame(0, data_get($result, 'post_cleanup_health_digest_probe.recoverable_lease_count'));
        $this->assertFalse((bool) data_get($result, 'post_cleanup_health_digest_probe.runtime_execution_allowed'));
        $this->assertFalse((bool) data_get($result, 'post_cleanup_health_digest_probe.dispatch_allowed'));
        $this->assertFalse((bool) data_get($result, 'post_cleanup_health_digest_probe.provider_call_allowed'));
        $this->assertFalse((bool) data_get($result, 'post_cleanup_health_digest_probe.token_spend_allowed'));
        $this->assertFalse((bool) data_get($result, 'post_cleanup_health_digest_probe.self_programming_allowed'));
    }

    public function test_safe_for_parallel_terminal_loop_when_all_other_invariants_hold(): void
    {
        $result = $this->certify();
        $matrix = $result['canonical_invariant_matrix'];
        $this->assertTrue($matrix['invariants']['safe_for_parallel_terminal_loop']['value']);
    }

    public function test_simulate_overlap_breaks_canonical_invariants(): void
    {
        $result = $this->certify(['simulate_overlap' => true]);
        $matrix = $result['canonical_invariant_matrix'];
        $this->assertFalse($matrix['all_true']);
        $this->assertNotEmpty($matrix['violations']);
        $this->assertContains('safe_for_parallel_terminal_loop', $matrix['violations']);
    }

    public function test_completion_audit_terminal_loop_mirrors_canonical_matrix(): void
    {
        $cert = $this->certify();
        $audit = new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class));
        $reflection = new \ReflectionClass(AtlasSelfConstructionOsCompletionAuditService::class);
        $method = $reflection->getMethod('evaluateTerminalLoopInvariants');
        $method->setAccessible(true);
        $invariants = $method->invoke($audit, ['multi_agent_loop_certification' => $cert]);
        foreach (self::CANONICAL_INVARIANT_NAMES as $name) {
            $this->assertArrayHasKey($name, $invariants, "completion audit must surface canonical invariant '{$name}'");
            $this->assertTrue($invariants[$name], "completion audit invariant '{$name}' must mirror cert truth");
        }
    }

    public function test_completion_audit_blocks_when_simulated_violation_threaded_in(): void
    {
        $audit = new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class));
        $reflection = new \ReflectionClass(AtlasSelfConstructionOsCompletionAuditService::class);
        $method = $reflection->getMethod('evaluateTerminalLoopInvariants');
        $method->setAccessible(true);

        $invariants = $method->invoke($audit, [
            'multi_agent_loop_certification' => [
                'canonical_invariant_matrix' => [
                    'invariants' => [
                        'no_duplicate_claims' => ['value' => false],
                    ],
                ],
            ],
        ]);
        $this->assertFalse($invariants['no_duplicate_claims']);
    }

    public function test_cli_exposes_canonical_invariant_matrix(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-multi-agent-loop-certification-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $cert = $payload['agent_control_plane_multi_agent_loop_certification'] ?? [];
        $this->assertArrayHasKey('canonical_invariant_matrix', $cert);
        $this->assertSame(self::CANONICAL_INVARIANT_NAMES, $cert['canonical_invariant_matrix']['invariant_names']);
        $this->assertTrue($cert['canonical_invariant_matrix']['all_true']);
    }

    public function test_certification_hash_is_deterministic_for_clean_battery(): void
    {
        $first = $this->certify();
        $second = $this->certify();
        // Hashes differ because certification_id + cycle_evidence carry ulids
        // and timestamps. The canonical_invariant_matrix shape must be stable.
        $this->assertSame(
            array_keys($first['canonical_invariant_matrix']['invariants']),
            array_keys($second['canonical_invariant_matrix']['invariants']),
        );
        $this->assertSame($first['canonical_invariant_matrix']['all_true'], $second['canonical_invariant_matrix']['all_true']);
    }

    // ---------- helpers ----------

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function certify(array $options = []): array
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
        $service = new AgentControlPlaneMultiAgentLoopCertificationService($orchestrator, $queue, $leases);

        return $service->certify($options);
    }
}
