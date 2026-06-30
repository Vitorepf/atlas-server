<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedRespecPlanRegressionHarness;
use Tests\TestCase;

final class AtlasTaskBlockedRespecPlanRegressionHarnessTest extends TestCase
{
    private function svc(): AtlasTaskBlockedRespecPlanRegressionHarness
    {
        return new AtlasTaskBlockedRespecPlanRegressionHarness;
    }

    // ── AC1: named fixtures present ──────────────────────────────────────────

    public function test_provides_all_five_named_fixtures(): void
    {
        $fixtures = $this->svc()->fixtures();

        foreach ([
            AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_EMPTY_FIELD_BLOCKED,
            AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_OBJECTIVE_WITH_CLASS_AND_TEST_PATH,
            AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_FORBIDDEN_TARGET_SUSPECT,
            AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_CONTRADICTORY_ACCEPTANCE_SUSPECT,
            AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_DUPLICATE_ALREADY_DONE_SUSPECT,
        ] as $name) {
            $this->assertArrayHasKey($name, $fixtures, "missing fixture: {$name}");
        }
        $this->assertCount(5, $fixtures);
    }

    public function test_fixture_lookup_by_name_matches_the_full_list(): void
    {
        $all = $this->svc()->fixtures();
        $one = $this->svc()->fixture(AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_EMPTY_FIELD_BLOCKED);

        $this->assertSame($all[AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_EMPTY_FIELD_BLOCKED], $one);
    }

    public function test_unknown_fixture_name_returns_null(): void
    {
        $this->assertNull($this->svc()->fixture('never_heard_of_this'));
    }

    // ── AC2: each fixture has the required fields ───────────────────────────────

    public function test_every_fixture_has_required_fields(): void
    {
        foreach ($this->svc()->fixtures() as $name => $fixture) {
            foreach (['source_packet', 'expected_likely_family', 'expected_recoverable_fields', 'expected_can_submit', 'expected_safe_next_action'] as $field) {
                $this->assertArrayHasKey($field, $fixture, "fixture {$name} missing field {$field}");
            }
            $this->assertIsArray($fixture['source_packet']);
            $this->assertIsString($fixture['expected_likely_family']);
            $this->assertIsArray($fixture['expected_recoverable_fields']);
            $this->assertIsBool($fixture['expected_can_submit']);
            $this->assertIsString($fixture['expected_safe_next_action']);
        }
    }

    // ── AC3: pure in-memory data generation ─────────────────────────────────────

    public function test_fixtures_call_does_not_touch_the_filesystem(): void
    {
        $dir = sys_get_temp_dir();
        $countBefore = count(scandir($dir) ?: []);

        $this->svc()->fixtures();

        $countAfter = count(scandir($dir) ?: []);
        $this->assertSame($countBefore, $countAfter, 'generating fixtures must not create files');
    }

    // ── AC4: covers the current unknown/missing-fields failure mode ────────────

    public function test_empty_field_blocked_fixture_freezes_the_unknown_collapse_failure_mode(): void
    {
        $fixture = $this->svc()->fixture(AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_EMPTY_FIELD_BLOCKED);

        $this->assertSame('unknown', $fixture['expected_likely_family']);
        $this->assertSame([], $fixture['expected_recoverable_fields']);
        $this->assertFalse($fixture['expected_can_submit'], 'an empty-field blocked packet must not be allowed to resubmit as can_submit=true');
    }

    public function test_objective_with_class_and_test_path_fixture_is_recoverable_and_can_submit(): void
    {
        $fixture = $this->svc()->fixture(AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_OBJECTIVE_WITH_CLASS_AND_TEST_PATH);

        $this->assertNotSame('unknown', $fixture['expected_likely_family']);
        $this->assertNotEmpty($fixture['expected_recoverable_fields']);
        $this->assertTrue($fixture['expected_can_submit']);
    }

    public function test_forbidden_target_suspect_fixture_can_never_submit(): void
    {
        $fixture = $this->svc()->fixture(AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_FORBIDDEN_TARGET_SUSPECT);

        $this->assertFalse($fixture['expected_can_submit']);
        $this->assertSame(AtlasTaskBlockedRespecPlanRegressionHarness::ACTION_GIVE_BACK_OR_RESPEC, $fixture['expected_safe_next_action']);
    }

    public function test_contradictory_acceptance_suspect_fixture_can_never_submit(): void
    {
        $fixture = $this->svc()->fixture(AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_CONTRADICTORY_ACCEPTANCE_SUSPECT);

        $this->assertFalse($fixture['expected_can_submit']);
        $this->assertSame('contradictory_acceptance', $fixture['expected_likely_family']);
    }

    public function test_duplicate_already_done_suspect_fixture_recommends_retirement(): void
    {
        $fixture = $this->svc()->fixture(AtlasTaskBlockedRespecPlanRegressionHarness::FIXTURE_DUPLICATE_ALREADY_DONE_SUSPECT);

        $this->assertSame(AtlasTaskBlockedRespecPlanRegressionHarness::ACTION_RETIRE_AS_DUPLICATE, $fixture['expected_safe_next_action']);
        $this->assertFalse($fixture['expected_can_submit']);
    }

    public function test_exactly_one_fixture_represents_the_unknown_family(): void
    {
        $unknownFixtures = array_filter(
            $this->svc()->fixtures(),
            static fn (array $f): bool => $f['expected_likely_family'] === 'unknown',
        );

        $this->assertCount(1, $unknownFixtures, 'only the truly empty-field case should collapse to family=unknown');
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_fixtures_are_stable_across_calls(): void
    {
        $a = $this->svc()->fixtures();
        $b = $this->svc()->fixtures();

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }
}
