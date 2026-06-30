<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneScopeRepairInputRebuilder;
use Tests\TestCase;

final class AgentControlPlaneScopeRepairInputRebuilderTest extends TestCase
{
    /** A known petreo path from the FORBIDDEN_SELF_TARGETS constant. */
    private const PETREO_PATH = 'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php';

    private function guard(): AtlasLoopHarnessGuard
    {
        return new AtlasLoopHarnessGuard;
    }

    private function basePacket(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id'     => 'pkt-001',
            'objective'          => 'Implement AtlasFoo so it does X.',
            'allowed_files'      => ['app/Services/Ai/Foo.php', 'tests/Feature/Ai/FooTest.php'],
            'scope_in'           => ['app/Services/Ai/Foo.php', 'tests/Feature/Ai/FooTest.php'],
            'forbidden_files'    => [],
            'acceptance_criteria' => ['Runnable gate: php artisan test exits 0.'],
            'required_evidence'  => ['tests_or_gates_result'],
            'risk_level'         => 'low',
            'max_runtime_seconds' => 3600,
            'max_token_budget'   => 500,
            'lease_ttl_seconds'  => 1800,
            'rollback_strategy'  => 'plan_only',
        ], $overrides);
    }

    // ── AC1: runnable gate (implicit) ─────────────────────────────────────────

    public function test_ac1_repair_input_keeping_scope_returns_expected_keys(): void
    {
        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($this->basePacket(), []);

        $this->assertArrayHasKey('task_packet_id',    $r);
        $this->assertArrayHasKey('objective',         $r);
        $this->assertArrayHasKey('allowed_files',     $r);
        $this->assertArrayHasKey('scope_in',          $r);
        $this->assertArrayHasKey('forbidden_files',   $r);
        $this->assertArrayHasKey('acceptance_criteria', $r);
        $this->assertArrayHasKey('required_evidence', $r);
        $this->assertArrayHasKey('risk_level',        $r);
        $this->assertArrayHasKey('lease_ttl_seconds', $r);
        $this->assertArrayHasKey('rollback_strategy', $r);
    }

    // ── AC2: petreoPathsToScrub — removed targets + forbidden self-targets, no test paths ──

    public function test_ac2_removed_targets_always_appear_in_result(): void
    {
        $removed = ['app/Services/Ai/Removed.php'];
        $result  = AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub(
            $this->basePacket(),
            $removed,
            $this->guard(),
        );

        $this->assertContains('app/Services/Ai/Removed.php', $result);
    }

    public function test_ac2_forbidden_self_target_paths_appear_in_result(): void
    {
        // Use a real path from FORBIDDEN_SELF_TARGETS so the real guard returns true.
        $packet = $this->basePacket(['forbidden_files' => [self::PETREO_PATH]]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub(
            $packet,
            [],
            $this->guard(),
        );

        $this->assertContains(self::PETREO_PATH, $result);
    }

    public function test_ac2_ordinary_test_paths_are_not_included(): void
    {
        // A test path is NOT in FORBIDDEN_SELF_TARGETS → guard returns false → not in result.
        $testPath = 'tests/Feature/Ai/SomeTest.php';
        $packet   = $this->basePacket(['forbidden_files' => [$testPath]]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub(
            $packet,
            [],
            $this->guard(),
        );

        $this->assertNotContains($testPath, $result,
            'ordinary test paths must not appear when guard does not flag them as forbidden self-targets');
    }

    public function test_ac2_result_is_union_of_removed_targets_and_petreo_forbidden(): void
    {
        $removedPath = 'app/Services/Ai/Removed.php';
        $packet      = $this->basePacket(['forbidden_files' => [self::PETREO_PATH]]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub(
            $packet,
            [$removedPath],
            $this->guard(),
        );

        $this->assertContains(self::PETREO_PATH, $result);
        $this->assertContains($removedPath,      $result);
    }

    public function test_ac2_duplicates_are_removed(): void
    {
        // Pass PETREO_PATH both as a removed target and in forbidden_files.
        $packet = $this->basePacket(['forbidden_files' => [self::PETREO_PATH]]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub(
            $packet,
            [self::PETREO_PATH],  // also in removedTargets
            $this->guard(),
        );

        $this->assertSame(array_unique($result), $result,
            'result must not contain duplicate paths');
    }

    // ── AC3: repairInputKeepingScope removes scrubbed ACs and appends scope note ──

    public function test_ac3_ac_referencing_scrubbed_full_path_is_removed(): void
    {
        $scrubPath = 'app/Services/Ai/Petreo.php';
        $packet    = $this->basePacket([
            'acceptance_criteria' => [
                "Implement {$scrubPath} with method doX",
                'Runnable gate: php artisan test exits 0.',
            ],
        ]);

        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, [$scrubPath]);

        foreach ($r['acceptance_criteria'] as $ac) {
            $this->assertStringNotContainsString($scrubPath, $ac,
                'ACs referencing the scrubbed path must be removed');
        }
    }

    public function test_ac3_ac_referencing_scrubbed_basename_is_removed(): void
    {
        $scrubPath = 'app/Services/Ai/Petreo.php';
        $packet    = $this->basePacket([
            'acceptance_criteria' => [
                'Implement Petreo.php with the public API',  // basename match
                'Runnable gate: php artisan test exits 0.',
            ],
        ]);

        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, [$scrubPath]);

        foreach ($r['acceptance_criteria'] as $ac) {
            $this->assertStringNotContainsString('Petreo.php', $ac,
                'ACs referencing the scrubbed basename must be removed');
        }
    }

    public function test_ac3_objective_gets_scope_reconciliation_appended(): void
    {
        $scrubPath = 'app/Services/Ai/Petreo.php';
        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope(
            $this->basePacket(),
            [$scrubPath],
        );

        $this->assertStringContainsString('Scope reconciliation', $r['objective']);
        $this->assertStringContainsString($scrubPath, $r['objective']);
    }

    public function test_ac3_unaffected_acs_are_preserved(): void
    {
        $scrubPath  = 'app/Services/Ai/Petreo.php';
        $keepAc     = 'Runnable gate: php artisan test exits 0.';
        $packet     = $this->basePacket([
            'acceptance_criteria' => [
                "Edit {$scrubPath}",
                $keepAc,
            ],
        ]);

        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, [$scrubPath]);

        $this->assertContains($keepAc, $r['acceptance_criteria'],
            'ACs not referencing scrubbed paths must be preserved');
    }

    // ── AC4: empty acceptance → synthesised minimal criterion; scope fields preserved ──

    public function test_ac4_emptied_acceptance_is_replaced_with_minimal_criterion(): void
    {
        $scrubPath = 'app/Services/Ai/Petreo.php';
        $packet    = $this->basePacket([
            'acceptance_criteria' => ["Edit {$scrubPath}"],  // only AC references scrubbed path
        ]);

        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, [$scrubPath]);

        $this->assertNotEmpty($r['acceptance_criteria'],
            'acceptance must not be empty after scrubbing');
        $this->assertCount(1, $r['acceptance_criteria']);
        $this->assertStringContainsString('allowed_files', $r['acceptance_criteria'][0],
            'synthesised criterion must reference allowed_files');
    }

    public function test_ac4_allowed_files_are_preserved_after_scrub(): void
    {
        $scrubPath = 'app/Services/Ai/Petreo.php';
        $packet    = $this->basePacket([
            'allowed_files' => ['app/Services/Ai/Safe.php', 'tests/Feature/Ai/SafeTest.php'],
            'scope_in'      => ['app/Services/Ai/Safe.php', 'tests/Feature/Ai/SafeTest.php'],
            'acceptance_criteria' => ["Edit {$scrubPath}"],
        ]);

        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, [$scrubPath]);

        $this->assertSame(['app/Services/Ai/Safe.php', 'tests/Feature/Ai/SafeTest.php'], $r['allowed_files']);
        $this->assertContains('app/Services/Ai/Safe.php', $r['scope_in']);
    }

    public function test_ac4_risk_budget_lease_rollback_fields_preserved(): void
    {
        $packet = $this->basePacket([
            'risk_level'                                        => 'medium',
            'cost_budget_requirements'                          => ['max_runtime_seconds' => 7200, 'max_token_budget' => 2000],
            'lease_requirements'                                => ['lease_ttl_seconds' => 900],
            'rollback_requirements'                             => ['rollback_strategy' => 'revert_commit'],
        ]);

        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, []);

        $this->assertSame('medium',         $r['risk_level']);
        $this->assertSame(7200,             $r['max_runtime_seconds']);
        $this->assertSame(2000,             $r['max_token_budget']);
        $this->assertSame(900,              $r['lease_ttl_seconds']);
        $this->assertSame('revert_commit',  $r['rollback_strategy']);
    }

    public function test_ac4_forbidden_files_preserved_in_output(): void
    {
        $packet = $this->basePacket(['forbidden_files' => ['app/Services/Ai/Guard.php']]);

        $r = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, []);

        $this->assertContains('app/Services/Ai/Guard.php', $r['forbidden_files']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_deterministic_repair_input(): void
    {
        $packet = $this->basePacket([
            'acceptance_criteria' => ['Edit app/Services/Ai/Petreo.php', 'Other AC'],
        ]);

        $r1 = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, ['app/Services/Ai/Petreo.php']);
        $r2 = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, ['app/Services/Ai/Petreo.php']);

        $this->assertSame(
            json_encode($r1, JSON_UNESCAPED_SLASHES),
            json_encode($r2, JSON_UNESCAPED_SLASHES),
        );
    }
}
