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

    public function test_duplicated_service_circuit_reports_shared_symbols_methods_responsibility_lines_and_proof(): void
    {
        $result = $this->mapper()->map([
            'organs' => [
                [
                    'organ_id' => 'organ-a',
                    'layer' => 'gate',
                    'purpose_tokens' => ['dedup'],
                    'symbols' => ['DedupIndex', 'DedupPolicy'],
                    'method_names' => ['dedupe', 'evaluate'],
                    'responsibility_tags' => ['queue_admission', 'dedup'],
                    'line_count' => 150,
                    'has_tests' => true,
                ],
                [
                    'organ_id' => 'organ-b',
                    'layer' => 'gate',
                    'purpose_tokens' => ['dedup'],
                    'symbols' => ['DedupIndex', 'DedupCache'],
                    'method_names' => ['dedupe', 'reset'],
                    'responsibility_tags' => ['queue_admission', 'caching'],
                    'line_count' => 90,
                    'has_tests' => false,
                ],
            ],
        ]);

        $cluster = $result['clusters'][0];
        $this->assertSame(['DedupIndex'], $cluster['shared_symbols']);
        $this->assertSame(['dedupe'], $cluster['repeated_method_names']);
        $this->assertSame(['queue_admission'], $cluster['overlapping_responsibility_tags']);
        $this->assertSame(90, $cluster['removable_lines']);
        $this->assertTrue($cluster['proof_ready']);
        $this->assertArrayHasKey('priority_score', $cluster);
    }

    public function test_similar_names_without_shared_responsibility_ranks_lower_than_true_duplicate_circuit(): void
    {
        $trueDuplicate = $this->mapper()->map([
            'organs' => [
                [
                    'organ_id' => 'dup-a',
                    'layer' => 'gate',
                    'purpose_tokens' => ['dedup'],
                    'symbols' => ['DedupIndex'],
                    'method_names' => ['dedupe'],
                    'responsibility_tags' => ['queue_admission'],
                    'line_count' => 150,
                    'has_tests' => true,
                ],
                [
                    'organ_id' => 'dup-b',
                    'layer' => 'gate',
                    'purpose_tokens' => ['dedup'],
                    'symbols' => ['DedupIndex'],
                    'method_names' => ['dedupe'],
                    'responsibility_tags' => ['queue_admission'],
                    'line_count' => 90,
                    'has_tests' => true,
                ],
            ],
        ])['clusters'][0];

        $cosmeticOnly = $this->mapper()->map([
            'organs' => [
                ['organ_id' => 'similar-a', 'layer' => 'gate', 'purpose_tokens' => ['dedup']],
                ['organ_id' => 'similar-b', 'layer' => 'gate', 'purpose_tokens' => ['dedup']],
            ],
        ])['clusters'][0];

        $this->assertGreaterThan($cosmeticOnly['priority_score'], $trueDuplicate['priority_score']);
    }

    public function test_clusters_are_ordered_by_priority_score_then_canonical_circuit_id(): void
    {
        $result = $this->mapper()->map([
            'organs' => [
                ['organ_id' => 'low-a', 'layer' => 'gate', 'purpose_tokens' => ['dedup']],
                ['organ_id' => 'low-b', 'layer' => 'gate', 'purpose_tokens' => ['dedup']],
                [
                    'organ_id' => 'high-a',
                    'layer' => 'planner',
                    'purpose_tokens' => ['route'],
                    'symbols' => ['RouteIndex'],
                    'method_names' => ['route'],
                    'responsibility_tags' => ['routing'],
                ],
                [
                    'organ_id' => 'high-b',
                    'layer' => 'planner',
                    'purpose_tokens' => ['route'],
                    'symbols' => ['RouteIndex'],
                    'method_names' => ['route'],
                    'responsibility_tags' => ['routing'],
                ],
            ],
        ]);

        $this->assertSame(['high-a', 'high-b'], $result['clusters'][0]['members']);
        $this->assertSame(['low-a', 'low-b'], $result['clusters'][1]['members']);
        $this->assertGreaterThanOrEqual($result['clusters'][1]['priority_score'], $result['clusters'][0]['priority_score']);
    }
}
