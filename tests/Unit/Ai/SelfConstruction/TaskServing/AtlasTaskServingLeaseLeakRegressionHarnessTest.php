<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingLeaseClaimParityInspector;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingLeaseLeakRegressionHarness;
use Tests\TestCase;

final class AtlasTaskServingLeaseLeakRegressionHarnessTest extends TestCase
{
    private function svc(): AtlasTaskServingLeaseLeakRegressionHarness
    {
        return new AtlasTaskServingLeaseLeakRegressionHarness;
    }

    // ── AC1: named scenarios are present ─────────────────────────────────────

    public function test_provides_all_five_named_scenarios(): void
    {
        $scenarios = $this->svc()->scenarios();

        foreach ([
            AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_CLEAN_PARITY,
            AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_RECOVERABLE_ORPHAN,
            AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_ACTIVE_LEASE_SURPLUS,
            AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_CLAIMED_WITHOUT_LEASE,
            AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_TERMINAL_RECORD_WITH_ACTIVE_LEASE,
        ] as $name) {
            $this->assertArrayHasKey($name, $scenarios, "missing scenario: {$name}");
        }
        $this->assertCount(5, $scenarios);
    }

    public function test_scenario_lookup_by_name_matches_the_full_list(): void
    {
        $all = $this->svc()->scenarios();
        $one = $this->svc()->scenario(AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_CLEAN_PARITY);

        $this->assertSame($all[AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_CLEAN_PARITY], $one);
    }

    public function test_unknown_scenario_name_returns_null(): void
    {
        $this->assertNull($this->svc()->scenario('never_heard_of_this'));
    }

    // ── AC2: each scenario has the required fields ──────────────────────────────

    public function test_every_scenario_has_required_fields(): void
    {
        foreach ($this->svc()->scenarios() as $name => $scenario) {
            foreach (['queue_records', 'active_leases', 'expected_parity_class', 'expected_recoverable_total', 'expected_primary_action'] as $field) {
                $this->assertArrayHasKey($field, $scenario, "scenario {$name} missing field {$field}");
            }
            $this->assertIsArray($scenario['queue_records']);
            $this->assertIsArray($scenario['active_leases']);
            $this->assertIsString($scenario['expected_parity_class']);
            $this->assertIsInt($scenario['expected_recoverable_total']);
            $this->assertIsString($scenario['expected_primary_action']);
        }
    }

    // ── AC3: pure PHP array generation, no side effects ─────────────────────────

    public function test_scenarios_call_does_not_touch_the_filesystem(): void
    {
        $before = sys_get_temp_dir();
        $countBefore = count(scandir($before) ?: []);

        $this->svc()->scenarios();

        $countAfter = count(scandir($before) ?: []);
        $this->assertSame($countBefore, $countAfter, 'generating scenarios must not create files');
    }

    // ── AC4: scenarios are stable and feed the real parity inspector correctly ──

