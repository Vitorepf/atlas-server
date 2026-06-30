<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleReadinessContract;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMuscleReadinessContractTest extends TestCase
{
    private AtlasExternalBrainMuscleReadinessContract $contract;

    protected function setUp(): void
    {
        $this->contract = new AtlasExternalBrainMuscleReadinessContract;
    }

    private function readySpec(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-01',
            'objective' => 'Implement AtlasDriftDetector to detect interface contract drift at CI time',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php',
                'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php',
            ],
            'acceptance_criteria' => [
                'The runnable test proves AtlasDriftDetector::detect() returns drift entries',
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'risk_level' => 'low',
            'duplicate' => false,
            'implemented_files' => [],
            'active_claims' => [],
        ], $overrides);
    }

    private function findCheck(array $result, string $name): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['check'] === $name) {
                return $check;
            }
        }
        $this->fail("Check '{$name}' not found in result");
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_ready_spec_passes_all_checks_and_returns_null_category(): void
    {
        $r = $this->contract->check($this->readySpec());

        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::SCHEMA, $r['schema']);
        $this->assertTrue($r['ready']);
        $this->assertNull($r['failure_category']);
        $this->assertSame([], $r['repair_hints']);

        foreach ($r['checks'] as $check) {
            $this->assertTrue($check['passed'], "check {$check['check']} must pass for a ready spec");
        }
    }

    public function test_output_always_has_six_named_checks(): void
    {
        $r = $this->contract->check($this->readySpec());
        $names = array_column($r['checks'], 'check');

        foreach (['scoped_files', 'no_collision', 'runnable_proof', 'enough_context',
                  'bounded_risk', 'clear_give_back_path'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    // ── scoped_files check ────────────────────────────────────────────────────

    public function test_fails_scoped_files_when_allowed_files_empty(): void
    {
        $r = $this->contract->check($this->readySpec(['allowed_files' => []]));

        $check = $this->findCheck($r, 'scoped_files');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('empty', $check['reason']);
        $this->assertFalse($r['ready']);
    }

    public function test_fails_scoped_files_for_wildcard(): void
    {
        $r = $this->contract->check($this->readySpec(['allowed_files' => ['app/Services/*.php']]));

        $check = $this->findCheck($r, 'scoped_files');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('wildcard', $check['reason']);
    }

    public function test_fails_scoped_files_for_bare_directory(): void
    {
        $r = $this->contract->check($this->readySpec(['allowed_files' => ['app/Services/Ai']]));

        $check = $this->findCheck($r, 'scoped_files');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('bare_directory', $check['reason']);
    }

    // ── no_collision check ────────────────────────────────────────────────────

    public function test_fails_no_collision_when_file_claimed_by_other_worker(): void
    {
        $sharedFile = 'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php';
        $r = $this->contract->check($this->readySpec([
            'allowed_files' => [$sharedFile],
            'active_claims' => [['worker_id' => 'codex-1', 'claimed_files' => [$sharedFile]]],
        ]));

        $check = $this->findCheck($r, 'no_collision');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('codex-1', $check['reason']);
        $this->assertFalse($r['ready']);
    }

    public function test_passes_no_collision_when_claimed_files_do_not_overlap(): void
    {
        $r = $this->contract->check($this->readySpec([
            'allowed_files' => ['app/Services/Ai/AtlasFoo.php'],
            'active_claims' => [['worker_id' => 'codex-1', 'claimed_files' => ['app/Services/Ai/AtlasBar.php']]],
        ]));

        $this->assertTrue($this->findCheck($r, 'no_collision')['passed']);
    }

    // ── runnable_proof check ──────────────────────────────────────────────────

    public function test_fails_runnable_proof_when_criteria_empty(): void
    {
        $r = $this->contract->check($this->readySpec(['acceptance_criteria' => []]));

        $check = $this->findCheck($r, 'runnable_proof');
        $this->assertFalse($check['passed']);
    }

    public function test_fails_runnable_proof_when_criteria_lack_test_signal(): void
    {
        $r = $this->contract->check($this->readySpec([
            'acceptance_criteria' => ['The feature should make Atlas better overall'],
        ]));

        $check = $this->findCheck($r, 'runnable_proof');
        $this->assertFalse($check['passed']);
        $this->assertNotEmpty($r['repair_hints']);
    }

    public function test_passes_runnable_proof_with_artisan_in_criterion(): void
    {
        $r = $this->contract->check($this->readySpec([
            'acceptance_criteria' => ['php artisan atlas:ci:check exits 0 when clean'],
        ]));

        $this->assertTrue($this->findCheck($r, 'runnable_proof')['passed']);
    }

    // ── enough_context check ──────────────────────────────────────────────────

    public function test_fails_enough_context_when_objective_too_short(): void
    {
        $r = $this->contract->check($this->readySpec(['objective' => 'Fix the bug']));

        $check = $this->findCheck($r, 'enough_context');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('too_short', $check['reason']);
    }

    public function test_fails_enough_context_when_objective_is_vague(): void
    {
        $r = $this->contract->check($this->readySpec([
            'objective' => 'We should make it better and clean up the existing code significantly',
        ]));

        $check = $this->findCheck($r, 'enough_context');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('vague', $check['reason']);
    }

    // ── bounded_risk check ────────────────────────────────────────────────────

    public function test_fails_bounded_risk_for_critical_risk_level(): void
    {
        $r = $this->contract->check($this->readySpec(['risk_level' => 'critical']));

        $check = $this->findCheck($r, 'bounded_risk');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('critical', $check['reason']);
    }

    public function test_passes_bounded_risk_for_medium_risk(): void
    {
        $r = $this->contract->check($this->readySpec(['risk_level' => 'medium']));
        $this->assertTrue($this->findCheck($r, 'bounded_risk')['passed']);
    }

    // ── clear_give_back_path check ────────────────────────────────────────────

    public function test_fails_clear_give_back_path_when_evidence_empty(): void
    {
        $r = $this->contract->check($this->readySpec(['required_evidence' => []]));

        $check = $this->findCheck($r, 'clear_give_back_path');
        $this->assertFalse($check['passed']);
        $this->assertFalse($r['ready']);
    }

    // ── failure category classification ───────────────────────────────────────

    public function test_already_implemented_when_duplicate_flag_set(): void
    {
        $r = $this->contract->check($this->readySpec([
            'duplicate' => true,
            'allowed_files' => [],  // also broken, but duplicate wins
        ]));

        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_ALREADY_IMPLEMENTED, $r['failure_category']);
    }

    public function test_already_implemented_when_target_file_in_implemented_set(): void
    {
        $file = 'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php';
        $r = $this->contract->check($this->readySpec([
            'allowed_files' => [$file],
            'acceptance_criteria' => [],  // force failure
            'implemented_files' => [$file],
        ]));

        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_ALREADY_IMPLEMENTED, $r['failure_category']);
    }

    public function test_operator_only_when_risk_level_is_critical(): void
    {
        $r = $this->contract->check($this->readySpec(['risk_level' => 'critical']));

        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_OPERATOR_ONLY, $r['failure_category']);
    }

    public function test_operator_only_when_objective_requires_operator(): void
    {
        $r = $this->contract->check($this->readySpec([
            'objective' => 'Implement drift detection but requires operator approval before merging into prod',
        ]));

        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_OPERATOR_ONLY, $r['failure_category']);
    }

    public function test_repairable_spec_defect_for_fixable_issues(): void
    {
        $r = $this->contract->check($this->readySpec(['allowed_files' => []]));

        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_REPAIRABLE, $r['failure_category']);
    }

    public function test_repair_hints_aggregate_from_all_failed_checks(): void
    {
        $r = $this->contract->check($this->readySpec([
            'allowed_files' => [],
            'acceptance_criteria' => [],
        ]));

        $this->assertGreaterThan(1, count($r['repair_hints']));
        foreach ($r['repair_hints'] as $hint) {
            $this->assertIsString($hint);
            $this->assertNotEmpty($hint);
        }
    }
}
