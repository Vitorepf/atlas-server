<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSafeDeletionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSafeDeletionPlannerTest extends TestCase
{
    private function safeCluster(): array
    {
        return [
            'behavior_equivalence_proven' => true,
            'consumers' => [
                ['name' => 'CallerA', 'covered' => true],
                ['name' => 'CallerB', 'covered' => true],
            ],
            'replacement_owner' => 'AtlasSelfConstructionOrganReadinessComposer',
            'rollback_notes' => 'git revert the merge commit; consumers unchanged',
            'allowed_files' => ['app/Services/Ai/Foo.php'],
            'required_tests' => ['tests/Unit/Ai/FooTest.php'],
        ];
    }

    public function test_safe_candidate_emits_delete_or_merge_with_full_plan(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->plan($this->safeCluster());

        $this->assertSame('delete_or_merge', $plan['action']);
        $this->assertSame(['app/Services/Ai/Foo.php'], $plan['allowed_files']);
        $this->assertSame(['tests/Unit/Ai/FooTest.php'], $plan['required_tests']);
        $this->assertSame('git revert the merge commit; consumers unchanged', $plan['rollback_plan']);
        $this->assertSame([], $plan['risk_reasons']);
    }

    public function test_blocked_when_behavior_equivalence_not_proven(): void
    {
        $cluster = $this->safeCluster();
        $cluster['behavior_equivalence_proven'] = false;

        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->plan($cluster);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('behavior_equivalence_not_proven', $plan['risk_reasons']);
    }

    public function test_blocked_when_consumers_missing(): void
    {
        $cluster = $this->safeCluster();
        $cluster['consumers'] = [];

        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->plan($cluster);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('consumer_coverage_incomplete', $plan['risk_reasons']);
    }

    public function test_blocked_when_a_consumer_is_not_covered(): void
    {
        $cluster = $this->safeCluster();
        $cluster['consumers'] = [
            ['name' => 'CallerA', 'covered' => true],
            ['name' => 'CallerB', 'covered' => false],
        ];

        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->plan($cluster);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('consumer_coverage_incomplete', $plan['risk_reasons']);
    }

    public function test_blocked_when_replacement_owner_missing(): void
    {
        $cluster = $this->safeCluster();
        $cluster['replacement_owner'] = '';

        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->plan($cluster);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('replacement_owner_missing', $plan['risk_reasons']);
    }

    public function test_blocked_when_rollback_notes_missing(): void
    {
        $cluster = $this->safeCluster();
        $cluster['rollback_notes'] = '';

        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->plan($cluster);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('rollback_notes_missing', $plan['risk_reasons']);
    }

    public function test_multiple_missing_facts_all_reported(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->plan([]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertSame([
            'behavior_equivalence_not_proven',
            'consumer_coverage_incomplete',
            'replacement_owner_missing',
            'rollback_notes_missing',
        ], $plan['risk_reasons']);
    }

    public function test_candidate_with_runtime_consumer_is_blocked_from_deletion(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion([
            'candidate_id' => 'OldOrgan',
            'runtime_consumers' => ['AtlasSomeCommand'],
            'replacement_owner' => 'NewOrgan',
        ]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('runtime_consumer_present:AtlasSomeCommand', $plan['risk_reasons']);
    }

    public function test_candidate_with_public_contract_consumer_is_blocked_from_deletion(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion([
            'candidate_id' => 'OldOrgan',
            'public_contract_consumers' => ['ExternalApiClient'],
            'replacement_owner' => 'NewOrgan',
        ]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('public_contract_consumer_present:ExternalApiClient', $plan['risk_reasons']);
    }

    public function test_dead_replacement_covered_candidate_emits_full_executable_deletion_plan(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion([
            'candidate_id' => 'OldOrgan',
            'replacement_owner' => 'NewOrgan',
            'allowed_files' => ['app/Services/Old.php'],
            'required_tests' => ['tests/Unit/OldTest.php'],
            'rollback_path' => 'git revert <merge_commit_sha>',
        ]);

        $this->assertSame('safe_delete', $plan['action']);
        $this->assertSame(['delete_file:app/Services/Old.php'], $plan['deletion_steps']);
        $this->assertSame(['remove_imports_of:OldOrgan_from:app/Services/Old.php'], $plan['import_cleanup_steps']);
        $this->assertSame(['php artisan test tests/Unit/OldTest.php'], $plan['replay_gates']);
        $this->assertTrue($plan['rollback_receipt_required']);
        $this->assertSame('git revert <merge_commit_sha>', $plan['rollback_path']);
        $this->assertSame([], $plan['risk_reasons']);
    }

    public function test_plan_hash_is_deterministic(): void
    {
        $input = [
            'candidate_id' => 'OldOrgan',
            'replacement_owner' => 'NewOrgan',
            'allowed_files' => ['app/Services/Old.php'],
            'required_tests' => ['tests/Unit/OldTest.php'],
            'rollback_path' => 'git revert <merge_commit_sha>',
        ];

        $first = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion($input);
        $second = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion($input);

        $this->assertSame($first['plan_hash'], $second['plan_hash']);
    }

    // ── AC1: zero-reference candidates still require a guard test and rollback path ──

    public function test_zero_reference_candidate_without_guard_test_is_blocked(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion([
            'candidate_id' => 'OldOrgan',
            'replacement_owner' => 'NewOrgan',
            'rollback_path' => 'git revert <merge_commit_sha>',
        ]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('guard_test_missing', $plan['risk_reasons']);
    }

    public function test_zero_reference_candidate_without_rollback_path_is_blocked(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion([
            'candidate_id' => 'OldOrgan',
            'replacement_owner' => 'NewOrgan',
            'required_tests' => ['tests/Unit/OldTest.php'],
        ]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('rollback_path_missing', $plan['risk_reasons']);
    }

    // ── AC3: unresolved dynamic consumers also block deletion ─────────────────

    public function test_unresolved_dynamic_consumer_blocks_deletion(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion([
            'candidate_id' => 'OldOrgan',
            'dynamic_consumers' => ['AtlasSomeReflectiveDispatcher'],
            'replacement_owner' => 'NewOrgan',
            'required_tests' => ['tests/Unit/OldTest.php'],
            'rollback_path' => 'git revert <merge_commit_sha>',
        ]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('unresolved_dynamic_consumer_present:AtlasSomeReflectiveDispatcher', $plan['risk_reasons']);
    }

    // ── AC2: wrapper retirement plans name replacement target + consumer update path ──

    public function test_wrapper_retirement_plan_names_replacement_target_and_consumer_update_path(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planWrapperRetirement([
            'candidate_id' => 'OldWrapper',
            'replacement_target' => 'AtlasNewDirectService',
            'consumer_update_path' => 'update callers to invoke AtlasNewDirectService::handle() directly',
            'consumers_to_migrate' => ['CallerA', 'CallerB'],
        ]);

        $this->assertSame('retire_wrapper', $plan['action']);
        $this->assertSame('AtlasNewDirectService', $plan['replacement_target']);
        $this->assertSame('update callers to invoke AtlasNewDirectService::handle() directly', $plan['consumer_update_path']);
        $this->assertSame(['CallerA', 'CallerB'], $plan['consumers_to_migrate']);
        $this->assertSame([], $plan['risk_reasons']);
    }

    public function test_wrapper_retirement_blocked_without_replacement_target(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planWrapperRetirement([
            'candidate_id' => 'OldWrapper',
            'consumer_update_path' => 'update callers',
        ]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('replacement_target_missing', $plan['risk_reasons']);
    }

    public function test_wrapper_retirement_blocked_without_consumer_update_path(): void
    {
        $plan = (new AtlasSelfConstructionSafeDeletionPlanner)->planWrapperRetirement([
            'candidate_id' => 'OldWrapper',
            'replacement_target' => 'AtlasNewDirectService',
        ]);

        $this->assertSame('blocked', $plan['action']);
        $this->assertContains('consumer_update_path_missing', $plan['risk_reasons']);
    }

    public function test_wrapper_retirement_plan_hash_is_deterministic(): void
    {
        $input = [
            'candidate_id' => 'OldWrapper',
            'replacement_target' => 'AtlasNewDirectService',
            'consumer_update_path' => 'update callers',
        ];
        $planner = new AtlasSelfConstructionSafeDeletionPlanner;

        $this->assertSame($planner->planWrapperRetirement($input)['plan_hash'], $planner->planWrapperRetirement($input)['plan_hash']);
    }
}
