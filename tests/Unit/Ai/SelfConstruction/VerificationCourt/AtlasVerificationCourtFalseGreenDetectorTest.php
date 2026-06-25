<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasVerificationCourtFalseGreenDetector: clean replay + scope ⇒ verdict=passed; a single red
 * replay outcome ⇒ verdict=failed with replay_red:<id>; missing replay outcome for a planned command ⇒
 * verdict=blocked with replay_missing_for:<id>; a changed file outside allowed_files ⇒ verdict=failed
 * with changed_file_outside_allowed:<path>; proxy_only_evidence=true ⇒ verdict=failed.
 */
final class AtlasVerificationCourtFalseGreenDetectorTest extends TestCase
{
    private function basePlan(): array
    {
        return [
            'plan_status' => 'ready',
            'blockers' => [],
            'commands' => [
                ['id' => 'cmd-abc', 'name' => 'phpunit_scoped'],
                ['id' => 'cmd-xyz', 'name' => 'docs_health_check'],
            ],
        ];
    }

    public function test_clean_pass_when_evidence_accepted_replay_green_and_scope_matches(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'passed' => true, 'output_present' => true],
                ['command_id' => 'cmd-xyz', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Demo/Foo.php'],
            'allowed_files' => ['app/Demo/Foo.php'],
        ]);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $r['verdict']);
        $this->assertSame([], $r['reasons']);
    }

    public function test_red_replay_outcome_yields_failed_with_replay_red_reason(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'passed' => false],
                ['command_id' => 'cmd-xyz', 'passed' => true],
            ],
            'changed_files' => ['app/Foo.php'],
            'allowed_files' => ['app/Foo.php'],
        ]);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED, $r['verdict']);
        $this->assertContains('replay_red:cmd-abc', $r['reasons']);
    }

    public function test_missing_replay_outcome_yields_blocked(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'passed' => true],
                // cmd-xyz missing
            ],
            'changed_files' => ['app/Foo.php'],
            'allowed_files' => ['app/Foo.php'],
        ]);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED, $r['verdict']);
        $this->assertContains('replay_missing_for:cmd-xyz', $r['reasons']);
    }

    public function test_changed_file_outside_allowed_yields_failed(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'passed' => true],
                ['command_id' => 'cmd-xyz', 'passed' => true],
            ],
            'changed_files' => ['app/Allowed.php', 'app/SECRET.php'],
            'allowed_files' => ['app/Allowed.php'],
        ]);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED, $r['verdict']);
        $this->assertContains('changed_file_outside_allowed:app/SECRET.php', $r['reasons']);
    }

    public function test_proxy_only_evidence_yields_failed(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'passed' => true],
                ['command_id' => 'cmd-xyz', 'passed' => true],
            ],
            'changed_files' => ['app/Foo.php'],
            'allowed_files' => ['app/Foo.php'],
            'proxy_only_evidence' => true,
        ]);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED, $r['verdict']);
        $this->assertContains('proxy_only_evidence', $r['reasons']);
    }

    public function test_evidence_not_accepted_yields_blocked(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => false],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [],
            'changed_files' => [],
            'allowed_files' => [],
        ]);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED, $r['verdict']);
        $this->assertContains('evidence_contract_not_accepted', $r['reasons']);
    }

    public function test_output_missing_for_claimed_test_yields_failed(): void
    {
        $r = (new AtlasVerificationCourtFalseGreenDetector)->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => $this->basePlan(),
            'replay_outcomes' => [
                ['command_id' => 'cmd-abc', 'passed' => true, 'output_present' => false],
                ['command_id' => 'cmd-xyz', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Foo.php'],
            'allowed_files' => ['app/Foo.php'],
        ]);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED, $r['verdict']);
        $this->assertContains('replay_output_missing:cmd-abc', $r['reasons']);
    }
}
