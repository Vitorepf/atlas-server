<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityClusterReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityPairFact;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasCortexSymbolSimilarityClusterReporterTest extends TestCase
{
    private function reporter(): AtlasCortexSymbolSimilarityClusterReporter
    {
        return new AtlasCortexSymbolSimilarityClusterReporter;
    }

    private function pair(
        string $a,
        string $b,
        float $token = 0.0,
        float $ast   = 0.0,
        float $method = 0.0,
    ): AtlasCortexSymbolSimilarityPairFact {
        return new AtlasCortexSymbolSimilarityPairFact($a, $b, $token, $ast, 0, $method, []);
    }

    // ── AC2: MATCH_ANY / MATCH_ALL policy ────────────────────────────────────

    public function test_match_any_clusters_when_at_least_one_channel_above_threshold(): void
    {
        // token=0.9 (above 0.7), ast=0.1 (below 0.7) → ANY → qualifies
        $pairs = [$this->pair('A', 'B', token: 0.9, ast: 0.1)];

        $clusters = $this->reporter()->report(
            $pairs, 0.7, ['token_overlap_ratio', 'ast_shape_overlap_ratio'], AtlasCortexSymbolSimilarityClusterReporter::MATCH_ANY,
        );

        $this->assertCount(1, $clusters);
    }

    public function test_match_any_skips_when_all_channels_below_threshold(): void
    {
        $pairs = [$this->pair('A', 'B', token: 0.1, ast: 0.1)];

        $clusters = $this->reporter()->report(
            $pairs, 0.7, ['token_overlap_ratio', 'ast_shape_overlap_ratio'], AtlasCortexSymbolSimilarityClusterReporter::MATCH_ANY,
        );

        $this->assertCount(0, $clusters);
    }

    public function test_match_all_requires_every_channel_above_threshold(): void
    {
        // token=0.9 (above), ast=0.1 (below) → ALL → does NOT qualify
        $pairs = [$this->pair('A', 'B', token: 0.9, ast: 0.1)];

        $clusters = $this->reporter()->report(
            $pairs, 0.7, ['token_overlap_ratio', 'ast_shape_overlap_ratio'], AtlasCortexSymbolSimilarityClusterReporter::MATCH_ALL,
        );

        $this->assertCount(0, $clusters);
    }

    public function test_match_all_clusters_when_all_channels_above_threshold(): void
    {
        $pairs = [$this->pair('A', 'B', token: 0.9, ast: 0.8)];

        $clusters = $this->reporter()->report(
            $pairs, 0.7, ['token_overlap_ratio', 'ast_shape_overlap_ratio'], AtlasCortexSymbolSimilarityClusterReporter::MATCH_ALL,
        );

        $this->assertCount(1, $clusters);
    }

    // ── AC3: invalid input fails closed ──────────────────────────────────────

    public function test_unsupported_channel_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reporter()->report([], 0.5, ['nonexistent_channel']);
    }

    public function test_empty_channels_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reporter()->report([], 0.5, []);
    }

    public function test_invalid_match_mode_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reporter()->report([], 0.5, ['token_overlap_ratio'], 'majority');
    }

    // ── AC4: deterministic sorting + channel stats preserved ─────────────────

    public function test_cluster_members_are_sorted_deterministically(): void
    {
        $pairs = [$this->pair('Z', 'A', token: 0.9)];

        $clusters = $this->reporter()->report($pairs, 0.5, ['token_overlap_ratio']);

        $this->assertSame(['A', 'Z'], $clusters[0]->members);
    }

    public function test_multiple_clusters_sorted_by_cluster_id(): void
    {
        // Two disconnected pairs → two clusters
        $pairs = [
            $this->pair('C', 'D', token: 0.9),
            $this->pair('A', 'B', token: 0.9),
        ];

        $clusters = $this->reporter()->report($pairs, 0.5, ['token_overlap_ratio']);

        $this->assertCount(2, $clusters);
        // Must be sorted by clusterId (sha1 of sorted members)
        $ids = array_column($clusters, 'clusterId');
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $ids);
    }

    public function test_channel_stats_preserved_on_cluster(): void
    {
        $pairs = [$this->pair('A', 'B', token: 0.75, ast: 0.60, method: 0.50)];

        $clusters = $this->reporter()->report($pairs, 0.5, ['token_overlap_ratio']);
        $cluster  = $clusters[0];

        // tokenOverlap stats should reflect the pair's token ratio
        $this->assertSame(0.75, $cluster->tokenOverlap['min']);
        $this->assertSame(0.75, $cluster->tokenOverlap['max']);
        $this->assertSame(0.75, $cluster->tokenOverlap['mean']);
    }

    public function test_report_is_deterministic(): void
    {
        $pairs = [
            $this->pair('X', 'Y', token: 0.9),
            $this->pair('Y', 'Z', token: 0.8),
        ];

        $a = $this->reporter()->report($pairs, 0.5, ['token_overlap_ratio']);
        $b = $this->reporter()->report($pairs, 0.5, ['token_overlap_ratio']);

        $this->assertSame(
            array_map(static fn ($c) => $c->clusterId, $a),
            array_map(static fn ($c) => $c->clusterId, $b),
        );
    }
}
