<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtGateReplayPlan;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasVerificationCourtGateReplayPlan: low-risk service task ⇒ phpunit-scoped + diff-check;
 * docs-only task ⇒ docs-health command; multi-project lane task ⇒ lane-freshness command appears;
 * blocked when evidence_contract_result.accepted=false; command ids are deterministic across calls.
 */
final class AtlasVerificationCourtGateReplayPlanTest extends TestCase
{
    public function test_vague_declared_gate_with_changed_files_emits_acceptance_specificity_check(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Demo/Foo.php'],
            'risk_level' => 'low',
            'packet_facts' => ['declared_gates' => ['php artisan test']],
        ]);
        $names = array_column($r['commands'], 'name');
        $this->assertContains('acceptance_specificity_check', $names);
    }

    public function test_concrete_declared_gate_naming_changed_file_does_not_emit_specificity_check(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Demo/Foo.php'],
            'risk_level' => 'low',
            'packet_facts' => ['declared_gates' => ['php artisan test tests/Unit/Demo/FooTest.php']],
        ]);
        $names = array_column($r['commands'], 'name');
        $this->assertNotContains('acceptance_specificity_check', $names);
    }

    public function test_low_risk_service_task_emits_phpunit_and_diff_check(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Demo/Foo.php'],
            'risk_level' => 'low',
            'packet_facts' => ['declared_gates' => []],
        ]);
        $this->assertSame(AtlasVerificationCourtGateReplayPlan::STATUS_READY, $r['plan_status']);
        $names = array_column($r['commands'], 'name');
        $this->assertContains('phpunit_scoped', $names);
        $this->assertContains('diff_style_check', $names);
    }

    public function test_docs_only_task_emits_docs_health(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['docs/changelog.md'],
            'risk_level' => 'low',
        ]);
        $names = array_column($r['commands'], 'name');
        $this->assertContains('docs_health_check', $names);
    }

    public function test_multi_project_lane_emits_lane_freshness_check(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Demo/Foo.php'],
            'risk_level' => 'medium',
            'project_lane' => ['project_id' => 'demo-lane', 'allowed_scope_roots' => ['app/Demo']],
        ]);
        $names = array_column($r['commands'], 'name');
        $this->assertContains('lane_freshness_check', $names);
    }

    public function test_blocked_when_evidence_contract_not_accepted(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => false],
            'changed_files' => ['app/Demo/Foo.php'],
        ]);
        $this->assertSame(AtlasVerificationCourtGateReplayPlan::STATUS_BLOCKED, $r['plan_status']);
        $this->assertContains('evidence_contract_not_accepted', $r['blockers']);
    }

    public function test_no_changes_no_declared_gates_yields_no_replayable_gate(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => [],
        ]);
        $this->assertSame(AtlasVerificationCourtGateReplayPlan::STATUS_BLOCKED, $r['plan_status']);
        $this->assertContains('no_replayable_gate_derivable', $r['blockers']);
    }

    public function test_command_ids_are_deterministic_across_calls(): void
    {
        $facts = [
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Foo.php'],
            'packet_facts' => ['declared_gates' => ['static_analysis']],
        ];
        $p = new AtlasVerificationCourtGateReplayPlan;
        $a = json_encode($p->derive($facts), JSON_UNESCAPED_SLASHES);
        $b = json_encode($p->derive($facts), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b);
    }

    public function test_high_risk_change_requires_false_green_guard_command(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'risk_level' => 'high',
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('false_green_guard', $names);
        $this->assertSame(AtlasVerificationCourtGateReplayPlan::STATUS_READY, $r['plan_status']);
    }

    public function test_commands_carry_changed_file_filter_and_evidence_hash(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'risk_level' => 'low',
        ]);

        foreach ($r['commands'] as $cmd) {
            $this->assertArrayHasKey('changed_file_filter', $cmd);
            $this->assertArrayHasKey('evidence_hash', $cmd);
            $this->assertIsArray($cmd['changed_file_filter']);
            // every command with files must have a non-null hash
            if ($cmd['changed_file_filter'] !== []) {
                $this->assertIsString($cmd['evidence_hash']);
                $this->assertNotEmpty($cmd['evidence_hash']);
            }
        }
    }

    public function test_broad_scope_triggers_false_green_guard_regardless_of_risk_level(): void
    {
        $manyFiles = array_map(static fn (int $i): string => "app/Services/File{$i}.php", range(1, AtlasVerificationCourtGateReplayPlan::BROAD_SCOPE_THRESHOLD));
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => $manyFiles,
            'risk_level' => 'low',
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('false_green_guard', $names);
    }

    public function test_declared_gates_appear_as_commands(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Foo.php'],
            'packet_facts' => ['declared_gates' => ['pint', 'static_analysis']],
        ]);
        $names = array_column($r['commands'], 'name');
        $this->assertContains('pint', $names);
        $this->assertContains('static_analysis', $names);
    }

    // ── worker-floor replay steps ─────────────────────────────────────────────

    public function test_queue_touching_task_includes_all_worker_floor_replay_steps(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketQueueRepository.php'],
            'packet_facts' => ['declared_gates' => []],
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('worker_floor_queue_health_check', $names);
        $this->assertContains('worker_floor_queued_target_collision_check', $names);
        $this->assertContains('worker_floor_malformed_sweep', $names);
        $this->assertContains('worker_floor_check', $names);
    }

    public function test_maestro_touching_task_includes_worker_floor_replay_steps(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/SelfConstruction/Maestro/Health/AtlasMaestroReplenishUrgencyClassifier.php'],
            'packet_facts' => ['declared_gates' => []],
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('worker_floor_check', $names);
    }

    public function test_replenisher_touching_task_includes_worker_floor_replay_steps(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionNativeReplenisherPreflight.php'],
            'packet_facts' => ['declared_gates' => []],
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('worker_floor_check', $names);
    }

    public function test_autonomous_completion_touching_task_includes_worker_floor_replay_steps(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/SelfConstruction/Completion/AtlasSelfConstructionFinalAutonomyVerdict.php'],
            'packet_facts' => ['declared_gates' => []],
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('worker_floor_check', $names);
    }

    // ── AC: high-risk quorum + freshness commands ────────────────────────────

    public function test_high_risk_change_includes_receipt_quorum_and_freshness_replay_alongside_false_green_guard(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'risk_level' => 'high',
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('false_green_guard', $names);
        $this->assertContains('receipt_quorum_check', $names);
        $this->assertContains('freshness_replay_check', $names);
    }

    public function test_broad_scope_also_includes_receipt_quorum_and_freshness_replay(): void
    {
        $manyFiles = array_map(static fn (int $i): string => "app/Services/File{$i}.php", range(1, AtlasVerificationCourtGateReplayPlan::BROAD_SCOPE_THRESHOLD));
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => $manyFiles,
            'risk_level' => 'low',
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('receipt_quorum_check', $names);
        $this->assertContains('freshness_replay_check', $names);
    }

    public function test_low_risk_narrow_scope_excludes_quorum_and_freshness_commands(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'risk_level' => 'low',
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertNotContains('receipt_quorum_check', $names);
        $this->assertNotContains('freshness_replay_check', $names);
    }

    // ── AC: lane-evidence-isolation alongside lane-freshness ─────────────────

    public function test_project_lane_change_includes_lane_evidence_isolation_check(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Foo.php'],
            'project_lane' => ['project_id' => 'other-project'],
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertContains('lane_freshness_check', $names);
        $this->assertContains('lane_evidence_isolation_check', $names);
    }

    public function test_no_project_lane_excludes_lane_evidence_isolation_check(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Foo.php'],
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertNotContains('lane_evidence_isolation_check', $names);
    }

    // ── AC: all-vague declared gates block unless a concrete gate binds ──────

    public function test_all_vague_declared_gates_with_changed_files_blocks_the_plan(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Demo/Foo.php'],
            'risk_level' => 'low',
            'packet_facts' => ['declared_gates' => ['pint', 'static_analysis']],
        ]);

        $this->assertSame(AtlasVerificationCourtGateReplayPlan::STATUS_BLOCKED, $r['plan_status']);
        $this->assertContains('vague_declared_gates_without_concrete_replay_binding', $r['blockers']);
    }

    public function test_one_concrete_declared_gate_among_vague_ones_keeps_the_plan_ready(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Demo/Foo.php'],
            'risk_level' => 'low',
            'packet_facts' => ['declared_gates' => ['pint', 'php artisan test tests/Unit/Demo/FooTest.php']],
        ]);

        $this->assertSame(AtlasVerificationCourtGateReplayPlan::STATUS_READY, $r['plan_status']);
        $this->assertNotContains('vague_declared_gates_without_concrete_replay_binding', $r['blockers']);
    }

    public function test_vague_declared_gates_with_no_changed_files_does_not_block(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => [],
            'packet_facts' => ['declared_gates' => ['pint']],
        ]);

        $this->assertNotContains('vague_declared_gates_without_concrete_replay_binding', $r['blockers']);
    }

    // ── AC: deterministic ids + evidence_hash for new commands ───────────────

    public function test_new_commands_carry_deterministic_ids_and_evidence_hash(): void
    {
        $facts = [
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'risk_level' => 'high',
            'project_lane' => ['project_id' => 'proj-a'],
        ];
        $p = new AtlasVerificationCourtGateReplayPlan;
        $a = $p->derive($facts);
        $b = $p->derive($facts);
        $this->assertSame($a, $b, 'identical input must yield byte-identical output');

        $byName = [];
        foreach ($a['commands'] as $cmd) {
            $byName[$cmd['name']] = $cmd;
        }
        foreach (['receipt_quorum_check', 'freshness_replay_check', 'lane_evidence_isolation_check'] as $name) {
            $this->assertArrayHasKey($name, $byName);
            $this->assertNotEmpty($byName[$name]['id']);
            $this->assertNotEmpty($byName[$name]['evidence_hash']);
        }
    }

    public function test_unrelated_task_stays_minimal_without_worker_floor_steps(): void
    {
        $r = (new AtlasVerificationCourtGateReplayPlan)->derive([
            'evidence_contract_result' => ['accepted' => true],
            'changed_files' => ['app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskPacketQualityInspector.php'],
            'packet_facts' => ['declared_gates' => []],
        ]);

        $names = array_column($r['commands'], 'name');
        $this->assertNotContains('worker_floor_check', $names);
        $this->assertNotContains('worker_floor_queue_health_check', $names);
    }
}
