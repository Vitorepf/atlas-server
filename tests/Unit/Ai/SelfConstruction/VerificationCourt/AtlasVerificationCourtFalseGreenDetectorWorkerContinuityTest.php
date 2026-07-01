<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use PHPUnit\Framework\TestCase;

/**
 * Pins the worker-continuity claim gate: a task claiming queue-continuity improvement
 * (claimed_worker_continuity=true) must include a passed replay outcome whose name covers
 * claimable_per_active_worker or no_claimable_task — otherwise the verdict must not be passed.
 */
final class AtlasVerificationCourtFalseGreenDetectorWorkerContinuityTest extends TestCase
{
    private function basePlan(): array
    {
        return [
            'plan_status' => 'ready',
            'blockers' => [],
            'commands' => [
                ['id' => 'cmd-abc', 'name' => 'phpunit_scoped'],
            ],
        ];
    }

    public function test_claimed_worker_continuity_without_matching_replay_is_non_passed_with_reason(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'name' => 'phpunit_scoped', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Demo/Foo.php'],
            'allowed_files' => ['app/Demo/Foo.php'],
            'claimed_worker_continuity' => true,
        ]);

        $this->assertNotSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
        $this->assertContains('worker_continuity_replay_missing', $r['reasons']);
    }

    public function test_matching_replay_output_with_clean_scope_and_ready_plan_passes(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => [
                'plan_status' => 'ready',
                'blockers' => [],
                'commands' => [
                    ['id' => 'cmd-abc', 'name' => 'phpunit_scoped'],
                    ['id' => 'cmd-wc', 'name' => 'claimable_per_active_worker_replay'],
                ],
            ],
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'name' => 'phpunit_scoped', 'passed' => true, 'output_present' => true],
                ['command_id' => 'cmd-wc', 'name' => 'claimable_per_active_worker_replay', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Demo/Foo.php'],
            'allowed_files' => ['app/Demo/Foo.php'],
            'claimed_worker_continuity' => true,
        ]);

        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
        $this->assertSame([], $r['reasons']);
    }

    public function test_no_claimable_task_topic_also_satisfies_the_continuity_claim(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => [
                'plan_status' => 'ready',
                'blockers' => [],
                'commands' => [
                    ['id' => 'cmd-nc', 'name' => 'no_claimable_task_recovery_replay'],
                ],
            ],
            'replay_outcomes' => [
                ['command_id' => 'cmd-nc', 'name' => 'no_claimable_task_recovery_replay', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => [],
            'allowed_files' => [],
            'claimed_worker_continuity' => true,
        ]);

        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
    }

    public function test_matching_replay_outcome_that_failed_does_not_satisfy_the_claim(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => [
                'plan_status' => 'ready',
                'blockers' => [],
                'commands' => [
                    ['id' => 'cmd-wc', 'name' => 'claimable_per_active_worker_replay'],
                ],
            ],
            'replay_outcomes' => [
                ['command_id' => 'cmd-wc', 'name' => 'claimable_per_active_worker_replay', 'passed' => false, 'output_present' => true],
            ],
            'changed_files' => [],
            'allowed_files' => [],
            'claimed_worker_continuity' => true,
        ]);

        $this->assertNotSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
        $this->assertContains('worker_continuity_replay_missing', $r['reasons']);
    }

    public function test_claim_false_does_not_require_worker_continuity_replay(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'name' => 'phpunit_scoped', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Demo/Foo.php'],
            'allowed_files' => ['app/Demo/Foo.php'],
            'claimed_worker_continuity' => false,
        ]);

        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
    }

    public function test_low_worker_floor_flags_false_green_worker_starvation(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'name' => 'phpunit_scoped', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Demo/Foo.php'],
            'allowed_files' => ['app/Demo/Foo.php'],
            'worker_floor_facts' => [
                'active_worker_count' => 6,
                'claimable_per_active_worker' => 1.0,
                'floor' => 2.0,
            ],
        ]);

        $this->assertNotSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
        $this->assertContains('false_green_worker_starvation', $r['reasons']);
        $this->assertSame('worker_starvation', $r['repair_feedback']['false_green_family']);
    }

    public function test_fresh_no_claimable_task_signal_resolved_by_repair_receipt_does_not_flag_starvation(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'name' => 'phpunit_scoped', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Demo/Foo.php'],
            'allowed_files' => ['app/Demo/Foo.php'],
            'no_claimable_task_signal_fresh' => true,
            'no_claimable_task_resolved_by_repair_receipt' => true,
        ]);

        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
        $this->assertNotContains('false_green_worker_starvation', $r['reasons']);
    }

    public function test_fresh_no_claimable_task_signal_without_repair_receipt_flags_starvation(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'name' => 'phpunit_scoped', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Demo/Foo.php'],
            'allowed_files' => ['app/Demo/Foo.php'],
            'no_claimable_task_signal_fresh' => true,
        ]);

        $this->assertNotSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
        $this->assertContains('false_green_worker_starvation', $r['reasons']);
    }

    public function test_repair_feedback_worker_continuity_family_present_when_missing(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'name' => 'phpunit_scoped', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => [],
            'allowed_files' => [],
            'claimed_worker_continuity' => true,
        ]);

        $this->assertSame('worker_continuity', $r['repair_feedback']['false_green_family']);
        $this->assertNotEmpty($r['repair_feedback']['repair_hint']);
    }
}
