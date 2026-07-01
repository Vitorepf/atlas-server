<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricScopeRepairAdvisor;
use PHPUnit\Framework\TestCase;

/**
 * Test-only poison packets and forbidden allowed_files are caught before serving: claimable for
 * impl+test scope, unclaimable implementation_scope_removed for test-only scope, repair_action
 * readd_allowed_impl when the objective names an Atlas implementation class, split_operator_task
 * when operator involvement is required, cancel_poison fallback, and forbidden_file_in_allowed_scope
 * detection. scope_preserved is false whenever the packet is unclaimable, and impl_file_count /
 * test_file_count always describe the actual allowed_files mix.
 */
final class AtlasTaskFabricScopeRepairAdvisorTest extends TestCase
{
    private AtlasTaskFabricScopeRepairAdvisor $advisor;

    protected function setUp(): void
    {
        $this->advisor = new AtlasTaskFabricScopeRepairAdvisor;
    }

    public function test_claimable_for_impl_and_test_scope(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasFoo to handle bar',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AtlasFoo.php',
                'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php',
            ],
        ]);

        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_CLAIMABLE, $result['status']);
        self::assertTrue($result['scope_preserved']);
        self::assertSame(1, $result['impl_file_count']);
        self::assertSame(1, $result['test_file_count']);
    }

    public function test_unclaimable_implementation_scope_removed_for_test_only_scope(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasTaskFabricScopeRepairAdvisor to prevent poison packets',
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricScopeRepairAdvisorTest.php'],
        ]);

        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_UNCLAIMABLE, $result['status']);
        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::REASON_IMPLEMENTATION_SCOPE_REMOVED, $result['reason']);
        self::assertFalse($result['scope_preserved']);
        self::assertSame(0, $result['impl_file_count']);
        self::assertSame(1, $result['test_file_count']);
    }

    public function test_repair_action_readd_allowed_impl_when_objective_names_atlas_class(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasFooBarService to do something useful',
            'allowed_files' => ['tests/Unit/AtlasFooBarServiceTest.php'],
        ]);

        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_READD_IMPL, $result['repair_action']);
    }

    public function test_repair_action_split_operator_task_when_operator_required(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'some task without recognizable class name',
            'allowed_files' => ['tests/Unit/SomeTest.php'],
            'requires_operator' => true,
        ]);

        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_SPLIT_OPERATOR, $result['repair_action']);
    }

    public function test_repair_action_cancel_poison_fallback(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'do some unspecified work',
            'allowed_files' => ['tests/Unit/SomeTest.php'],
            'requires_operator' => false,
        ]);

        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_CANCEL_POISON, $result['repair_action']);
    }

    public function test_forbidden_file_in_allowed_scope_is_detected(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasFoo',
            'allowed_files' => ['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'],
            'forbidden_files' => ['app/Services/AtlasFoo.php'],
        ]);

        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_UNCLAIMABLE, $result['status']);
        self::assertSame(AtlasTaskFabricScopeRepairAdvisor::REASON_FORBIDDEN_FILE_IN_SCOPE, $result['reason']);
        self::assertFalse($result['scope_preserved']);
    }

    public function test_impl_and_test_file_counts_describe_the_actual_allowed_files_mix(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasThing',
            'allowed_files' => [
                'app/Services/AtlasThing.php',
                'app/Services/AtlasHelper.php',
                'tests/Unit/AtlasThingTest.php',
            ],
        ]);

        self::assertSame(2, $result['impl_file_count']);
        self::assertSame(1, $result['test_file_count']);
    }

    public function test_scope_preserved_is_false_for_both_test_only_and_forbidden_collision_outcomes(): void
    {
        $testOnly = $this->advisor->advise([
            'objective' => 'Implement AtlasThing',
            'allowed_files' => ['tests/Unit/AtlasThingTest.php'],
        ]);
        $forbiddenCollision = $this->advisor->advise([
            'objective' => 'Implement AtlasThing',
            'allowed_files' => ['app/Services/AtlasThing.php', 'tests/Unit/AtlasThingTest.php'],
            'forbidden_files' => ['app/Services/AtlasThing.php'],
        ]);

        self::assertFalse($testOnly['scope_preserved']);
        self::assertFalse($forbiddenCollision['scope_preserved']);
    }
}
