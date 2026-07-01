<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierHarvestYieldModel;
use Tests\TestCase;

final class AtlasExternalBrainFrontierHarvestYieldModelTest extends TestCase
{
    public function test_raw_seed_count_alone_never_justifies_continue_when_unique_high_value_count_is_zero(): void
    {
        $result = (new AtlasExternalBrainFrontierHarvestYieldModel)->model([
            'frontiers' => [
                ['frontier_id' => 'f1', 'raw_seed_count' => 10000, 'unique_high_value_count' => 0],
            ],
        ]);

        $decision = $result['results'][0]['decision'];
        $this->assertNotSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CONTINUE, $decision);
        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_STOP_FRONTIER_HARVEST, $decision);
    }

    public function test_forbidden_wall_rate_triggers_change_strategy_before_duplicate_or_continue(): void
    {
        $result = (new AtlasExternalBrainFrontierHarvestYieldModel)->model([
            'frontiers' => [
                [
                    'frontier_id' => 'f1',
                    'raw_seed_count' => 100,
                    'unique_high_value_count' => 50,
                    'duplicate_count' => 60,
                    'forbidden_wall_count' => 40,
                ],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CHANGE_STRATEGY, $result['results'][0]['decision']);
    }

    public function test_duplicate_rate_triggers_consolidate_before_low_yield_stop(): void
    {
        $result = (new AtlasExternalBrainFrontierHarvestYieldModel)->model([
            'frontiers' => [
                [
                    'frontier_id' => 'f1',
                    'raw_seed_count' => 100,
                    'unique_high_value_count' => 5,
                    'duplicate_count' => 60,
                ],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CONSOLIDATE, $result['results'][0]['decision']);
    }

    public function test_continue_requires_marginal_yield_and_expected_batch_value_above_floors_with_evidence(): void
    {
        $result = (new AtlasExternalBrainFrontierHarvestYieldModel)->model([
            'frontiers' => [
                [
                    'frontier_id' => 'f1',
                    'raw_seed_count' => 100,
                    'unique_high_value_count' => 30,
                    'compounding_impact_per_task' => 1.0,
                ],
            ],
        ]);

        $entry = $result['results'][0];
        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CONTINUE, $entry['decision']);
        $this->assertSame(0.3, $entry['marginal_yield']);
        $this->assertSame(30.0, $entry['expected_next_batch_value']);
        $this->assertSame(
            ['raw_seed_count' => 100, 'unique_high_value_count' => 30, 'duplicate_count' => 0, 'forbidden_wall_count' => 0],
            $entry['evidence_counts'],
        );
        $this->assertNotEmpty($entry['reasons']);
    }
}