    public function test_scenarios_are_stable_across_calls(): void
    {
        $a = $this->svc()->scenarios();
        $b = $this->svc()->scenarios();

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    public function test_every_scenario_fed_into_the_real_inspector_matches_its_expectations(): void
    {
        $inspector = new AtlasTaskServingLeaseClaimParityInspector;

        foreach ($this->svc()->scenarios() as $name => $scenario) {
            $result = $inspector->inspect($scenario['active_leases'], $scenario['queue_records']);

            $this->assertSame(
                $scenario['expected_parity_class'],
                $result['classification'],
                "scenario {$name}: classification mismatch",
            );
            $this->assertSame(
                $scenario['expected_recoverable_total'],
                $result['recoverable_candidates']['total'],
                "scenario {$name}: recoverable total mismatch",
            );
            $this->assertSame(
                $scenario['expected_primary_action'],
                $result['recommended_next_action'],
                "scenario {$name}: recommended action mismatch",
            );
        }
    }

    public function test_active_lease_surplus_scenario_covers_the_lease_mismatch_class_with_servable_queue(): void
    {
        // This is the exact shape that keeps health false (leases_match_claimed=false) while
        // recoverable.total=0 and the queue is still servable — the class this harness exists for.
        $scenario = $this->svc()->scenario(AtlasTaskServingLeaseLeakRegressionHarness::SCENARIO_ACTIVE_LEASE_SURPLUS);

        $this->assertGreaterThan(count($scenario['queue_records']), count($scenario['active_leases']));
        $this->assertSame(0, $scenario['expected_recoverable_total']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_REGISTRY_DUPLICATE_DRIFT, $scenario['expected_parity_class']);
    }

    // ── AC: business-vocabulary regression scenarios (distinct from the frozen 5) ──────

    public function test_provides_all_five_regression_scenarios(): void
    {
        $scenarios = $this->svc()->regressionScenarios();

        foreach ([
            AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_GHOST_ACTIVE_LEASE,
            AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_TRUE_EXPIRED_LEASE,
            AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_ORPHANED_CLAIM,
            AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_RELEASED_RECOVERY,
            AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_CLEAN_QUEUE,
        ] as $name) {
            $this->assertArrayHasKey($name, $scenarios, "missing regression scenario: {$name}");
        }
        $this->assertCount(5, $scenarios);
    }

    public function test_regression_scenario_lookup_by_name_matches_the_full_list(): void
    {
        $all = $this->svc()->regressionScenarios();
        $one = $this->svc()->regressionScenario(AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_GHOST_ACTIVE_LEASE);

        $this->assertSame($all[AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_GHOST_ACTIVE_LEASE], $one);
    }

    public function test_unknown_regression_scenario_name_returns_null(): void
    {
        $this->assertNull($this->svc()->regressionScenario('never_heard_of_this'));
    }

    public function test_every_regression_scenario_includes_input_snapshot_classification_and_action(): void
    {
        foreach ($this->svc()->regressionScenarios() as $name => $scenario) {
            foreach (['input_snapshot', 'expected_classification', 'expected_recommended_action'] as $field) {
                $this->assertArrayHasKey($field, $scenario, "regression scenario {$name} missing field {$field}");
            }
            $this->assertArrayHasKey('queue_records', $scenario['input_snapshot']);
            $this->assertArrayHasKey('active_leases', $scenario['input_snapshot']);
            $this->assertIsString($scenario['expected_classification']);
            $this->assertIsString($scenario['expected_recommended_action']);
        }
    }

    public function test_every_regression_scenario_fed_into_the_real_inspector_matches_its_expectations(): void
    {
        $inspector = new AtlasTaskServingLeaseClaimParityInspector;

        foreach ($this->svc()->regressionScenarios() as $name => $scenario) {
            $result = $inspector->inspect(
                $scenario['input_snapshot']['active_leases'],
                $scenario['input_snapshot']['queue_records'],
            );

            $this->assertSame($scenario['expected_classification'], $result['classification'], "regression scenario {$name}: classification mismatch");
            $this->assertSame($scenario['expected_recommended_action'], $result['recommended_next_action'], "regression scenario {$name}: action mismatch");
        }
    }

    public function test_regression_scenarios_are_stable_across_calls(): void
    {
        $a = $this->svc()->regressionScenarios();
        $b = $this->svc()->regressionScenarios();

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    public function test_ghost_active_lease_has_no_matching_queue_record(): void
    {
        $scenario = $this->svc()->regressionScenario(AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_GHOST_ACTIVE_LEASE);

        $this->assertSame([], $scenario['input_snapshot']['queue_records']);
        $this->assertNotEmpty($scenario['input_snapshot']['active_leases']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_WITHOUT_CLAIM, $scenario['expected_classification']);
    }

    public function test_true_expired_lease_pairs_a_terminal_record_with_a_surviving_lease(): void
    {
        $scenario = $this->svc()->regressionScenario(AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_TRUE_EXPIRED_LEASE);

        $this->assertSame('completed', $scenario['input_snapshot']['queue_records'][0]['status']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE, $scenario['expected_classification']);
    }

    public function test_orphaned_claim_has_no_active_lease(): void
    {
        $scenario = $this->svc()->regressionScenario(AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_ORPHANED_CLAIM);

        $this->assertSame([], $scenario['input_snapshot']['active_leases']);
        $this->assertSame('claimed', $scenario['input_snapshot']['queue_records'][0]['status']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLAIM_WITHOUT_LEASE, $scenario['expected_classification']);
    }

    public function test_released_recovery_resolves_to_clean_parity(): void
    {
        $scenario = $this->svc()->regressionScenario(AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_RELEASED_RECOVERY);

        $this->assertSame('released', $scenario['input_snapshot']['queue_records'][0]['status']);
        $this->assertSame([], $scenario['input_snapshot']['active_leases']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY, $scenario['expected_classification']);
    }

    public function test_clean_queue_is_the_fully_empty_baseline(): void
    {
        $scenario = $this->svc()->regressionScenario(AtlasTaskServingLeaseLeakRegressionHarness::REGRESSION_SCENARIO_CLEAN_QUEUE);

        $this->assertSame([], $scenario['input_snapshot']['queue_records']);
        $this->assertSame([], $scenario['input_snapshot']['active_leases']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY, $scenario['expected_classification']);
    }
}
