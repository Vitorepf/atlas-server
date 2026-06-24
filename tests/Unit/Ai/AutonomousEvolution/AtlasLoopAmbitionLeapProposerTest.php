<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAmbitionLeapProposer;
use PHPUnit\Framework\TestCase;

final class AtlasLoopAmbitionLeapProposerTest extends TestCase
{
    public function test_empty_input_returns_empty_output_without_fabrication(): void
    {
        $this->assertSame([], (new AtlasLoopAmbitionLeapProposer)->propose([]));
    }

    public function test_gap_with_insufficient_grounded_evidence_abstain_and_asks(): void
    {
        $leaps = (new AtlasLoopAmbitionLeapProposer)->propose([
            $this->gap(['trend:bucket:1']),
        ]);

        $this->assertCount(1, $leaps);
        $this->assertSame('AmbitionLeap', $leaps[0]['record_type']);
        $this->assertSame('insufficient_grounded_evidence', $leaps[0]['abstain_reason']);
        $this->assertNull($leaps[0]['target_capability_delta']);
        $this->assertSame(['trend:bucket:1'], $leaps[0]['grounded_evidence_refs']);
    }

    public function test_grounded_plateau_gap_yields_big_idea_leap(): void
    {
        $gap = $this->gap([
            'originator:orphan:AtlasLoopAmbitionLeapProposer',
            'pipeline:cycle:1',
            'trend:bucket:1',
        ]);
        $leaps = (new AtlasLoopAmbitionLeapProposer)->propose([$gap]);

        $this->assertCount(1, $leaps);
        $this->assertSame('AmbitionLeap', $leaps[0]['record_type']);
        $this->assertSame($gap['gap_id'], $leaps[0]['gap_id']);
        $this->assertNull($leaps[0]['abstain_reason']);
        $this->assertNotSame('', $leaps[0]['target_capability_delta']);
        $this->assertStringContainsString('Capability multiplier', (string) $leaps[0]['target_capability_delta']);
        $this->assertSame($gap['evidence_refs'], $leaps[0]['grounded_evidence_refs']);
        $this->assertStringStartsWith('ambition_leap:', $leaps[0]['leap_id']);
    }

    public function test_proxy_delta_is_rejected_instead_of_promoted(): void
    {
        $leaps = (new AtlasLoopAmbitionLeapProposer)->propose([
            $this->gap([
                'originator:orphan:AtlasLoopAmbitionLeapProposer',
                'pipeline:cycle:1',
                'trend:bucket:1',
            ], [
                'target_capability_delta' => 'Pure refactor and cyclomatic cleanup of dead code only',
            ]),
        ]);

        $this->assertCount(1, $leaps);
        $this->assertSame('proxy_delta_rejected', $leaps[0]['abstain_reason']);
        $this->assertNull($leaps[0]['target_capability_delta']);
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function gap(array $evidenceRefs, array $overrides = []): array
    {
        return array_merge([
            'record_type' => 'FrontierGap',
            'gap_id' => 'frontier_gap:loop-origination',
            'scope' => 'loop-origination',
            'evidence_refs' => $evidenceRefs,
            'plateau_signal' => true,
            'last_movement_at' => null,
        ], $overrides);
    }
}
