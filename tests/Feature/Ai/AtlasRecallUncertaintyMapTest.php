<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\Memory\AtlasRecallUncertaintyMap;
use Tests\TestCase;

/**
 * T4-S4 (Obra #17) — the recall uncertainty map is COUNTED from the recall result, never
 * fabricated: a clearly-separated top hit is confident; a flat/weak distribution is
 * uncertain; no recall is no_signal. Scale-invariant (keys on relative margin, since
 * recall scores are not normalised to 0..1).
 */
final class AtlasRecallUncertaintyMapTest extends TestCase
{
    public function test_no_recall_is_no_signal(): void
    {
        $map = (new AtlasRecallUncertaintyMap)->forRecall([
            'recall' => [],
            'summary' => ['registry_candidates' => 5],
        ]);

        self::assertSame('no_signal', $map['verdict']);
        self::assertSame(0, $map['recalled']);
        self::assertNull($map['top_score']);
        self::assertSame(0.0, $map['coverage']);
    }

    public function test_a_clearly_separated_top_hit_is_confident_scale_invariant(): void
    {
        // Large, non-0..1 scores (the composer can emit these) — the verdict must key on
        // RELATIVE separation, not an absolute threshold.
        $map = (new AtlasRecallUncertaintyMap)->forRecall([
            'recall' => [
                ['title' => 'A', 'score' => 103.0],
                ['title' => 'B', 'score' => 40.0],
            ],
            'summary' => ['registry_candidates' => 2],
        ]);

        self::assertSame('confident', $map['verdict'], 'a top hit clearly above the rest is confident');
        self::assertSame(103.0, $map['top_score']);
        self::assertGreaterThanOrEqual(0.3, $map['relative_margin']);
        self::assertSame(1.0, $map['coverage']);
    }

    public function test_a_flat_distribution_is_uncertain(): void
    {
        $map = (new AtlasRecallUncertaintyMap)->forRecall([
            'recall' => [
                ['title' => 'A', 'score' => 50.0],
                ['title' => 'B', 'score' => 49.0],
                ['title' => 'C', 'score' => 48.0],
            ],
            'summary' => ['registry_candidates' => 10],
        ]);

        self::assertSame('uncertain', $map['verdict'], 'a near-tie top is a weak signal');
        self::assertLessThan(0.3, $map['relative_margin']);
        // 3 recalled of 10 candidates = 0.3 coverage.
        self::assertSame(0.3, $map['coverage']);
    }

    public function test_a_single_hit_is_confident(): void
    {
        $map = (new AtlasRecallUncertaintyMap)->forRecall([
            'recall' => [['title' => 'only', 'score' => 12.0]],
            'summary' => ['registry_candidates' => 1],
        ]);

        self::assertSame('confident', $map['verdict']);
        self::assertSame(1, $map['recalled']);
    }

    public function test_mcp_recall_tool_response_carries_the_uncertainty_map(): void
    {
        // veto-#20 wiring proof: the MCP atlas_memory_recall tool response now exposes the
        // uncertainty map (even an empty recall yields an honest `no_signal` verdict).
        $resp = app(AtlasOpenBrainMcpService::class)->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_recall',
                'arguments' => ['query' => 'anything at all'],
            ],
        ]);

        $blob = (string) json_encode($resp);
        self::assertStringContainsString('uncertainty', $blob, 'the MCP recall tool must expose the uncertainty map');
        self::assertStringContainsString(AtlasRecallUncertaintyMap::SCHEMA, $blob);
    }
}
