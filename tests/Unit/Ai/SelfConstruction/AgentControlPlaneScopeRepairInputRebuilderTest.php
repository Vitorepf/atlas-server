<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneScopeRepairInputRebuilder;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneScopeRepairInputRebuilder never reopens a forbidden-target repair
 * whose surviving allowed_files are test-only without an explicit missing-implementation hint,
 * while non-test survivors remain reopenable with normalized allowed_files/scope_in/forbidden_files.
 */
final class AgentControlPlaneScopeRepairInputRebuilderTest extends TestCase
{
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

    public function test_test_only_survivors_sets_blocked_reason(): void
    {
        $packet = $this->basePacket([
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
        ]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputWithoutForbiddenTargets($packet, ['app/Foo.php']);

        self::assertSame(['tests/Unit/FooTest.php'], $result['allowed_files']);
        self::assertSame('test_only_survivors_after_forbidden_removal', $result['repair_blocked_reason']);
    }

    public function test_test_only_survivors_objective_includes_missing_implementation_hint(): void
    {
        $packet = $this->basePacket([
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
        ]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputWithoutForbiddenTargets($packet, ['app/Foo.php']);

        self::assertStringContainsString('BLOCKED', $result['objective']);
        self::assertStringContainsString('no implementation file remains', $result['objective']);
    }

    public function test_non_test_survivor_remains_reopenable_and_normalizes_scope(): void
    {
        $packet = $this->basePacket([
            'allowed_files' => ['app/Foo.php', 'app/Bar.php', 'tests/Unit/FooTest.php'],
            'scope_in' => ['app/Foo.php'],
            'forbidden_files' => ['app/Forbidden.php'],
        ]);

        $result = AgentControlPlaneScopeRepairInputRebuilder::repairInputWithoutForbiddenTargets($packet, ['app/Foo.php']);

        self::assertNull($result['repair_blocked_reason']);
        self::assertSame(['app/Bar.php', 'tests/Unit/FooTest.php'], $result['allowed_files']);
        self::assertContains('app/Bar.php', $result['scope_in']);
        self::assertContains('tests/Unit/FooTest.php', $result['scope_in']);
        self::assertContains('app/Foo.php', $result['forbidden_files']);
        self::assertContains('app/Forbidden.php', $result['forbidden_files']);
    }
}
