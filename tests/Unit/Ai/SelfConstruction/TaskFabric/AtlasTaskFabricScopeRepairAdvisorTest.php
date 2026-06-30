<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricScopeRepairAdvisor;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricScopeRepairAdvisorTest extends TestCase
{
    private AtlasTaskFabricScopeRepairAdvisor $advisor;

    protected function setUp(): void
    {
        $this->advisor = new AtlasTaskFabricScopeRepairAdvisor;
    }

    // ── AC2: happy path — impl + test files, no forbidden collision ──────────

    public function test_claimable_when_impl_and_test_files_present_with_no_forbidden(): void
    {
        $result = $this->advisor->advise([
            'objective'     => 'Implement AtlasFoo to handle bar',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AtlasFoo.php',
                'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php',
            ],
            'forbidden_files' => [],
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_CLAIMABLE, $result['status']);
        $this->assertTrue($result['scope_preserved']);
        $this->assertArrayHasKey('allowed_files', $result);
        $this->assertCount(2, $result['allowed_files']);
    }

    public function test_claimable_returns_schema(): void
    {
        $result = $this->advisor->advise([
            'objective'     => 'Add AtlasBar service',
            'allowed_files' => ['app/Services/AtlasBar.php', 'tests/Unit/AtlasBarTest.php'],
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::SCHEMA, $result['schema']);
        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_CLAIMABLE, $result['status']);
    }

    // ── AC1: test-only poison packets ────────────────────────────────────────

    public function test_unclaimable_when_only_test_files_remain_and_objective_names_impl_class(): void
    {
        $result = $this->advisor->advise([
            'objective'     => 'Implement AtlasTaskFabricScopeRepairAdvisor to prevent poison packets',
            'allowed_files' => [
                'tests/Unit/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricScopeRepairAdvisorTest.php',
            ],
            'forbidden_files' => [],
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_UNCLAIMABLE, $result['status']);
        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REASON_IMPLEMENTATION_SCOPE_REMOVED, $result['reason']);
        $this->assertFalse($result['scope_preserved']);
    }

    public function test_repair_action_is_readd_impl_when_objective_names_atlas_class(): void
    {
        $result = $this->advisor->advise([
            'objective'     => 'Implement AtlasFooBarService to do something useful',
            'allowed_files' => ['tests/Unit/AtlasFooBarServiceTest.php'],
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_READD_IMPL, $result['repair_action']);
    }

    public function test_repair_action_is_one_of_valid_options(): void
    {
        $valid = [
            AtlasTaskFabricScopeRepairAdvisor::REPAIR_READD_IMPL,
            AtlasTaskFabricScopeRepairAdvisor::REPAIR_SPLIT_OPERATOR,
            AtlasTaskFabricScopeRepairAdvisor::REPAIR_CANCEL_POISON,
        ];

        $result = $this->advisor->advise([
            'objective'     => 'some vague task with no class name',
            'allowed_files' => ['tests/Unit/SomethingTest.php'],
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_UNCLAIMABLE, $result['status']);
        $this->assertContains($result['repair_action'], $valid, 'repair_action must be one of the three valid options');
    }

    public function test_repair_action_is_split_operator_when_packet_requires_operator(): void
    {
        $result = $this->advisor->advise([
            'objective'        => 'some task without recognizable class name',
            'allowed_files'    => ['tests/Unit/SomeTest.php'],
            'requires_operator' => true,
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_SPLIT_OPERATOR, $result['repair_action']);
    }

    public function test_repair_action_is_cancel_poison_when_no_recoverable_context(): void
    {
        $result = $this->advisor->advise([
            'objective'        => 'do some unspecified work',
            'allowed_files'    => ['tests/Unit/SomeTest.php'],
            'requires_operator' => false,
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REPAIR_CANCEL_POISON, $result['repair_action']);
    }

    // ── Forbidden file collision ─────────────────────────────────────────────

    public function test_unclaimable_when_forbidden_file_appears_in_allowed_scope(): void
    {
        $result = $this->advisor->advise([
            'objective'       => 'Implement AtlasFoo',
            'allowed_files'   => ['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'],
            'forbidden_files' => ['app/Services/AtlasFoo.php'],
        ]);

        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::STATUS_UNCLAIMABLE, $result['status']);
        $this->assertSame(AtlasTaskFabricScopeRepairAdvisor::REASON_FORBIDDEN_FILE_IN_SCOPE, $result['reason']);
        $this->assertFalse($result['scope_preserved']);
    }

    // ── File count metadata ──────────────────────────────────────────────────

    public function test_result_includes_impl_and_test_file_counts(): void
    {
        $result = $this->advisor->advise([
            'objective'     => 'Implement AtlasThing',
            'allowed_files' => [
                'app/Services/AtlasThing.php',
                'app/Services/AtlasHelper.php',
                'tests/Unit/AtlasThingTest.php',
            ],
        ]);

        $this->assertSame(2, $result['impl_file_count']);
        $this->assertSame(1, $result['test_file_count']);
    }
}
