<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeToSeedFeedbackReducer;
use Tests\TestCase;

final class AtlasExternalBrainOutcomeToSeedFeedbackReducerTest extends TestCase
{
    private function reducer(): AtlasExternalBrainOutcomeToSeedFeedbackReducer
    {
        return new AtlasExternalBrainOutcomeToSeedFeedbackReducer;
    }

    // ── AC: completed outcomes produce amplify constraints ──

    public function test_completed_outcome_produces_amplify_constraint(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'completed', 'target_family' => 'implementation', 'task_id' => 't1'],
        ]);

        $this->assertSame('amplify', $result['constraints'][0]['constraint']);
        $this->assertSame('implementation', $result['constraints'][0]['target_family']);
        $this->assertSame('proven_high_leverage_vein', $result['constraints'][0]['reason']);
    }

    public function test_success_outcome_produces_amplify_constraint(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'success', 'target_family' => 'testing', 'task_id' => 't2'],
        ]);

        $this->assertSame('amplify', $result['constraints'][0]['constraint']);
    }

    // ── AC: give_back, blocked and quarantined outcomes produce avoid_or_repair constraints ──

    public function test_give_back_outcome_produces_avoid_or_repair_constraint(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'give_back', 'target_family' => 'refactor', 'reason' => 'scope_too_wide', 'task_id' => 't3'],
        ]);

        $this->assertSame('avoid_or_repair', $result['constraints'][0]['constraint']);
        $this->assertSame('refactor', $result['constraints'][0]['target_family']);
        $this->assertSame('scope_too_wide', $result['constraints'][0]['reason']);
    }

    public function test_blocked_outcome_produces_avoid_or_repair_constraint(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'blocked', 'target_family' => 'architecture', 'reason' => 'dependency_missing', 'task_id' => 't4'],
        ]);

        $this->assertSame('avoid_or_repair', $result['constraints'][0]['constraint']);
        $this->assertSame('dependency_missing', $result['constraints'][0]['reason']);
    }

    public function test_quarantined_outcome_produces_avoid_or_repair_constraint(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'quarantined', 'target_family' => 'poison', 'reason' => 'repeated_poison', 'task_id' => 't5'],
        ]);

        $this->assertSame('avoid_or_repair', $result['constraints'][0]['constraint']);
        $this->assertSame('repeated_poison', $result['constraints'][0]['reason']);
    }

    // ── constraints tied to target family and reason ──

    public function test_constraint_includes_target_family_and_reason(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'give_back', 'target_family' => 'frontend', 'reason' => 'worker_mismatch', 'task_id' => 't6'],
        ]);

        $this->assertSame('frontend', $result['constraints'][0]['target_family']);
        $this->assertSame('worker_mismatch', $result['constraints'][0]['reason']);
    }

    // ── deduplication ──

    public function test_duplicate_constraints_deduplicated(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'give_back', 'target_family' => 'refactor', 'reason' => 'scope_too_wide', 'task_id' => 't1'],
            ['result' => 'give_back', 'target_family' => 'refactor', 'reason' => 'scope_too_wide', 'task_id' => 't2'],
        ]);

        $this->assertCount(1, $result['constraints']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->reducer()->reduce([]);

        $this->assertSame(AtlasExternalBrainOutcomeToSeedFeedbackReducer::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('constraints', $result);
        $this->assertArrayHasKey('amplify_constraints', $result);
        $this->assertArrayHasKey('avoid_or_repair_constraints', $result);
        $this->assertArrayHasKey('total_constraints', $result);
    }

    public function test_amplify_and_avoid_constraints_separated(): void
    {
        $result = $this->reducer()->reduce([
            ['result' => 'completed', 'target_family' => 'good', 'task_id' => 't1'],
            ['result' => 'give_back', 'target_family' => 'bad', 'reason' => 'failed', 'task_id' => 't2'],
        ]);

        $this->assertCount(1, $result['amplify_constraints']);
        $this->assertCount(1, $result['avoid_or_repair_constraints']);
        $this->assertSame(2, $result['total_constraints']);
    }

    public function test_result_is_deterministic(): void
    {
        $outcomes = [
            ['result' => 'completed', 'target_family' => 'a', 'task_id' => 't1'],
            ['result' => 'give_back', 'target_family' => 'b', 'reason' => 'x', 'task_id' => 't2'],
        ];

        $a = $this->reducer()->reduce($outcomes);
        $b = $this->reducer()->reduce($outcomes);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
