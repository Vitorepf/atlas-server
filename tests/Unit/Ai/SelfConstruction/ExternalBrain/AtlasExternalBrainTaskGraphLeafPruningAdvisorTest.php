<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphLeafPruningAdvisor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskGraphLeafPruningAdvisorTest extends TestCase
{
    public function test_high_queue_pressure_recommends_merge_or_retire_for_duplicate_low_leverage_leaves(): void
    {
        $result = (new AtlasExternalBrainTaskGraphLeafPruningAdvisor)->advise([
            'queue_pressure' => 'high',
            'tasks' => [
                ['task_id' => 't1', 'evidence_value' => 0.2, 'maturity_gap_coverage' => 0.2, 'duplication_risk' => 0.5],
            ],
        ]);

        $this->assertContains($result['recommendations'][0]['recommendation'], ['merge_leaf', 'retire_leaf']);
    }

    public function test_low_queue_pressure_preserves_moderate_leaves_instead_of_premature_retire(): void
    {
        $result = (new AtlasExternalBrainTaskGraphLeafPruningAdvisor)->advise([
            'queue_pressure' => 'low',
            'tasks' => [
                ['task_id' => 't1', 'evidence_value' => 0.3, 'maturity_gap_coverage' => 0.3, 'duplication_risk' => 0.1],
            ],
        ]);

        $this->assertContains($result['recommendations'][0]['recommendation'], ['keep_leaf', 'delay_leaf']);
        $this->assertNotSame('retire_leaf', $result['recommendations'][0]['recommendation']);
    }

    public function test_safety_or_certification_leaves_with_enough_evidence_preserved_regardless_of_queue_pressure(): void
    {
        foreach (['low', 'medium', 'high'] as $pressure) {
            $result = (new AtlasExternalBrainTaskGraphLeafPruningAdvisor)->advise([
                'queue_pressure' => $pressure,
                'tasks' => [
                    [
                        'task_id' => 't1',
                        'is_safety_or_certification' => true,
                        'evidence_value' => 0.9,
                        'duplication_risk' => 0.9,
                    ],
                ],
            ]);

            $this->assertSame('keep_leaf', $result['recommendations'][0]['recommendation'], "expected keep_leaf under {$pressure} pressure");
            $this->assertSame('preserved_high_evidence_safety_or_certification_leaf', $result['recommendations'][0]['reason']);
        }
    }

    public function test_default_pressure_is_medium_and_matches_prior_thresholds(): void
    {
        $result = (new AtlasExternalBrainTaskGraphLeafPruningAdvisor)->advise([
            'tasks' => [
                ['task_id' => 't1', 'evidence_value' => 0.1, 'maturity_gap_coverage' => 0.1],
            ],
        ]);

        $this->assertSame('retire_leaf', $result['recommendations'][0]['recommendation']);
    }
}
