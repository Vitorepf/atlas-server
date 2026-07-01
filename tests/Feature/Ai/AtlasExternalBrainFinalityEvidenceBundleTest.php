<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalityEvidenceBundle;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFinalityEvidenceBundleTest extends TestCase
{
    private function bundle(): AtlasExternalBrainFinalityEvidenceBundle
    {
        return new AtlasExternalBrainFinalityEvidenceBundle;
    }

    private function provenDimension(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'is_proven' => true,
            'is_stale' => false,
            'is_unwired' => false,
            'is_contradicted' => false,
            'is_unintegrated' => false,
            'is_undocumented' => false,
            'is_queue_unsafe' => false,
            'has_outcome_learning' => true,
            'evidence_refs' => ['proof_ref_1'],
        ], $overrides);
    }

    // ── AC2: each blocker category is detected with the exact blocker_type ──

    public function test_missing_dimension_blocks_with_missing_blocker_type(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [],
            'required_dimensions' => ['runtime_wiring'],
        ]);

        $this->assertFalse($result['is_final']);
        $this->assertSame('missing', $result['blockers'][0]['blocker_type']);
    }

    public function test_each_specific_flaw_blocks_with_its_exact_blocker_type(): void
    {
        $flawToBlockerType = [
            'is_contradicted' => 'contradicted',
            'is_stale' => 'stale',
            'is_unwired' => 'unwired',
            'is_unintegrated' => 'unintegrated',
            'is_undocumented' => 'undocumented',
            'is_queue_unsafe' => 'queue_unsafe',
        ];

        foreach ($flawToBlockerType as $flag => $expectedBlockerType) {
            $result = $this->bundle()->assemble([
                'dimensions' => [$this->provenDimension('dim', [$flag => true])],
            ]);

            $this->assertFalse($result['is_final'], "flag: {$flag}");
            $this->assertSame($expectedBlockerType, $result['blockers'][0]['blocker_type'], "flag: {$flag}");
        }
    }

    public function test_no_outcome_learning_blocks_with_that_blocker_type(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [$this->provenDimension('dim', ['has_outcome_learning' => false])],
        ]);

        $this->assertFalse($result['is_final']);
        $this->assertSame('no_outcome_learning', $result['blockers'][0]['blocker_type']);
    }

    public function test_unproven_via_is_proven_false_blocks_with_unproven_blocker_type(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [$this->provenDimension('dim', ['is_proven' => false])],
        ]);

        $this->assertFalse($result['is_final']);
        $this->assertSame('unproven', $result['blockers'][0]['blocker_type']);
    }

    public function test_unproven_via_empty_evidence_refs_blocks_with_unproven_blocker_type(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [$this->provenDimension('dim', ['evidence_refs' => []])],
        ]);

        $this->assertFalse($result['is_final']);
        $this->assertSame('unproven', $result['blockers'][0]['blocker_type']);
    }

    // ── AC3: is_final=true only when every required dimension passes with evidence_refs ──

    public function test_is_final_true_only_when_all_required_dimensions_pass_with_evidence(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [
                $this->provenDimension('wiring'),
                $this->provenDimension('docs'),
            ],
            'required_dimensions' => ['wiring', 'docs'],
        ]);

        $this->assertTrue($result['is_final']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(['wiring', 'docs'], $result['satisfied_dimensions']);
        $this->assertNotEmpty($result['bundle_evidence']);
    }

    public function test_is_final_false_when_one_of_several_required_dimensions_fails(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [
                $this->provenDimension('wiring'),
                $this->provenDimension('docs', ['is_undocumented' => true]),
            ],
            'required_dimensions' => ['wiring', 'docs'],
        ]);

        $this->assertFalse($result['is_final']);
        $this->assertSame(['wiring'], $result['satisfied_dimensions']);
    }

    // ── AC4: readiness_band, readiness_percent, next_highest_leverage_gap, finality_summary ──

    public function test_readiness_metrics_are_deterministic_and_match_blocker_state_when_final(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [$this->provenDimension('a'), $this->provenDimension('b')],
        ]);

        $this->assertSame('final', $result['readiness_band']);
        $this->assertSame(100.0, $result['readiness_percent']);
        $this->assertNull($result['next_highest_leverage_gap']);
        $this->assertStringContainsString('finality declared', $result['finality_summary']);
    }

    public function test_readiness_metrics_reflect_partial_satisfaction_and_name_first_blocker(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [
                $this->provenDimension('a'),
                $this->provenDimension('b', ['is_stale' => true]),
                $this->provenDimension('c', ['is_unwired' => true]),
                $this->provenDimension('d'),
            ],
            'required_dimensions' => ['a', 'b', 'c', 'd'],
        ]);

        $this->assertFalse($result['is_final']);
        $this->assertSame(0.5, $result['finality_score']);
        $this->assertSame(50.0, $result['readiness_percent']);
        $this->assertSame('developing', $result['readiness_band']);
        $this->assertSame('b', $result['next_highest_leverage_gap']);
        $this->assertStringContainsString('2/4 dimensions satisfied', $result['finality_summary']);
        $this->assertStringContainsString('stale', $result['finality_summary']);
        $this->assertStringContainsString('unwired', $result['finality_summary']);
    }

    public function test_incomplete_band_when_score_below_half(): void
    {
        $result = $this->bundle()->assemble([
            'dimensions' => [$this->provenDimension('a')],
            'required_dimensions' => ['a', 'b', 'c', 'd'],
        ]);

        $this->assertSame(0.25, $result['finality_score']);
        $this->assertSame('incomplete', $result['readiness_band']);
    }
}
