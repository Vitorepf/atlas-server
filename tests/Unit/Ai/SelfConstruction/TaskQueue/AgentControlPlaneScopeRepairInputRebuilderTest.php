<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneScopeRepairInputRebuilder;
use Tests\TestCase;

class AgentControlPlaneScopeRepairInputRebuilderTest extends TestCase
{
    private function makeGuard(): AtlasLoopHarnessGuard
    {
        return new AtlasLoopHarnessGuard;
    }

    private function basePacket(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'tp1',
            'objective' => 'Implement Foo',
            'source' => 'operator_intake',
            'operator_id' => 'op-1',
            'parent_run_id' => 'pr-1',
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
            'forbidden_files' => ['app/Forbidden.php'],
            'acceptance_criteria' => ['Build it'],
        ], $overrides);
    }

    public function test_is_test_path_detects_tests_dir(): void
    {
        self::assertTrue(AgentControlPlaneScopeRepairInputRebuilder::isTestPath('tests/Unit/FooTest.php'));
        self::assertTrue(AgentControlPlaneScopeRepairInputRebuilder::isTestPath('app/tests/Unit/FooTest.php'));
    }

    public function test_is_test_path_detects_test_suffix(): void
    {
        self::assertTrue(AgentControlPlaneScopeRepairInputRebuilder::isTestPath('app/FooTest.php'));
    }

    public function test_is_test_path_normalizes_backslashes(): void
    {
        self::assertTrue(AgentControlPlaneScopeRepairInputRebuilder::isTestPath('app\\FooTest.php'));
    }

    public function test_is_test_path_false_for_non_test(): void
    {
        self::assertFalse(AgentControlPlaneScopeRepairInputRebuilder::isTestPath('app/Foo.php'));
        self::assertFalse(AgentControlPlaneScopeRepairInputRebuilder::isTestPath(''));
    }

    public function test_petro_paths_to_scrub_unions_removed_and_petro(): void
    {
        $packet = $this->basePacket([
            'forbidden_files' => ['app/Petro.php', 'app/Other.php'],
        ]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub($packet, ['app/Removed.php'], $this->makeGuard());

        self::assertContains('app/Removed.php', $result);
    }

    public function test_petro_paths_to_scrub_dedupes(): void
    {
        $packet = $this->basePacket([
            'forbidden_files' => ['app/Petro.php'],
        ]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub($packet, ['app/Petro.php'], $this->makeGuard());

        self::assertCount(1, $result);
    }

    public function test_repair_input_keeping_scope_preserves_scope(): void
    {
        $packet = $this->basePacket();

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, []);

        self::assertSame('tp1', $result['task_packet_id']);
        self::assertSame(['app/Foo.php', 'app/Bar.php'], $result['allowed_files']);
        self::assertSame(['app/Forbidden.php'], $result['forbidden_files']);
    }

    public function test_repair_input_keeping_scope_synthesises_minimal_criterion_when_empty(): void
    {
        $packet = $this->basePacket([
            'acceptance_criteria' => [],
        ]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, []);

        self::assertNotEmpty($result['acceptance_criteria']);
        self::assertStringContainsString('allowed_files', $result['acceptance_criteria'][0]);
    }

    public function test_repair_input_keeping_scope_appends_reconciliation_note(): void
    {
        $packet = $this->basePacket();

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, ['app/Petro.php']);

        self::assertStringContainsString('app/Petro.php', $result['objective']);
        self::assertStringContainsString('Scope reconciliation', $result['objective']);
    }

    public function test_repair_input_without_forbidden_targets_removes_blocked_paths(): void
    {
        $packet = $this->basePacket([
            'allowed_files' => ['app/A.php', 'app/B.php', 'app/C.php'],
        ]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputWithoutForbiddenTargets($packet, ['app/B.php']);

        self::assertSame(['app/A.php', 'app/C.php'], $result['allowed_files']);
        self::assertContains('app/B.php', $result['forbidden_files']);
    }

    public function test_repair_input_without_forbidden_targets_appends_repair_note(): void
    {
        $packet = $this->basePacket();

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputWithoutForbiddenTargets($packet, ['app/X.php']);

        self::assertStringContainsString('app/X.php', $result['objective']);
        self::assertStringContainsString('Scope repair', $result['objective']);
    }

    public function test_repair_input_methods_are_deterministic(): void
    {
        $packet = $this->basePacket();
        $a = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, []);
        $b = AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, []);

        self::assertSame($a, $b);
    }
}
