<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-adequacy suite for the sovereign floor — the dogfood's demand made concrete: the gate
 * holds the obra's OWN tests to a real mutation bar. Every invariant is exercised on BOTH branches
 * with its exact detail asserted (so string/return mutants die), and every threshold is pinned at
 * its exact boundary (so comparison-operator mutants die). Wiper-safe: pure logic, no DB.
 */
final class SovereignFloorMutationAdequacyTest extends TestCase
{
    private function floor(): SovereignHonestyFloor
    {
        return new SovereignHonestyFloor(configMutationFloor: 0.0, configContextFloor: 0);
    }

    /** Assert one invariant's status + exact detail for a given bundle. */
    private function invariant(array $overrides, string $id): array
    {
        $verdict = $this->floor()->certify(AcceptanceBundleFactory::honest($overrides), TrustLevel::Dev);

        return $verdict->invariants[$id];
    }

    // --- effective floors: exact clamp values (kills arithmetic/const mutants) ---

    public function test_effective_floors_are_the_exact_sovereign_pisos(): void
    {
        self::assertSame(0.6, (new SovereignHonestyFloor(configMutationFloor: 0.0))->effectiveMutationFloor());
        self::assertSame(0.6, (new SovereignHonestyFloor(configMutationFloor: 0.6))->effectiveMutationFloor());
        self::assertSame(0.61, (new SovereignHonestyFloor(configMutationFloor: 0.61))->effectiveMutationFloor());
        self::assertSame(80, (new SovereignHonestyFloor(configContextFloor: 0))->effectiveContextFloor());
        self::assertSame(80, (new SovereignHonestyFloor(configContextFloor: 80))->effectiveContextFloor());
        self::assertSame(81, (new SovereignHonestyFloor(configContextFloor: 81))->effectiveContextFloor());
    }

    // --- false_claim_blocked: every sub-condition, exact detail ---

    public function test_false_claim_pass_detail(): void
    {
        self::assertSame(
            ['status' => 'pass', 'detail' => 'real_execution_evidence_present'],
            $this->invariant([], 'false_claim_blocked'),
        );
    }

    public function test_false_claim_zero_tests(): void
    {
        $r = $this->invariant(['execution' => ['commands' => ['php artisan test x'], 'claimed_status' => 'passed', 'tests_run' => 0, 'assertions_executed' => 5, 'selected_tests' => ['x'], 'artifacts' => []]], 'false_claim_blocked');
        self::assertSame(['status' => 'fail', 'detail' => 'claimed_pass_with_zero_tests_run'], $r);
    }

    public function test_false_claim_zero_assertions(): void
    {
        $r = $this->invariant(['execution' => ['commands' => ['php artisan test x'], 'claimed_status' => 'passed', 'tests_run' => 3, 'assertions_executed' => 0, 'selected_tests' => ['x'], 'artifacts' => []]], 'false_claim_blocked');
        self::assertSame(['status' => 'fail', 'detail' => 'claimed_pass_with_zero_assertions'], $r);
    }

    public function test_false_claim_smoke_artifact(): void
    {
        $r = $this->invariant(['execution' => ['commands' => ['php artisan test x'], 'claimed_status' => 'passed', 'tests_run' => 3, 'assertions_executed' => 5, 'selected_tests' => ['x'], 'artifacts' => ['runtime/atlas_real_execution_smoke.php']]], 'false_claim_blocked');
        self::assertSame(['status' => 'fail', 'detail' => 'fixed_smoke_artifact_is_not_a_test_run'], $r);
    }

    public function test_false_claim_lint_only(): void
    {
        $r = $this->invariant(['execution' => ['commands' => ['php -l foo.php'], 'claimed_status' => 'passed', 'tests_run' => 2, 'assertions_executed' => 2, 'selected_tests' => [], 'artifacts' => []]], 'false_claim_blocked');
        self::assertSame(['status' => 'fail', 'detail' => 'lint_only_run_presented_as_suite'], $r);
    }

    public function test_false_claim_suite_without_runner(): void
    {
        $r = $this->invariant(['execution' => ['commands' => ['echo hi'], 'claimed_status' => 'passed', 'tests_run' => 2, 'assertions_executed' => 2, 'selected_tests' => ['some/Suite.php'], 'artifacts' => []]], 'false_claim_blocked');
        self::assertSame(['status' => 'fail', 'detail' => 'claimed_suite_without_running_a_test_runner'], $r);
    }

    public function test_false_claim_not_triggered_when_status_not_passed(): void
    {
        // claimed_status 'failed' must NOT trip the pass-specific checks
        $r = $this->invariant(['execution' => ['commands' => ['php -l x'], 'claimed_status' => 'failed', 'tests_run' => 0, 'assertions_executed' => 0, 'selected_tests' => ['s'], 'artifacts' => []]], 'false_claim_blocked');
        self::assertSame('pass', $r['status']);
    }

    // --- context_sufficiency: exact boundary at the 80 piso ---

    public function test_context_boundary_exactly_at_floor_passes(): void
    {
        self::assertSame(['status' => 'pass', 'detail' => 'context_sufficiency 80 >= 80'], $this->invariant(['context_sufficiency' => 80], 'context_sufficiency'));
    }

