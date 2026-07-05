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

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: repaired_allowed_files, repair_confidence, unsafe_reason
    // ═══════════════════════════════════════════════════════════════════════

    public function test_claimable_has_full_repair_confidence_and_empty_unsafe_reason(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasFoo to handle bar',
            'allowed_files' => [
                'app/Services/AtlasFoo.php',
                'tests/Unit/AtlasFooTest.php',
            ],
        ]);

        $this->assertArrayHasKey('repaired_allowed_files', $result);
        $this->assertArrayHasKey('repair_confidence', $result);
        $this->assertArrayHasKey('unsafe_reason', $result);
        $this->assertSame(1.0, $result['repair_confidence']);
        $this->assertNull($result['unsafe_reason']);
        $this->assertSame(['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'], $result['repaired_allowed_files']);
    }

    public function test_readd_impl_repair_proposes_repaired_allowed_files(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasFooBarService to do something useful',
            'allowed_files' => ['tests/Unit/AtlasFooBarServiceTest.php'],
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_READD_IMPL, $result['repair_action']);
        $this->assertArrayHasKey('repaired_allowed_files', $result);
        $this->assertContains('app/Services/AtlasFooBarService.php', $result['repaired_allowed_files'],
            'repaired_allowed_files must propose adding the impl file');
        $this->assertGreaterThan(0.5, $result['repair_confidence']);
        $this->assertNull($result['unsafe_reason']);
    }

    public function test_cancel_poison_has_unsafe_reason_and_low_confidence(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'do some unspecified work',
            'allowed_files' => ['tests/Unit/SomeTest.php'],
            'requires_operator' => false,
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_CANCEL_POISON, $result['repair_action']);
        $this->assertLessThan(0.5, $result['repair_confidence'],
            'cancel_poison must have low repair_confidence');
        $this->assertNotNull($result['unsafe_reason'],
            'cancel_poison must have unsafe_reason');
    }

    public function test_forbidden_file_repair_removes_forbidden_from_allowed(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'Implement AtlasFoo',
            'allowed_files' => ['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'],
            'forbidden_files' => ['app/Services/AtlasFoo.php'],
        ]);

        $this->assertArrayHasKey('repaired_allowed_files', $result);
        $this->assertNotContains('app/Services/AtlasFoo.php', $result['repaired_allowed_files'],
            'forbidden file must be removed from repaired_allowed_files');
        $this->assertNull($result['unsafe_reason'],
            'forbidden file repair must not be unsafe');
        $this->assertGreaterThan(0.5, $result['repair_confidence']);
    }

    public function test_split_operator_has_medium_confidence(): void
    {
        $result = $this->advisor->advise([
            'objective' => 'some task without recognizable class name',
            'allowed_files' => ['tests/Unit/SomeTest.php'],
            'requires_operator' => true,
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_SPLIT_OPERATOR, $result['repair_action']);
        $this->assertGreaterThanOrEqual(0.5, $result['repair_confidence']);
        $this->assertLessThan(0.9, $result['repair_confidence']);
        $this->assertNull($result['unsafe_reason']);
    }
}
