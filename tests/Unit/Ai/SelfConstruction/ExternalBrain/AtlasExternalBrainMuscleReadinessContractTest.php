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
            'task_packet_id'      => 'task-01',
            'objective'           => 'Implement AtlasDriftDetector to detect interface contract drift at CI time',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php',
                'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php',
            ],
            'acceptance_criteria' => [
                'The runnable test proves AtlasDriftDetector::detect() returns drift entries',
            ],
            'required_evidence'   => ['tests_or_gates_result', 'implementation_notes'],
            'risk_level'          => 'low',
            'duplicate'           => false,
            'implemented_files'   => [],
            'active_claims'       => [],
            'give_back_on_blocked' => true,  // give_back_escape_hatch
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
        $this->assertSame('ready', $r['readiness_category']);
        $this->assertSame(0.0, $r['give_back_risk_score']);
        $this->assertSame([], $r['blocking_deficiencies']);
        $this->assertSame([], $r['repair_hints']);

        foreach ($r['checks'] as $check) {
            $this->assertTrue($check['passed'], "check {$check['check']} must pass for a ready spec");
        }
    }

    public function test_output_has_all_eleven_named_checks(): void
    {
        $r     = $this->contract->check($this->readySpec());
        $names = array_column($r['checks'], 'check');

        foreach ([
            'scoped_files', 'no_test_only_packet', 'implementation_plus_test_scope',
            'no_collision', 'runnable_proof', 'enough_context', 'concrete_symbol_presence',
            'acceptance_contradiction_risk', 'bounded_risk', 'clear_give_back_path',
            'give_back_escape_hatch', 'worker_capability_fit',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertCount(12, $r['checks']);
    }

    public function test_output_always_has_required_keys(): void
    {
        $r = $this->contract->check($this->readySpec());

        foreach (['schema', 'task_packet_id', 'ready', 'readiness_category', 'failure_category',
                  'give_back_risk_score', 'blocking_deficiencies', 'checks', 'repair_hints'] as $k) {
            $this->assertArrayHasKey($k, $r, "Missing key: {$k}");
        }
    }

    // ── blocking_deficiencies ─────────────────────────────────────────────────

    public function test_blocking_deficiencies_lists_failed_check_names(): void
    {
        $r = $this->contract->check($this->readySpec(['allowed_files' => []]));

        $this->assertContains('scoped_files', $r['blocking_deficiencies']);
    }

    public function test_blocking_deficiencies_empty_when_ready(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertSame([], $r['blocking_deficiencies']);
    }

    // ── readiness_category ────────────────────────────────────────────────────

    public function test_readiness_category_is_ready_when_all_pass(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertSame('ready', $r['readiness_category']);
    }

    public function test_readiness_category_test_only_packet_when_only_test_files(): void
    {
        $r = $this->contract->check($this->readySpec([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php'],
        ]));

        $this->assertSame('test_only_packet', $r['readiness_category']);
    }

    public function test_readiness_category_missing_evidence_when_required_evidence_empty(): void
    {
        $r = $this->contract->check($this->readySpec(['required_evidence' => []]));

        $this->assertSame('missing_evidence', $r['readiness_category']);
    }

    public function test_readiness_category_vague_objective_when_no_concrete_symbol(): void
    {
        $r = $this->contract->check($this->readySpec(['objective' => 'Improve performance of the overall system significantly']));

        $this->assertSame('vague_objective', $r['readiness_category']);
    }

    public function test_readiness_category_contradiction_risk_when_criteria_contradict(): void
    {
        $r = $this->contract->check($this->readySpec([
            'acceptance_criteria' => [
                // contains runnable signal ("test") + both positive ("must return") and negative ("must not return")
                'AtlasDriftDetector::detect() must return results and must not return null — test passes',
            ],
        ]));

        $this->assertSame('contradiction_risk', $r['readiness_category']);
    }

    public function test_readiness_category_no_escape_hatch_when_no_give_back_signal(): void
    {
        $r = $this->contract->check($this->readySpec([
            'give_back_on_blocked' => false,
            'give_back_condition'  => '',
            'max_attempts'         => 0,
        ]));

        $this->assertSame('no_escape_hatch', $r['readiness_category']);
    }

    public function test_readiness_category_already_implemented_when_duplicate(): void
    {
        $r = $this->contract->check($this->readySpec([
            'duplicate'    => true,
            'allowed_files' => [],
        ]));

        $this->assertSame('already_implemented', $r['readiness_category']);
    }

    public function test_readiness_category_operator_gate_required_when_critical(): void
    {
        $r = $this->contract->check($this->readySpec(['risk_level' => 'critical']));

        $this->assertSame('operator_gate_required', $r['readiness_category']);
    }

    // ── give_back_risk_score ──────────────────────────────────────────────────

    public function test_give_back_risk_score_zero_when_all_pass(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertSame(0.0, $r['give_back_risk_score']);
    }

    public function test_give_back_risk_score_increases_with_failures(): void
    {
        $base   = $this->contract->check($this->readySpec());
        $failed = $this->contract->check($this->readySpec(['required_evidence' => []]));

        $this->assertGreaterThan($base['give_back_risk_score'], $failed['give_back_risk_score']);
    }

    public function test_give_back_risk_score_capped_at_one(): void
    {
        $r = $this->contract->check($this->readySpec([
            'allowed_files'       => ['tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php'],  // test-only
            'required_evidence'   => [],
            'acceptance_criteria' => [],
            'give_back_on_blocked' => false,
            'objective'           => 'Improve everything quickly',  // no concrete symbol + short? no, 30 chars but vague
        ]));

        $this->assertLessThanOrEqual(1.0, $r['give_back_risk_score']);
        $this->assertGreaterThan(0.0, $r['give_back_risk_score']);
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
        $r     = $this->contract->check($this->readySpec(['allowed_files' => ['app/Services/*.php']]));
        $check = $this->findCheck($r, 'scoped_files');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('wildcard', $check['reason']);
    }

    public function test_fails_scoped_files_for_bare_directory(): void
    {
        $r     = $this->contract->check($this->readySpec(['allowed_files' => ['app/Services/Ai']]));
        $check = $this->findCheck($r, 'scoped_files');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('bare_directory', $check['reason']);
    }

    // ── no_test_only_packet check ─────────────────────────────────────────────

    public function test_fails_no_test_only_packet_when_all_files_are_in_tests_dir(): void
    {
        $r     = $this->contract->check($this->readySpec([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php'],
        ]));
        $check = $this->findCheck($r, 'no_test_only_packet');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('test_files', $check['reason']);
        $this->assertFalse($r['ready']);
    }

    public function test_passes_no_test_only_packet_when_impl_file_present(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertTrue($this->findCheck($r, 'no_test_only_packet')['passed']);
    }

    public function test_passes_no_test_only_packet_when_only_impl_files(): void
    {
        $r = $this->contract->check($this->readySpec([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasDriftDetector.php'],
        ]));
        $this->assertTrue($this->findCheck($r, 'no_test_only_packet')['passed']);
    }

    // ── implementation_plus_test_scope check ──────────────────────────────────

    public function test_fails_implementation_plus_test_scope_when_no_test_file(): void
    {
        $r     = $this->contract->check($this->readySpec([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasDriftDetector.php'],
        ]));
        $check = $this->findCheck($r, 'implementation_plus_test_scope');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('no_test_file', $check['reason']);
    }

    public function test_fails_implementation_plus_test_scope_when_no_impl_file(): void
    {
        $r     = $this->contract->check($this->readySpec([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php'],
        ]));
        $check = $this->findCheck($r, 'implementation_plus_test_scope');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('no_implementation_file', $check['reason']);
    }

    public function test_passes_implementation_plus_test_scope_when_both_present(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertTrue($this->findCheck($r, 'implementation_plus_test_scope')['passed']);
    }

    // ── no_collision check ────────────────────────────────────────────────────

    public function test_fails_no_collision_when_file_claimed_by_other_worker(): void
    {
        $sharedFile = 'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php';
        $r          = $this->contract->check($this->readySpec([
            'allowed_files'  => [$sharedFile, 'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php'],
            'active_claims'  => [['worker_id' => 'codex-1', 'claimed_files' => [$sharedFile]]],
        ]));

        $check = $this->findCheck($r, 'no_collision');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('codex-1', $check['reason']);
        $this->assertFalse($r['ready']);
    }

    public function test_passes_no_collision_when_claimed_files_do_not_overlap(): void
    {
        $r = $this->contract->check($this->readySpec([
            'active_claims' => [['worker_id' => 'codex-1', 'claimed_files' => ['app/Services/Ai/AtlasBar.php']]],
        ]));

        $this->assertTrue($this->findCheck($r, 'no_collision')['passed']);
    }

    // ── runnable_proof check ──────────────────────────────────────────────────

    public function test_fails_runnable_proof_when_criteria_empty(): void
    {
        $r     = $this->contract->check($this->readySpec(['acceptance_criteria' => []]));
        $check = $this->findCheck($r, 'runnable_proof');
        $this->assertFalse($check['passed']);
    }

    public function test_fails_runnable_proof_when_criteria_lack_test_signal(): void
    {
        $r     = $this->contract->check($this->readySpec([
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
        $r     = $this->contract->check($this->readySpec(['objective' => 'Fix the bug']));
        $check = $this->findCheck($r, 'enough_context');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('too_short', $check['reason']);
    }

    public function test_fails_enough_context_when_objective_is_vague(): void
    {
        $r     = $this->contract->check($this->readySpec([
            'objective' => 'We should make it better and clean up the existing code significantly',
        ]));
        $check = $this->findCheck($r, 'enough_context');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('vague', $check['reason']);
    }

    // ── concrete_symbol_presence check ────────────────────────────────────────

    public function test_fails_concrete_symbol_presence_when_objective_is_generic(): void
    {
        $r     = $this->contract->check($this->readySpec([
            'objective' => 'Improve performance of the overall system to be faster',
        ]));
        $check = $this->findCheck($r, 'concrete_symbol_presence');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('no_concrete_code_symbol', $check['reason']);
    }

    public function test_passes_concrete_symbol_presence_for_pascal_case_class(): void
    {
        $r = $this->contract->check($this->readySpec([
            'objective' => 'Implement AtlasDriftDetector to detect contract drift',
        ]));
        $this->assertTrue($this->findCheck($r, 'concrete_symbol_presence')['passed']);
    }

    public function test_passes_concrete_symbol_presence_for_double_colon_reference(): void
    {
        $r = $this->contract->check($this->readySpec([
            'objective' => 'Make AtlasBrain::score() return a weighted float for comprehension depth',
        ]));
        $this->assertTrue($this->findCheck($r, 'concrete_symbol_presence')['passed']);
    }

    public function test_passes_concrete_symbol_presence_for_artisan_command(): void
    {
        $r = $this->contract->check($this->readySpec([
            'objective' => 'Wire the atlas:brain:next artisan command to the comprehension loop',
        ]));
        $this->assertTrue($this->findCheck($r, 'concrete_symbol_presence')['passed']);
    }

    // ── acceptance_contradiction_risk check ───────────────────────────────────

    public function test_fails_acceptance_contradiction_risk_when_criterion_has_both_positive_and_negative(): void
    {
        $r     = $this->contract->check($this->readySpec([
            'acceptance_criteria' => [
                'AtlasDriftDetector::detect() must return a list and must not return an empty list always',
            ],
        ]));
        $check = $this->findCheck($r, 'acceptance_contradiction_risk');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('positive_and_negative_assertion', $check['reason']);
    }

    public function test_passes_acceptance_contradiction_risk_for_clean_criteria(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertTrue($this->findCheck($r, 'acceptance_contradiction_risk')['passed']);
    }

    public function test_passes_acceptance_contradiction_risk_when_negation_only(): void
    {
        $r = $this->contract->check($this->readySpec([
            'acceptance_criteria' => [
                'AtlasDriftDetector::detect() must not throw on empty input',
            ],
        ]));
        // Only a negation, no positive marker — not a contradiction.
        $this->assertTrue($this->findCheck($r, 'acceptance_contradiction_risk')['passed']);
    }

    // ── bounded_risk check ────────────────────────────────────────────────────

    public function test_fails_bounded_risk_for_critical_risk_level(): void
    {
        $r     = $this->contract->check($this->readySpec(['risk_level' => 'critical']));
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
        $r     = $this->contract->check($this->readySpec(['required_evidence' => []]));
        $check = $this->findCheck($r, 'clear_give_back_path');
        $this->assertFalse($check['passed']);
        $this->assertFalse($r['ready']);
    }

    // ── give_back_escape_hatch check ──────────────────────────────────────────

    public function test_fails_give_back_escape_hatch_when_no_stopping_criterion(): void
    {
        $r     = $this->contract->check($this->readySpec([
            'give_back_on_blocked' => false,
            'give_back_condition'  => '',
            'max_attempts'         => 0,
        ]));
        $check = $this->findCheck($r, 'give_back_escape_hatch');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('no_give_back_stopping_criterion', $check['reason']);
    }

    public function test_passes_give_back_escape_hatch_when_give_back_on_blocked_true(): void
    {
        $r = $this->contract->check($this->readySpec(['give_back_on_blocked' => true]));
        $this->assertTrue($this->findCheck($r, 'give_back_escape_hatch')['passed']);
    }

    public function test_passes_give_back_escape_hatch_when_give_back_condition_set(): void
    {
        $r = $this->contract->check($this->readySpec([
            'give_back_on_blocked' => false,
            'give_back_condition'  => 'Give back if tests are still red after 3 attempts',
        ]));
        $this->assertTrue($this->findCheck($r, 'give_back_escape_hatch')['passed']);
    }

    public function test_passes_give_back_escape_hatch_when_max_attempts_set(): void
    {
        $r = $this->contract->check($this->readySpec([
            'give_back_on_blocked' => false,
            'give_back_condition'  => '',
            'max_attempts'         => 3,
        ]));
        $this->assertTrue($this->findCheck($r, 'give_back_escape_hatch')['passed']);
    }

    // ── worker_capability_fit check ───────────────────────────────────────────

    public function test_passes_worker_capability_fit_when_no_capabilities_supplied(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertTrue($this->findCheck($r, 'worker_capability_fit')['passed']);
    }

    public function test_fails_worker_capability_fit_when_no_capability_matches_task_family_risk_or_tier(): void
    {
        $r = $this->contract->check($this->readySpec([
            'task_family'         => 'drift_detection',
            'model_tier'          => 'heavy',
            'worker_capabilities' => [
                ['task_families' => ['copywriting'], 'risk_levels' => ['low'], 'model_tiers' => ['light']],
            ],
        ]));

        $check = $this->findCheck($r, 'worker_capability_fit');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('no_worker_capability_matches', $check['reason']);
        $this->assertFalse($r['ready']);
    }

    public function test_passes_worker_capability_fit_when_a_capability_matches(): void
    {
        $r = $this->contract->check($this->readySpec([
            'task_family'         => 'drift_detection',
            'model_tier'          => 'heavy',
            'worker_capabilities' => [
                ['task_families' => ['copywriting'], 'risk_levels' => ['low'], 'model_tiers' => ['light']],
                ['task_families' => ['drift_detection'], 'risk_levels' => ['low'], 'model_tiers' => ['heavy']],
            ],
        ]));

        $this->assertTrue($this->findCheck($r, 'worker_capability_fit')['passed']);
        $this->assertTrue($r['ready']);
    }

    // ── failure category classification ───────────────────────────────────────

    public function test_already_implemented_when_duplicate_flag_set(): void
    {
        $r = $this->contract->check($this->readySpec([
            'duplicate'     => true,
            'allowed_files' => [],
        ]));
        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_ALREADY_IMPLEMENTED, $r['failure_category']);
    }

    public function test_already_implemented_when_target_file_in_implemented_set(): void
    {
        $file = 'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php';
        $r    = $this->contract->check($this->readySpec([
            'allowed_files'       => [$file, 'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php'],
            'acceptance_criteria' => [],
            'implemented_files'   => [$file],
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
        $r = $this->contract->check($this->readySpec(['required_evidence' => []]));
        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_REPAIRABLE, $r['failure_category']);
    }

    // ── worker_readiness ──────────────────────────────────────────────────────

    private function workerReadyFacts(): array
    {
        return [
            'scope_discipline_facts'  => ['out_of_scope_incidents' => 0, 'total_tasks' => 10],
            'runnable_proof_facts'    => ['can_run_phpunit' => true, 'can_run_artisan' => true],
            'give_back_hygiene_facts' => ['give_back_rate' => 0.1],
            'current_load_facts'      => ['active_tasks' => 1, 'capacity' => 5],
        ];
    }

    public function test_worker_readiness_key_exists_in_output(): void
    {
        $r = $this->contract->check($this->readySpec());
        $this->assertArrayHasKey('worker_readiness', $r);
        $this->assertArrayHasKey('verdict', $r['worker_readiness']);
    }

    public function test_worker_readiness_verdict_ready_when_all_facts_good(): void
    {
        $r = $this->contract->check($this->readySpec($this->workerReadyFacts()));
        $this->assertSame('ready', $r['worker_readiness']['verdict']);
        $this->assertTrue($r['worker_readiness']['scope_discipline']['ready']);
        $this->assertTrue($r['worker_readiness']['runnable_proof_support']['ready']);
        $this->assertTrue($r['worker_readiness']['give_back_hygiene']['ready']);
        $this->assertTrue($r['worker_readiness']['current_load']['ready']);
    }

    public function test_missing_scope_discipline_facts_yields_deficiency(): void
    {
        $r  = $this->contract->check($this->readySpec());
        $wr = $r['worker_readiness'];
        $this->assertFalse($wr['scope_discipline']['ready']);
        $this->assertContains('scope_discipline_evidence_missing', $wr['scope_discipline']['deficiency_codes']);
        $this->assertSame('not_ready', $wr['verdict']);
    }

    public function test_high_give_back_rate_yields_hygiene_deficiency(): void
    {
        $facts                                     = $this->workerReadyFacts();
        $facts['give_back_hygiene_facts']['give_back_rate'] = 0.6;
        $r  = $this->contract->check($this->readySpec($facts));
        $wr = $r['worker_readiness'];
        $this->assertFalse($wr['give_back_hygiene']['ready']);
        $this->assertContains('give_back_hygiene_rate_too_high', $wr['give_back_hygiene']['deficiency_codes']);
        $this->assertSame('not_ready', $wr['verdict']);
    }

    public function test_worker_at_capacity_yields_load_deficiency(): void
    {
        $facts                          = $this->workerReadyFacts();
        $facts['current_load_facts']    = ['active_tasks' => 5, 'capacity' => 5];
        $r  = $this->contract->check($this->readySpec($facts));
        $wr = $r['worker_readiness'];
        $this->assertFalse($wr['current_load']['ready']);
        $this->assertContains('current_load_worker_at_or_over_capacity', $wr['current_load']['deficiency_codes']);
    }

    public function test_no_test_runner_available_yields_runnable_proof_deficiency(): void
    {
        $facts                          = $this->workerReadyFacts();
        $facts['runnable_proof_facts']  = ['can_run_phpunit' => false, 'can_run_artisan' => false];
        $r  = $this->contract->check($this->readySpec($facts));
        $wr = $r['worker_readiness'];
        $this->assertFalse($wr['runnable_proof_support']['ready']);
        $this->assertContains('runnable_proof_support_no_test_runner_available', $wr['runnable_proof_support']['deficiency_codes']);
    }

    public function test_repair_hints_aggregate_from_all_failed_checks(): void
    {
        $r = $this->contract->check($this->readySpec([
            'allowed_files'       => [],
            'acceptance_criteria' => [],
        ]));

        $this->assertGreaterThan(1, count($r['repair_hints']));
        foreach ($r['repair_hints'] as $hint) {
            $this->assertIsString($hint);
            $this->assertNotEmpty($hint);
        }
    }

    // ── AC3: five canonical examples ──────────────────────────────────────────

    public function test_ac3_ready_example_is_promoted(): void
    {
        $r = $this->contract->check([
            'task_packet_id'      => 'ac3-ready',
            'objective'           => 'Implement AtlasCapabilityGapScorer to score the gap between current and target atlas capability',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/Brain/AtlasCapabilityGapScorer.php',
                'tests/Unit/Ai/SelfConstruction/Brain/AtlasCapabilityGapScorerTest.php',
            ],
            'acceptance_criteria' => [
                'AtlasCapabilityGapScorer::score() returns a float between 0 and 1 — test passes green',
            ],
            'required_evidence'   => ['tests_or_gates_result', 'implementation_notes'],
            'risk_level'          => 'low',
            'give_back_on_blocked' => true,
        ]);

        $this->assertTrue($r['ready']);
        $this->assertSame('ready', $r['readiness_category']);
        $this->assertSame(0.0, $r['give_back_risk_score']);
    }

    public function test_ac3_test_only_example_is_rejected(): void
    {
        $r = $this->contract->check([
            'task_packet_id'      => 'ac3-test-only',
            'objective'           => 'Add assertions to AtlasDriftDetectorTest to cover edge cases',
            'allowed_files'       => [
                'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php',
            ],
            'acceptance_criteria' => ['The test suite passes green'],
            'required_evidence'   => ['tests_or_gates_result'],
            'risk_level'          => 'low',
            'give_back_on_blocked' => true,
        ]);

        $this->assertFalse($r['ready']);
        $this->assertSame('test_only_packet', $r['readiness_category']);
        $this->assertContains('no_test_only_packet', $r['blocking_deficiencies']);
    }

    public function test_ac3_vague_example_is_rejected(): void
    {
        $r = $this->contract->check([
            'task_packet_id'      => 'ac3-vague',
            'objective'           => 'Improve performance across the entire system broadly',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php',
                'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php',
            ],
            'acceptance_criteria' => ['Something should be better overall'],
            'required_evidence'   => ['tests_or_gates_result'],
            'risk_level'          => 'low',
            'give_back_on_blocked' => true,
        ]);

        $this->assertFalse($r['ready']);
        // Either vague_objective (concrete_symbol) or missing_runnable_proof — both are valid.
        $this->assertNotSame('ready', $r['readiness_category']);
        $this->assertNotEmpty($r['blocking_deficiencies']);
    }

    public function test_ac3_contradiction_risk_example_is_rejected(): void
    {
        $r = $this->contract->check([
            'task_packet_id'      => 'ac3-contradiction',
            'objective'           => 'Implement AtlasDriftDetector to flag contract drift in CI',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php',
                'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php',
            ],
            'acceptance_criteria' => [
                // runnable signal ("test") + positive ("must return") + negative ("must not return") = contradiction
                'AtlasDriftDetector must return drift entries for all violations and must not return any entries always — test passes',
            ],
            'required_evidence'   => ['tests_or_gates_result'],
            'risk_level'          => 'low',
            'give_back_on_blocked' => true,
        ]);

        $this->assertFalse($r['ready']);
        $this->assertSame('contradiction_risk', $r['readiness_category']);
        $this->assertContains('acceptance_contradiction_risk', $r['blocking_deficiencies']);
    }

    public function test_ac3_missing_evidence_example_is_rejected(): void
    {
        $r = $this->contract->check([
            'task_packet_id'      => 'ac3-missing-evidence',
            'objective'           => 'Implement AtlasDriftDetector to detect CI contract drift',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/AtlasDriftDetector.php',
                'tests/Unit/Ai/SelfConstruction/AtlasDriftDetectorTest.php',
            ],
            'acceptance_criteria' => ['AtlasDriftDetector::detect() returns entries — test passes'],
            'required_evidence'   => [],  // missing!
            'risk_level'          => 'low',
            'give_back_on_blocked' => true,
        ]);

        $this->assertFalse($r['ready']);
        $this->assertSame('missing_evidence', $r['readiness_category']);
        $this->assertContains('clear_give_back_path', $r['blocking_deficiencies']);
        $this->assertGreaterThan(0.0, $r['give_back_risk_score']);
    }
}