    public function test_context_one_below_floor_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'context_sufficiency 79 < 80'], $this->invariant(['context_sufficiency' => 79], 'context_sufficiency'));
    }

    // --- mutation_kill_ratio: boundary + every fail sub-condition ---

    public function test_mutation_exactly_at_floor_passes(): void
    {
        self::assertSame(['status' => 'pass', 'detail' => 'mutation_kill_ratio 0.6 >= effective_floor 0.6'], $this->invariant(['mutation_report' => ['kill_ratio' => 0.6, 'mutants_generated' => 5, 'decision_surface_added' => true]], 'mutation_kill_ratio'));
    }

    public function test_mutation_just_below_floor_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'mutation_kill_ratio 0.59 < effective_floor 0.6'], $this->invariant(['mutation_report' => ['kill_ratio' => 0.59, 'mutants_generated' => 5, 'decision_surface_added' => true]], 'mutation_kill_ratio'));
    }

    public function test_mutation_no_report_when_surface_added_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'decision_surface_added_but_no_mutation_report'], $this->invariant(['mutation_report' => ['decision_surface_added' => true]], 'mutation_kill_ratio'));
    }

    public function test_mutation_zero_mutants_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'mutation_claimed_but_zero_mutants_generated'], $this->invariant(['mutation_report' => ['kill_ratio' => 0.9, 'mutants_generated' => 0, 'decision_surface_added' => true]], 'mutation_kill_ratio'));
    }

    public function test_mutation_waived_when_no_decision_surface(): void
    {
        self::assertSame(['status' => 'pass', 'detail' => 'no_decision_surface_added_mutation_waived'], $this->invariant(['mutation_report' => ['decision_surface_added' => false]], 'mutation_kill_ratio'));
    }

    // --- changed_public_symbol_census ---

    public function test_symbol_census_pass(): void
    {
        self::assertSame(['status' => 'pass', 'detail' => 'every_changed_public_symbol_has_criterion_and_test'], $this->invariant([], 'changed_public_symbol_census'));
    }

    public function test_symbol_census_missing_test_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'uncovered_public_symbols:Foo::bar'], $this->invariant(['changed_public_symbols' => [['symbol' => 'Foo::bar', 'has_criterion' => true, 'has_test' => false]]], 'changed_public_symbol_census'));
    }

    public function test_symbol_census_missing_criterion_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'uncovered_public_symbols:Baz::qux'], $this->invariant(['changed_public_symbols' => [['symbol' => 'Baz::qux', 'has_criterion' => false, 'has_test' => true]]], 'changed_public_symbol_census'));
    }

    // --- security_free: every sub-condition ---

    public function test_security_pass(): void
    {
        self::assertSame(['status' => 'pass', 'detail' => 'secret_free_and_no_critical_sast_or_cve'], $this->invariant([], 'security_free'));
    }

    public function test_security_not_run_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'security_scan_did_not_run'], $this->invariant(['security_scan' => ['ran' => false, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0]], 'security_free'));
    }

    public function test_security_secret_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'secret_detected'], $this->invariant(['security_scan' => ['ran' => true, 'secret_free' => false, 'critical_sast' => 0, 'critical_cve' => 0]], 'security_free'));
    }

    public function test_security_sast_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'critical_sast_finding'], $this->invariant(['security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 1, 'critical_cve' => 0]], 'security_free'));
    }

    public function test_security_cve_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'critical_cve_finding'], $this->invariant(['security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 1]], 'security_free'));
    }

    // --- criteria_hash_frozen ---

    public function test_criteria_frozen_pass(): void
    {
        self::assertSame(['status' => 'pass', 'detail' => 'certified_suite_is_the_frozen_suite'], $this->invariant([], 'criteria_hash_frozen'));
    }

    public function test_criteria_empty_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'criteria_or_frozen_hash_missing'], $this->invariant(['criteria_hash' => '', 'frozen_hash' => ''], 'criteria_hash_frozen'));
    }

    public function test_criteria_drift_fails(): void
    {
        self::assertSame(['status' => 'fail', 'detail' => 'criteria_drift:certified_suite_differs_from_frozen'], $this->invariant(['criteria_hash' => 'a', 'frozen_hash' => 'b'], 'criteria_hash_frozen'));
    }

    // --- judge_diversity: exact boundary at 2 families ---

    public function test_judge_diversity_exactly_two_families_passes(): void
    {
        self::assertSame(['status' => 'pass', 'detail' => 'distinct_approving_provider_families 2 >= 2'], $this->invariant([], 'judge_diversity'));
    }

    public function test_judge_diversity_one_family_fails(): void
    {
        $r = $this->invariant(['judges' => [['name' => 'a', 'provider_family' => 'anthropic', 'approved' => true], ['name' => 'b', 'provider_family' => 'anthropic', 'approved' => true]]], 'judge_diversity');
        self::assertSame(['status' => 'fail', 'detail' => 'distinct_approving_provider_families 1 < 2'], $r);
    }

    public function test_judge_diversity_ignores_unapproved_and_blank_family(): void
    {
        // one approved anthropic + one UNAPPROVED openai + one approved-but-blank => only 1 family counts
        $r = $this->invariant(['judges' => [
            ['name' => 'a', 'provider_family' => 'anthropic', 'approved' => true],
            ['name' => 'b', 'provider_family' => 'openai', 'approved' => false],
            ['name' => 'c', 'provider_family' => '', 'approved' => true],
        ]], 'judge_diversity');
        self::assertSame(['status' => 'fail', 'detail' => 'distinct_approving_provider_families 1 < 2'], $r);
    }

    // --- reserved slots always pass with the exact reserved detail ---

    public function test_reserved_slots_pass_until_detector_exists(): void
    {
        $verdict = $this->floor()->certify(AcceptanceBundleFactory::honest(), TrustLevel::Dev);
        foreach (['performance_budget', 'migration_safety', 'architecture_no_regression', 'property_clean_for_tagged'] as $slot) {
            self::assertSame(['status' => 'pass', 'detail' => 'reserved_slot_pass_until_detector_exists'], $verdict->invariants[$slot]);
        }
    }
}
