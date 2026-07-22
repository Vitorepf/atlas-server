<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Similarity;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityClusterFact;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityClusterReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityPairFact;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasCortexSymbolSimilarityClusterReporterTest extends TestCase
{
    public function test_it_reports_disconnected_components_deterministically(): void
    {
        $facts = [
            $this->pair('A', 'B', 0.91, 0.30, 2, 0.40),
            $this->pair('A', 'C', 0.88, 0.25, 1, 0.20),
            $this->pair('B', 'C', 0.86, 0.20, 1, 0.20),
            $this->pair('C', 'D', 0.10, 0.10, 0, 0.00),
            $this->pair('D', 'E', 0.92, 0.45, 3, 0.75),
        ];

        $reporter = new AtlasCortexSymbolSimilarityClusterReporter;
        $first = $reporter->report($facts, 0.7, ['token_overlap_ratio']);
        $second = $reporter->report($facts, 0.7, ['token_overlap_ratio']);

        $this->assertCount(2, $first);
        $this->assertSame(
            [['A', 'B', 'C'], ['D', 'E']],
            array_map(static fn (AtlasCortexSymbolSimilarityClusterFact $fact): array => $fact->members, $first),
        );
        $this->assertSame(
            array_map(static fn (AtlasCortexSymbolSimilarityClusterFact $fact): string => $fact->clusterId, $first),
            array_map(static fn (AtlasCortexSymbolSimilarityClusterFact $fact): string => $fact->clusterId, $second),
        );
    }

    public function test_it_keeps_channel_semantics_separate_for_any_vs_all_matching(): void
    {
        $facts = [
            $this->pair('X', 'Y', 0.90, 0.10, 1, 0.50),
        ];

        $reporter = new AtlasCortexSymbolSimilarityClusterReporter;

        $matchAny = $reporter->report($facts, 0.5, ['token_overlap_ratio', 'ast_shape_overlap_ratio'], AtlasCortexSymbolSimilarityClusterReporter::MATCH_ANY);
        $matchAll = $reporter->report($facts, 0.5, ['token_overlap_ratio', 'ast_shape_overlap_ratio'], AtlasCortexSymbolSimilarityClusterReporter::MATCH_ALL);

        $this->assertCount(1, $matchAny);
        $this->assertSame(['X', 'Y'], $matchAny[0]->members);
        $this->assertSame([], $matchAll);
    }

    public function test_it_exposes_three_overlap_channels_without_collapsed_score_fields(): void
    {
        $facts = [
            $this->pair('A', 'B', 0.90, 0.30, 2, 0.40),
            $this->pair('B', 'C', 0.80, 0.50, 3, 0.70),
        ];

        $clusters = (new AtlasCortexSymbolSimilarityClusterReporter)->report($facts, 0.7, ['token_overlap_ratio']);

        $this->assertCount(1, $clusters);
        $cluster = $clusters[0];
        $this->assertSame(['min' => 0.8, 'max' => 0.9, 'mean' => 0.85], $cluster->tokenOverlap);
        $this->assertSame(['min' => 0.3, 'max' => 0.5, 'mean' => 0.4], $cluster->astShapeOverlap);
        $this->assertSame(['min' => 0.4, 'max' => 0.7, 'mean' => 0.55], $cluster->methodNameOverlap);

        $reflection = new ReflectionClass($cluster);
        $propertyNames = array_map(static fn (\ReflectionProperty $property): string => $property->getName(), $reflection->getProperties());
        $this->assertNotContains('clusterScore', $propertyNames);
        $this->assertNotContains('score', $propertyNames);
        $this->assertNotContains('grade', $propertyNames);
        $this->assertArrayHasKey('token_overlap', $cluster->toArray());
        $this->assertArrayHasKey('ast_shape_overlap', $cluster->toArray());
        $this->assertArrayHasKey('method_name_overlap', $cluster->toArray());
    }

    private function pair(string $left, string $right, float $token, float $ast, int $methodCount, float $methodRatio): AtlasCortexSymbolSimilarityPairFact
    {
        return new AtlasCortexSymbolSimilarityPairFact(
            pairA: $left,
            pairB: $right,
            tokenOverlapRatio: $token,
            astShapeOverlapRatio: $ast,
            methodNameOverlapCount: $methodCount,
            methodNameOverlapRatio: $methodRatio,
            fingerprints: ['pair' => sha1($left.'|'.$right)],
        );
    }
}
