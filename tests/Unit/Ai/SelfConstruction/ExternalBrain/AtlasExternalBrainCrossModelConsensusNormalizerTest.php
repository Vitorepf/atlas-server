<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossModelConsensusNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCrossModelConsensusNormalizerTest extends TestCase
{
    private AtlasExternalBrainCrossModelConsensusNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AtlasExternalBrainCrossModelConsensusNormalizer;
    }

    private function proposal(array $overrides = []): array
    {
        return array_merge([
            'proposal_id'            => 'p-'.uniqid(),
            'objective'              => 'Implement high-value service component.',
            'evidence_strength'      => 0.7,
            'leverage_score'         => 0.8,
            'is_duplicate'           => false,
            'is_vague'               => false,
            'model_source'           => 'model_a',
            'contradiction_with'     => '',
            'contradiction_severity' => '',
        ], $overrides);
    }

    private function input(array ...$proposals): array
    {
        return ['proposals' => $proposals];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal()));

        foreach ([
            'schema', 'selected_proposals', 'rejected_proposals',
            'evidence_ranking', 'disagreements', 'merge_notes',
            'consensus_confidence', 'verification_required', 'verification_reason',
        ] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainCrossModelConsensusNormalizer::SCHEMA, $result['schema']);
    }

    // ── Rejection: duplicate ──────────────────────────────────────────────────

    public function test_duplicate_proposal_is_rejected(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal(['is_duplicate' => true])));

        $this->assertSame([], $result['selected_proposals']);
        $this->assertCount(1, $result['rejected_proposals']);
        $this->assertSame(
            AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_DUPLICATE,
            $result['rejected_proposals'][0]['rejection_reason'],
        );
    }

    // ── Rejection: vague ──────────────────────────────────────────────────────

    public function test_vague_proposal_is_rejected(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal(['is_vague' => true])));

        $this->assertSame([], $result['selected_proposals']);
        $this->assertSame(
            AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_VAGUE,
            $result['rejected_proposals'][0]['rejection_reason'],
        );
    }

    // ── Rejection: low_leverage ───────────────────────────────────────────────

    public function test_low_leverage_proposal_is_rejected(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal(['leverage_score' => 0.1])));

        $this->assertSame([], $result['selected_proposals']);
        $this->assertSame(
            AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_LOW_LEVERAGE,
            $result['rejected_proposals'][0]['rejection_reason'],
        );
    }

    public function test_proposal_at_leverage_threshold_is_not_rejected(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal(['leverage_score' => 0.30])));

        $this->assertCount(1, $result['selected_proposals']);
    }

    // ── Rejection order: duplicate beats vague ────────────────────────────────

    public function test_duplicate_takes_precedence_over_vague(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal([
            'is_duplicate' => true,
            'is_vague'     => true,
        ])));

        $this->assertSame(
            AtlasExternalBrainCrossModelConsensusNormalizer::REJECTION_DUPLICATE,
            $result['rejected_proposals'][0]['rejection_reason'],
        );
    }

    // ── AC2: minority proposal with stronger evidence is selected ─────────────

    public function test_high_evidence_minority_beats_low_evidence_majority(): void
    {
        // 3 majority duplicates + 1 minority with strong evidence
        $strong = $this->proposal(['proposal_id' => 'minority', 'evidence_strength' => 0.95]);
        $dup1   = $this->proposal(['proposal_id' => 'dup1', 'is_duplicate' => true]);
        $dup2   = $this->proposal(['proposal_id' => 'dup2', 'is_duplicate' => true]);
        $dup3   = $this->proposal(['proposal_id' => 'dup3', 'is_duplicate' => true]);

        $result = $this->normalizer->normalize($this->input($strong, $dup1, $dup2, $dup3));

        $selectedIds = array_column($result['selected_proposals'], 'proposal_id');
        $this->assertContains('minority', $selectedIds);
        $this->assertCount(3, $result['rejected_proposals']);
    }

    // ── AC2: evidence ranking is present ─────────────────────────────────────

    public function test_evidence_ranking_is_sorted_by_evidence_desc(): void
    {
        $low  = $this->proposal(['proposal_id' => 'low',  'evidence_strength' => 0.3]);
        $high = $this->proposal(['proposal_id' => 'high', 'evidence_strength' => 0.9]);
        $mid  = $this->proposal(['proposal_id' => 'mid',  'evidence_strength' => 0.6]);

        $result = $this->normalizer->normalize($this->input($low, $high, $mid));

        $this->assertSame(['high', 'mid', 'low'], $result['evidence_ranking']);
    }

    // ── AC3: high-severity contradictions block both proposals ────────────────

    public function test_high_severity_contradiction_surfaces_as_disagreement(): void
    {
        $a = $this->proposal(['proposal_id' => 'a', 'contradiction_with' => 'b', 'contradiction_severity' => 'high']);
        $b = $this->proposal(['proposal_id' => 'b', 'contradiction_with' => 'a', 'contradiction_severity' => 'high']);

        $result = $this->normalizer->normalize($this->input($a, $b));

        $this->assertNotEmpty($result['disagreements']);
        $this->assertSame([], $result['selected_proposals']);
    }

    public function test_disagreement_has_expected_fields(): void
    {
        $a = $this->proposal(['proposal_id' => 'a', 'contradiction_with' => 'b', 'contradiction_severity' => 'high']);
        $b = $this->proposal(['proposal_id' => 'b', 'contradiction_with' => 'a', 'contradiction_severity' => 'high']);

        $result = $this->normalizer->normalize($this->input($a, $b));

        $disagreement = $result['disagreements'][0];
        $this->assertArrayHasKey('type', $disagreement);
        $this->assertArrayHasKey('proposal_a', $disagreement);
        $this->assertArrayHasKey('proposal_b', $disagreement);
        $this->assertSame(
            AtlasExternalBrainCrossModelConsensusNormalizer::DISAGREEMENT_HIGH_SEVERITY,
            $disagreement['type'],
        );
    }

    public function test_low_severity_contradiction_does_not_block_selection(): void
    {
        $a = $this->proposal(['proposal_id' => 'a', 'contradiction_with' => 'b', 'contradiction_severity' => 'low']);
        $b = $this->proposal(['proposal_id' => 'b']);

        $result = $this->normalizer->normalize($this->input($a, $b));

        $this->assertCount(2, $result['selected_proposals']);
        $this->assertSame([], $result['disagreements']);
    }

    public function test_contradiction_not_counted_twice(): void
    {
        $a = $this->proposal(['proposal_id' => 'a', 'contradiction_with' => 'b', 'contradiction_severity' => 'high']);
        $b = $this->proposal(['proposal_id' => 'b', 'contradiction_with' => 'a', 'contradiction_severity' => 'high']);

        $result = $this->normalizer->normalize($this->input($a, $b));

        $this->assertCount(1, $result['disagreements']);
    }

    // ── AC4: merge_notes ─────────────────────────────────────────────────────

    public function test_merge_notes_is_non_empty(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal()));

        $this->assertNotEmpty($result['merge_notes']);
    }

    public function test_merge_notes_mentions_disagreements_when_present(): void
    {
        $a = $this->proposal(['proposal_id' => 'a', 'contradiction_with' => 'b', 'contradiction_severity' => 'high']);
        $b = $this->proposal(['proposal_id' => 'b', 'contradiction_with' => 'a', 'contradiction_severity' => 'high']);

        $result      = $this->normalizer->normalize($this->input($a, $b));
        $notesString = implode(' ', $result['merge_notes']);

        $this->assertStringContainsString('disagreement', strtolower($notesString));
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_proposals_returns_empty_outputs(): void
    {
        $result = $this->normalizer->normalize(['proposals' => []]);

        $this->assertSame([], $result['selected_proposals']);
        $this->assertSame([], $result['rejected_proposals']);
        $this->assertSame([], $result['evidence_ranking']);
        $this->assertSame([], $result['disagreements']);
    }

    // ── AC1: canonical_dimensions ─────────────────────────────────────────────

    public function test_selected_proposal_has_canonical_dimensions_field(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal(['proposal_id' => 'x'])));

        $this->assertArrayHasKey('canonical_dimensions', $result['selected_proposals'][0]);
    }

    public function test_canonical_dimensions_includes_all_six_required_dimensions(): void
    {
        $result = $this->normalizer->normalize($this->input($this->proposal(['proposal_id' => 'x'])));
        $dims   = $result['selected_proposals'][0]['canonical_dimensions'];

        foreach (AtlasExternalBrainCrossModelConsensusNormalizer::CANONICAL_DIMENSIONS as $dim) {
            $this->assertArrayHasKey($dim, $dims, "Missing canonical dimension: {$dim}");
        }
    }

    public function test_missing_dimension_uses_default_value(): void
    {
        // No proxy_risk or novelty supplied → defaults used (0.0 and 0.5 respectively)
        $result = $this->normalizer->normalize($this->input($this->proposal(['proposal_id' => 'x'])));
        $dims   = $result['selected_proposals'][0]['canonical_dimensions'];

        $this->assertSame(0.0, $dims['proxy_risk']);
        $this->assertSame(0.5, $dims['novelty']);
    }

    public function test_canonical_dimensions_values_are_provider_independent_floats(): void
    {
        $p = $this->proposal([
            'proposal_id'    => 'x',
            'model_source'   => 'some_frontier_model_internal_id',
            'implementability' => 0.85,
            'proxy_risk'     => 0.12,
            'novelty'        => 0.70,
            'confidence'     => 0.90,
        ]);
        $result = $this->normalizer->normalize($this->input($p));
        $dims   = $result['selected_proposals'][0]['canonical_dimensions'];

        // Values map 1:1 — no model-specific scaling applied.
        $this->assertEqualsWithDelta(0.85, $dims['implementability'], 0.001);
        $this->assertEqualsWithDelta(0.12, $dims['proxy_risk'],       0.001);
        $this->assertEqualsWithDelta(0.70, $dims['novelty'],          0.001);
        $this->assertEqualsWithDelta(0.90, $dims['confidence'],       0.001);
    }

    // ── AC2: disagreement on proxy_risk or implementability ───────────────────

    public function test_proxy_risk_split_lowers_consensus_confidence(): void
    {
        // Two proposals with very different proxy_risk values → disagreement.
        $p1 = $this->proposal(['proposal_id' => 'p1', 'proxy_risk' => 0.05, 'confidence' => 0.8]);
        $p2 = $this->proposal(['proposal_id' => 'p2', 'proxy_risk' => 0.90, 'confidence' => 0.8]);

        $noSplit  = $this->normalizer->normalize($this->input($p1));           // single proposal
        $withSplit = $this->normalizer->normalize($this->input($p1, $p2));

        $this->assertGreaterThan($withSplit['consensus_confidence'], $noSplit['consensus_confidence']);
    }

    public function test_proxy_risk_disagreement_triggers_verification_required(): void
    {
        $p1 = $this->proposal(['proposal_id' => 'a', 'proxy_risk' => 0.05]);
        $p2 = $this->proposal(['proposal_id' => 'b', 'proxy_risk' => 0.90]);

        $result = $this->normalizer->normalize($this->input($p1, $p2));

        $this->assertTrue($result['verification_required']);
        $this->assertStringContainsString('proxy_risk', (string) $result['verification_reason']);
    }

    public function test_implementability_disagreement_triggers_verification_required(): void
    {
        $p1 = $this->proposal(['proposal_id' => 'a', 'implementability' => 0.10]);
        $p2 = $this->proposal(['proposal_id' => 'b', 'implementability' => 0.95]);

        $result = $this->normalizer->normalize($this->input($p1, $p2));

        $this->assertTrue($result['verification_required']);
        $this->assertStringContainsString('implementability', (string) $result['verification_reason']);
    }

    public function test_merge_notes_mention_verify_before_enqueue_when_disagreement(): void
    {
        $p1 = $this->proposal(['proposal_id' => 'a', 'proxy_risk' => 0.05]);
        $p2 = $this->proposal(['proposal_id' => 'b', 'proxy_risk' => 0.90]);

        $result = $this->normalizer->normalize($this->input($p1, $p2));

        $notesStr = strtolower(implode(' ', $result['merge_notes']));
        $this->assertStringContainsString('verify', $notesStr);
    }

    public function test_no_disagreement_when_critical_dims_agree(): void
    {
        $p1 = $this->proposal(['proposal_id' => 'a', 'proxy_risk' => 0.10, 'implementability' => 0.80]);
        $p2 = $this->proposal(['proposal_id' => 'b', 'proxy_risk' => 0.15, 'implementability' => 0.85]);

        $result = $this->normalizer->normalize($this->input($p1, $p2));

        $this->assertFalse($result['verification_required']);
        $this->assertNull($result['verification_reason']);
    }

    public function test_consensus_confidence_is_high_when_single_proposal(): void
    {
        $p = $this->proposal(['proposal_id' => 'x', 'confidence' => 0.9]);
        $result = $this->normalizer->normalize($this->input($p));

        $this->assertGreaterThan(0.5, $result['consensus_confidence']);
    }
}
