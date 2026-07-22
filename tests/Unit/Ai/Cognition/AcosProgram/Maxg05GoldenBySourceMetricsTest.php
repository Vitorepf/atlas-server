<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Context\LocalRagGoldenBySourceMetrics;
use Tests\TestCase;

/**
 * MAXG-05 — recall@K por fonte + MRR + nDCG@5 no golden vN.
 *
 * Report-only, gate=false hardcoded no primeiro land. Propriedade pétrea (invariante
 * do plano): nDCG@5 é MONOTÔNICO sob melhora de posição — mover um item relevante
 * para uma posição mais alta jamais pode reduzir o nDCG.
 */
final class Maxg05GoldenBySourceMetricsTest extends TestCase
{
    public function test_gate_hardcoded_false_report_only(): void
    {
        $this->assertFalse(LocalRagGoldenBySourceMetrics::GATE);
        $case = LocalRagGoldenBySourceMetrics::evaluateCase([], []);
        $this->assertFalse($case['gate']);
    }

    public function test_by_source_recall_at_5_partitions_hits_by_source(): void
    {
        $items = [
            ['source' => 'registry', 'ref_hashes' => ['h1']],
            ['source' => 'verbatim', 'ref_hashes' => ['h2']],
            ['source' => 'semantic', 'ref_hashes' => ['h3']],
        ];
        $mustInclude = [
            ['source_ref_type' => 'atlas_memory_entry', 'source_ref_hash' => 'h1'],
        ];

        $case = LocalRagGoldenBySourceMetrics::evaluateCase($items, $mustInclude);

        $this->assertSame(1.0, $case['by_source']['registry']['recall_at_5_hit']);
        $this->assertSame(0.0, $case['by_source']['verbatim']['recall_at_5_hit']);
        $this->assertSame(0.0, $case['by_source']['semantic']['recall_at_5_hit']);
        $this->assertSame(0.0, $case['by_source']['compounding']['recall_at_5_hit']);
        $this->assertSame(1.0, $case['overall']['recall_at_5_hit']);
    }

    public function test_mrr_reflects_first_matched_rank_per_case(): void
    {
        $itemsWithMatchAtRank2 = [
            ['source' => 'registry', 'ref_hashes' => ['noise']],
            ['source' => 'registry', 'ref_hashes' => ['h1']],
            ['source' => 'registry', 'ref_hashes' => ['noise2']],
        ];
        $mustInclude = [
            ['source_ref_type' => 'atlas_memory_entry', 'source_ref_hash' => 'h1'],
        ];
        $case = LocalRagGoldenBySourceMetrics::evaluateCase($itemsWithMatchAtRank2, $mustInclude);
        $this->assertSame(2, $case['overall']['first_match_rank']);

        $agg = LocalRagGoldenBySourceMetrics::aggregate([$case]);
        $this->assertSame(0.5, $agg['overall']['mrr']);
    }

    public function test_ndcg_at_5_monotonic_under_position_improvement(): void
    {
        $mustInclude = [
            ['source_ref_type' => 'atlas_memory_entry', 'source_ref_hash' => 'h1'],
        ];
        $atRank5 = [
            ['source' => 'registry', 'ref_hashes' => ['noise1']],
            ['source' => 'registry', 'ref_hashes' => ['noise2']],
            ['source' => 'registry', 'ref_hashes' => ['noise3']],
            ['source' => 'registry', 'ref_hashes' => ['noise4']],
            ['source' => 'registry', 'ref_hashes' => ['h1']],
        ];
        $atRank1 = [
            ['source' => 'registry', 'ref_hashes' => ['h1']],
            ['source' => 'registry', 'ref_hashes' => ['noise1']],
            ['source' => 'registry', 'ref_hashes' => ['noise2']],
            ['source' => 'registry', 'ref_hashes' => ['noise3']],
            ['source' => 'registry', 'ref_hashes' => ['noise4']],
        ];
        $ndcg5 = LocalRagGoldenBySourceMetrics::evaluateCase($atRank5, $mustInclude)['overall']['ndcg_at_5'];
        $ndcg1 = LocalRagGoldenBySourceMetrics::evaluateCase($atRank1, $mustInclude)['overall']['ndcg_at_5'];

        $this->assertGreaterThanOrEqual($ndcg5, $ndcg1, 'nDCG must not decrease when a relevant item moves up');
        $this->assertSame(1.0, $ndcg1, 'Rank-1 relevant item ⇒ ideal DCG');
    }

    public function test_case_negative_no_match_gives_zero_recall_and_null_first_rank(): void
    {
        $items = [
            ['source' => 'registry', 'ref_hashes' => ['noise1']],
            ['source' => 'verbatim', 'ref_hashes' => ['noise2']],
        ];
        $mustInclude = [
            ['source_ref_type' => 'atlas_memory_entry', 'source_ref_hash' => 'expected'],
        ];
        $case = LocalRagGoldenBySourceMetrics::evaluateCase($items, $mustInclude);

        $this->assertSame(0.0, $case['overall']['recall_at_5_hit']);
        $this->assertNull($case['overall']['first_match_rank']);
        $this->assertSame(0.0, $case['overall']['ndcg_at_5']);
    }

    public function test_aggregate_reports_all_four_canonical_sources(): void
    {
        $agg = LocalRagGoldenBySourceMetrics::aggregate([]);
        $this->assertArrayHasKey('registry', $agg['by_source']);
        $this->assertArrayHasKey('verbatim', $agg['by_source']);
        $this->assertArrayHasKey('semantic', $agg['by_source']);
        $this->assertArrayHasKey('compounding', $agg['by_source']);
        $this->assertSame(0, $agg['overall']['cases']);
    }
}
