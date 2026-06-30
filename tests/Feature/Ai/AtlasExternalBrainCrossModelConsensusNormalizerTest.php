<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossModelConsensusNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCrossModelConsensusNormalizerTest extends TestCase
{
    private function normalizer(): AtlasExternalBrainCrossModelConsensusNormalizer
    {
        return new AtlasExternalBrainCrossModelConsensusNormalizer;
    }

    // ── AC2: duplicate/vague/low_leverage rejected before evidence ranking ────

    public function test_duplicate_proposal_is_rejected(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            ['proposal_id' => 'A', 'is_duplicate' => true, 'evidence_strength' => 0.9],
        ]]);

        $this->assertCount(0, $r['selected_proposals']);
        $this->assertCount(1, $r['rejected_proposals']);
        $this->assertSame(AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_DUPLICATE, $r['rejected_proposals'][0]['rejection_reason']);
    }

    public function test_vague_proposal_is_rejected(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            ['proposal_id' => 'B', 'is_vague' => true, 'evidence_strength' => 0.8],
        ]]);

        $this->assertCount(0, $r['selected_proposals']);
        $this->assertSame(AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_VAGUE, $r['rejected_proposals'][0]['rejection_reason']);
    }

    public function test_low_leverage_proposal_is_rejected(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            ['proposal_id' => 'C', 'leverage_score' => 0.20, 'evidence_strength' => 0.9],
        ]]);

        $this->assertCount(0, $r['selected_proposals']);
        $this->assertSame(AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_LOW_LEVERAGE, $r['rejected_proposals'][0]['rejection_reason']);
    }

    public function test_duplicate_takes_priority_over_vague(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            ['proposal_id' => 'D', 'is_duplicate' => true, 'is_vague' => true, 'leverage_score' => 0.0],
        ]]);

        $this->assertSame(AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_DUPLICATE, $r['rejected_proposals'][0]['rejection_reason']);
    }

    // ── AC3: minority high-evidence beats duplicated low-evidence majority ────

    public function test_minority_high_evidence_beats_duplicated_majority(): void
    {
        // 3 duplicated low-evidence proposals vs 1 minority with high evidence
        $r = $this->normalizer()->normalize(['proposals' => [
            ['proposal_id' => 'maj1', 'is_duplicate' => true,  'evidence_strength' => 0.10, 'leverage_score' => 0.80],
            ['proposal_id' => 'maj2', 'is_duplicate' => true,  'evidence_strength' => 0.10, 'leverage_score' => 0.80],
            ['proposal_id' => 'maj3', 'is_duplicate' => true,  'evidence_strength' => 0.10, 'leverage_score' => 0.80],
            ['proposal_id' => 'min1', 'is_duplicate' => false, 'evidence_strength' => 0.95, 'leverage_score' => 0.80],
        ]]);

        $this->assertCount(1, $r['selected_proposals']);
        $this->assertSame('min1', $r['selected_proposals'][0]['proposal_id']);
        $this->assertCount(3, $r['rejected_proposals']);
    }

    public function test_selected_sorted_by_evidence_desc(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            ['proposal_id' => 'lo', 'evidence_strength' => 0.40, 'leverage_score' => 0.50],
            ['proposal_id' => 'hi', 'evidence_strength' => 0.90, 'leverage_score' => 0.50],
            ['proposal_id' => 'mid', 'evidence_strength' => 0.65, 'leverage_score' => 0.50],
        ]]);

        $ids = array_column($r['selected_proposals'], 'proposal_id');
        $this->assertSame(['hi', 'mid', 'lo'], $ids);
    }

    // ── AC4: high-severity contradictions block both proposals ────────────────

    public function test_high_severity_contradiction_blocks_both_proposals(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            [
                'proposal_id'            => 'X',
                'evidence_strength'      => 0.80,
                'leverage_score'         => 0.70,
                'contradiction_with'     => 'Y',
                'contradiction_severity' => 'high',
            ],
            [
                'proposal_id'            => 'Y',
                'evidence_strength'      => 0.75,
                'leverage_score'         => 0.70,
                'contradiction_with'     => 'X',
                'contradiction_severity' => 'high',
            ],
        ]]);

        $this->assertCount(0, $r['selected_proposals']);
        $this->assertCount(1, $r['disagreements']);
        $this->assertSame(
            AtlasExternalBrainCrossModelConsensusNormalizer::DISAGREEMENT_HIGH_SEVERITY,
            $r['disagreements'][0]['type'],
        );
    }

    public function test_low_severity_contradiction_does_not_block(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            [
                'proposal_id'            => 'P',
                'evidence_strength'      => 0.70,
                'leverage_score'         => 0.60,
                'contradiction_with'     => 'Q',
                'contradiction_severity' => 'low',
            ],
            [
                'proposal_id'        => 'Q',
                'evidence_strength'  => 0.65,
                'leverage_score'     => 0.60,
            ],
        ]]);

        $this->assertCount(2, $r['selected_proposals']);
        $this->assertCount(0, $r['disagreements']);
    }

    public function test_contradiction_pair_only_logged_once(): void
    {
        // Both sides declare contradition_with each other at high severity
        $r = $this->normalizer()->normalize(['proposals' => [
            ['proposal_id' => 'A', 'evidence_strength' => 0.7, 'leverage_score' => 0.6,
             'contradiction_with' => 'B', 'contradiction_severity' => 'high'],
            ['proposal_id' => 'B', 'evidence_strength' => 0.7, 'leverage_score' => 0.6,
             'contradiction_with' => 'A', 'contradiction_severity' => 'high'],
        ]]);

        $this->assertCount(1, $r['disagreements']);
    }

    // ── AC5: output always includes required keys ─────────────────────────────

    public function test_output_always_has_required_keys(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => []]);

        foreach (['selected_proposals', 'rejected_proposals', 'evidence_ranking', 'disagreements', 'merge_notes'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }

    public function test_normalize_is_deterministic(): void
    {
        $input = ['proposals' => [
            ['proposal_id' => 'a', 'evidence_strength' => 0.7, 'leverage_score' => 0.6],
            ['proposal_id' => 'b', 'is_duplicate' => true,     'evidence_strength' => 0.9],
        ]];

        $x = $this->normalizer()->normalize($input);
        $y = $this->normalizer()->normalize($input);

        $this->assertSame(json_encode($x), json_encode($y));
    }
}
