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
        ]);

        $this->assertSame('safe_delete', $plan['action']);
        $this->assertSame(['delete_file:app/Services/Old.php'], $plan['deletion_steps']);
        $this->assertSame(['remove_imports_of:OldOrgan_from:app/Services/Old.php'], $plan['import_cleanup_steps']);
        $this->assertSame(['php artisan test tests/Unit/OldTest.php'], $plan['replay_gates']);
        $this->assertTrue($plan['rollback_receipt_required']);
        $this->assertSame([], $plan['risk_reasons']);
    }

    public function test_plan_hash_is_deterministic(): void
    {
        $input = [
            'candidate_id' => 'OldOrgan',
            'replacement_owner' => 'NewOrgan',
            'allowed_files' => ['app/Services/Old.php'],
            'required_tests' => ['tests/Unit/OldTest.php'],
        ];

        $first = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion($input);
        $second = (new AtlasSelfConstructionSafeDeletionPlanner)->planSafeDeletion($input);

        $this->assertSame($first['plan_hash'], $second['plan_hash']);
    }
}
