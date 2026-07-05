<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilOutcomeEvidenceDebater;
use Tests\TestCase;

final class AtlasStrategyCouncilOutcomeEvidenceDebaterTest extends TestCase
{
    private function debater(): AtlasStrategyCouncilOutcomeEvidenceDebater
    {
        return new AtlasStrategyCouncilOutcomeEvidenceDebater;
    }

    // ── AC: stronger evidence wins ──

    public function test_stronger_evidence_wins(): void
    {
        $result = $this->debater()->debate([
            'candidate_a' => ['id' => 'a', 'evidence_score' => 0.9, 'has_evidence' => true],
            'candidate_b' => ['id' => 'b', 'evidence_score' => 0.5, 'has_evidence' => true],
        ]);

        $this->assertSame('winner', $result['verdict']);
        $this->assertSame('a', $result['winner']);
    }

    // ── AC: missing evidence lowers confidence ──

    public function test_missing_evidence_lowers_confidence(): void
    {
        $result = $this->debater()->debate([
            'candidate_a' => ['id' => 'a', 'evidence_score' => 0.9, 'has_evidence' => false],
            'candidate_b' => ['id' => 'b', 'evidence_score' => 0.5, 'has_evidence' => true],
        ]);

        // a has higher raw score but lower confidence (0.3), b has lower score but full confidence.
        // adjusted_a = 0.9 * 0.3 = 0.27, adjusted_b = 0.5 * 1.0 = 0.5 → b wins.
        $this->assertSame('winner', $result['verdict']);
        $this->assertSame('b', $result['winner']);
        $this->assertContains('missing_evidence_lowered_confidence', $result['reasons']);
    }

    // ── AC: ties return requested probe facts ──

    public function test_ties_return_probe_requested(): void
    {
        $result = $this->debater()->debate([
            'candidate_a' => ['id' => 'a', 'evidence_score' => 0.5, 'has_evidence' => true],
            'candidate_b' => ['id' => 'b', 'evidence_score' => 0.5, 'has_evidence' => true],
        ]);

        $this->assertSame('probe_requested', $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertContains('probe_facts_requested', $result['reasons']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->debater()->debate([]);

        $this->assertSame(AtlasStrategyCouncilOutcomeEvidenceDebater::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('winner', $result);
        $this->assertArrayHasKey('reasons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'candidate_a' => ['id' => 'a', 'evidence_score' => 0.8, 'has_evidence' => true],
            'candidate_b' => ['id' => 'b', 'evidence_score' => 0.3, 'has_evidence' => true],
        ];

        $a = $this->debater()->debate($input);
        $b = $this->debater()->debate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
