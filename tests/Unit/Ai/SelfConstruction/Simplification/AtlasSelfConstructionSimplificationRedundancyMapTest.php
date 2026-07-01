<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationRedundancyMap;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationRedundancyMapTest extends TestCase
{
    private function mapper(): AtlasSelfConstructionSimplificationRedundancyMap
    {
        return new AtlasSelfConstructionSimplificationRedundancyMap;
    }

    public function test_three_matching_organs_are_grouped_as_redundancy_cluster(): void
    {
        $result = $this->mapper()->map([
            'organs' => [
                ['organ_id' => 'organ-a', 'layer' => 'gate', 'purpose_tokens' => ['dedup', 'admission']],
                ['organ_id' => 'organ-b', 'layer' => 'gate', 'purpose_tokens' => ['dedup', 'value']],
                ['organ_id' => 'organ-c', 'layer' => 'gate', 'purpose_tokens' => ['admission', 'value']],
            ],
        ]);

        $this->assertSame(1, $result['cluster_count']);
        $cluster = $result['clusters'][0];
        $this->assertSame('gate', $cluster['layer']);
        $this->assertSame(['organ-a', 'organ-b', 'organ-c'], $cluster['members']);
        $this->assertNotEmpty($cluster['overlap_reasons']);
        $this->assertContains($cluster['consolidation_priority'], [
            AtlasSelfConstructionSimplificationRedundancyMap::PRIORITY_HIGH,
            AtlasSelfConstructionSimplificationRedundancyMap::PRIORITY_MEDIUM,
        ]);
    }

    public function test_unrelated_organs_are_not_grouped(): void
    {
        $result = $this->mapper()->map([
            'organs' => [
                ['organ_id' => 'organ-x', 'layer' => 'gate', 'purpose_tokens' => ['dedup']],
                ['organ_id' => 'organ-y', 'layer' => 'planner', 'purpose_tokens' => ['dedup']],
                ['organ_id' => 'organ-z', 'layer' => 'gate', 'purpose_tokens' => ['ranking']],
            ],
        ]);

        $this->assertSame(0, $result['cluster_count']);
        $this->assertSame([], $result['clusters']);
    }

    public function test_every_cluster_has_required_fields(): void
    {
        $result = $this->mapper()->map([
            'organs' => [
                ['organ_id' => 'a', 'layer' => 'planner', 'purpose_tokens' => ['route']],
                ['organ_id' => 'b', 'layer' => 'planner', 'purpose_tokens' => ['route']],
            ],
        ]);

        $cluster = $result['clusters'][0];
        foreach (['layer', 'members', 'overlap_reasons', 'consolidation_priority'] as $key) {
            $this->assertArrayHasKey($key, $cluster);
        }
    }

    public function test_shared_downstream_consumers_reflected_in_overlap_reasons(): void
    {
        $result = $this->mapper()->map([
            'organs' => [
                ['organ_id' => 'p', 'layer' => 'planner', 'purpose_tokens' => ['route'], 'downstream_consumers' => ['CommandX']],
                ['organ_id' => 'q', 'layer' => 'planner', 'purpose_tokens' => ['route'], 'downstream_consumers' => ['CommandX']],
            ],
        ]);

        $reasons = implode('|', $result['clusters'][0]['overlap_reasons']);
        $this->assertStringContainsString('shared_consumers:CommandX', $reasons);
        $this->assertSame(AtlasSelfConstructionSimplificationRedundancyMap::PRIORITY_HIGH, $result['clusters'][0]['consolidation_priority']);
    }

    public function test_empty_organs_returns_no_clusters(): void
    {
        $result = $this->mapper()->map(['organs' => []]);

        $this->assertSame(0, $result['cluster_count']);
    }

    public function test_output_is_deterministic(): void
    {
        $input = [
            'organs' => [
                ['organ_id' => 'a', 'layer' => 'gate', 'purpose_tokens' => ['dedup']],
                ['organ_id' => 'b', 'layer' => 'gate', 'purpose_tokens' => ['dedup']],
            ],
        ];

        $this->assertSame(
            json_encode($this->mapper()->map($input)),
            json_encode($this->mapper()->map($input)),
        );
    }
}
