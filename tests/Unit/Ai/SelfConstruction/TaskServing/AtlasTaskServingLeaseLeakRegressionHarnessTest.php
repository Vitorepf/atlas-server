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
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_REGISTRY_DRIFT, $scenario['expected_parity_class']);
    }
}
