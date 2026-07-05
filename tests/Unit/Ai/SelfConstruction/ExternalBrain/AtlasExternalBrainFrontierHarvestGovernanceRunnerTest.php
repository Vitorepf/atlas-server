<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierHarvestGovernanceRunner;
use Tests\TestCase;

final class AtlasExternalBrainFrontierHarvestGovernanceRunnerTest extends TestCase
{
    private function runner(): AtlasExternalBrainFrontierHarvestGovernanceRunner
    {
        return new AtlasExternalBrainFrontierHarvestGovernanceRunner;
    }

    public function test_low_yield_source_is_deprioritized(): void
    {
        $result = $this->runner()->govern(
            ['frontiers' => [['frontier_id' => 'low', 'raw_seed_count' => 10, 'unique_high_value_count' => 0, 'duplicate_count' => 8]]],
            ['frontier_id' => 'low', 'harvested_patterns' => []],
            ['frontier_id' => 'low', 'before_score' => 0.5, 'after_score' => 0.5],
            ['scope_id' => 'low', 'exhaustion_signals' => []],
        );

        $this->assertSame('deprioritize_low_yield', $result['action']);
        $this->assertFalse($result['should_harvest']);
    }

    public function test_exhausted_scope_escalates_via_ladder(): void
    {
        $result = $this->runner()->govern(
            ['frontiers' => [['frontier_id' => 'exhausted', 'raw_seed_count' => 10, 'unique_high_value_count' => 5]]],
            ['frontier_id' => 'exhausted', 'harvested_patterns' => []],
            ['frontier_id' => 'exhausted', 'before_score' => 0.5, 'after_score' => 0.5],
            [
                'local_findings_per_wave' => 0,
                'quota_remaining' => 5,
                'attempted_fronts_with_evidence' => [
                    'local_grep_bug_hunt',
                    'cross_file_invariant_scan',
                    'design_path_mining',
                    'simplification_candidate_search',
                    'research_to_task_digest',
                ],
            ],
        );

        $this->assertNotEmpty($result['blockers']);
        $this->assertFalse($result['should_harvest']);
    }

    public function test_high_yield_source_harvests_and_distills(): void
    {
        $result = $this->runner()->govern(
            ['frontiers' => [['frontier_id' => 'high', 'raw_seed_count' => 10, 'unique_high_value_count' => 8, 'duplicate_count' => 1]]],
            ['frontier_id' => 'high', 'harvested_patterns' => ['pattern_a']],
            ['frontier_id' => 'high', 'before_score' => 0.5, 'after_score' => 0.7],
            ['scope_id' => 'high', 'exhaustion_signals' => []],
        );

        $this->assertSame('harvest_and_distill', $result['action']);
        $this->assertTrue($result['should_harvest']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->runner()->govern([], [], [], []);

        foreach (['schema', 'action', 'should_harvest', 'yield', 'distillation', 'benchmark', 'escalation', 'blockers'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }
}
