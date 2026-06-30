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
}
