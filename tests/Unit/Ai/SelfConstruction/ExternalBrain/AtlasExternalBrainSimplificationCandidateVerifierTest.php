<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationCandidateVerifier;
use Tests\TestCase;

final class AtlasExternalBrainSimplificationCandidateVerifierTest extends TestCase
{
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => 'remove-dead-helper',
            'kind' => 'deletion',
            'consumer_count' => 0,
            'migration_plan' => null,
            'behavior_coverage' => true,
            'test_coverage' => true,
            'rollback_notes' => 'revert commit if regression observed in 24h soak',
            'lines_deleted' => 120,
            'preserved_behavior_tests' => [],
        ], $overrides);
    }

    public function test_zero_consumer_candidate_with_coverage_and_rollback_is_approved_with_positive_score(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate());

        $this->assertTrue($result['approved']);
        $this->assertSame([], $result['blockers']);
        $this->assertGreaterThan(0, $result['net_reduction_score']);
    }

    public function test_live_consumers_without_migration_plan_is_rejected_despite_many_deleted_lines(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'consumer_count' => 5,
            'migration_plan' => null,
            'lines_deleted' => 5000,
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains('live_consumers_without_migration_plan', $result['blockers']);
        $this->assertSame(0, $result['net_reduction_score']);
    }

    public function test_live_consumers_with_migration_plan_can_be_approved(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'consumer_count' => 2,
            'migration_plan' => 'swap callers to NewService over two releases',
        ]));

        $this->assertTrue($result['approved']);
        $this->assertNotContains('live_consumers_without_migration_plan', $result['blockers']);
    }

    public function test_merge_candidate_requires_preserved_behavior_tests_before_approval(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'kind' => 'merge',
            'preserved_behavior_tests' => [],
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains('merge_requires_preserved_behavior_tests', $result['blockers']);
    }

    public function test_merge_candidate_with_preserved_behavior_tests_is_approved(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'kind' => 'merge',
            'preserved_behavior_tests' => ['tests/Unit/FooTest.php', 'tests/Unit/BarTest.php'],
        ]));

        $this->assertTrue($result['approved']);
        $this->assertContains('tests/Unit/FooTest.php', $result['required_tests']);
        $this->assertContains('tests/Unit/BarTest.php', $result['required_tests']);
    }

    public function test_missing_behavior_coverage_blocks_regardless_of_other_factors(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'behavior_coverage' => false,
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains('missing_behavior_coverage', $result['blockers']);
    }

    public function test_missing_rollback_notes_blocks_approval(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'rollback_notes' => '',
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains('missing_rollback_notes', $result['blockers']);
        $this->assertSame('rollback_notes_required', $result['rollback_requirement']);
    }

    public function test_missing_test_coverage_adds_required_test_entry(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'test_coverage' => false,
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains('missing_test_coverage', $result['blockers']);
        $this->assertNotEmpty($result['required_tests']);
    }

    public function test_output_includes_all_five_required_fields(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate());

        $this->assertArrayHasKey('approved', $result);
        $this->assertArrayHasKey('net_reduction_score', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('required_tests', $result);
        $this->assertArrayHasKey('rollback_requirement', $result);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $verifier = new AtlasExternalBrainSimplificationCandidateVerifier;
        $candidate = $this->candidate();

        $this->assertSame($verifier->verify($candidate), $verifier->verify($candidate));
    }

    // ── AC1: behavior_proof_status + consumer_migration_status ───────────────

    public function test_output_includes_behavior_proof_status_and_consumer_migration_status(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate());

        $this->assertArrayHasKey('behavior_proof_status', $result);
        $this->assertArrayHasKey('consumer_migration_status', $result);
        $this->assertSame('proven', $result['behavior_proof_status']);
        $this->assertSame('no_consumers', $result['consumer_migration_status']);
    }

    public function test_behavior_proof_status_missing_when_behavior_coverage_false(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'behavior_coverage' => false,
        ]));

        $this->assertSame('missing', $result['behavior_proof_status']);
    }

    public function test_behavior_proof_status_missing_for_merge_without_preserved_tests(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'kind' => 'merge',
            'preserved_behavior_tests' => [],
        ]));

        $this->assertSame('missing', $result['behavior_proof_status']);
    }

    public function test_consumer_migration_status_migration_planned(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'consumer_count' => 3,
            'migration_plan' => 'swap callers over two releases',
        ]));

        $this->assertSame('migration_planned', $result['consumer_migration_status']);
    }

    public function test_consumer_migration_status_unmigrated_consumers(): void
    {
        $result = (new AtlasExternalBrainSimplificationCandidateVerifier)->verify($this->candidate([
            'consumer_count' => 3,
            'migration_plan' => null,
        ]));

        $this->assertSame('unmigrated_consumers', $result['consumer_migration_status']);
    }
}
