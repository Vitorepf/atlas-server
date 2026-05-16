<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationSignalScorer;
use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationSignalsInput;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use PHPUnit\Framework\TestCase;

final class EscalationDecisionEngineTest extends TestCase
{
    private function engine(): EscalationDecisionEngine
    {
        return new EscalationDecisionEngine(new EscalationSignalScorer());
    }

    public function test_low_score_returns_null(): void
    {
        $decision = $this->engine()->decide(
            $this->input(riskLevel: 'R1', fileCount: 1),
            'r1',
            'tch',
            '2026-05-16T10:00:00Z',
        );

        $this->assertNull($decision);
    }

    public function test_score_4_emits_obra_candidate_without_human_required(): void
    {
        $input = $this->input(
            riskLevel: 'R2',
            fileCount: 6,        // +2
            layersTouched: 3,    // +1
            testCoverageGap: true, // +1
        ); // score = 4

        $decision = $this->engine()->decide(
            $input,
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAtIso: '2026-05-16T10:00:00Z',
        );

        $this->assertNotNull($decision);
        $this->assertSame(EscalationDecision::TARGET_OBRA_CANDIDATE, $decision->target);
        $this->assertSame(4, $decision->score);
        $this->assertFalse($decision->humanActionRequired);
    }

    public function test_score_7_emits_forge_with_human_required(): void
    {
        $input = $this->input(
            riskLevel: 'R3',
            fileCount: 7,           // +2
            layersTouched: 3,       // +1
            sameSignatureTwice: true, // +2
            diffGrew: true,         // +1
            testCoverageGap: true,  // +1
        ); // score = 7

        $decision = $this->engine()->decide($input, 'r1', 'tch', '2026-05-16T10:00:00Z');

        $this->assertNotNull($decision);
        $this->assertSame(EscalationDecision::TARGET_FORGE, $decision->target);
        $this->assertSame(7, $decision->score);
        $this->assertTrue($decision->humanActionRequired);
    }

    public function test_r4_forces_forge_even_with_low_score(): void
    {
        $input = $this->input(riskLevel: 'R4', fileCount: 0);
        // score will be 5 from R4 alone — above threshold anyway, but test
        // the explicit "risk_level_forces_forge" reason path.

        $decision = $this->engine()->decide($input, 'r1', 'tch', '2026-05-16T10:00:00Z');

        $this->assertNotNull($decision);
        $this->assertSame(EscalationDecision::TARGET_FORGE, $decision->target);
        $this->assertTrue($decision->humanActionRequired);
        $this->assertContains('risk_level_r4_forces_forge', $decision->reasons);
    }

    public function test_r5_with_zero_other_signals_still_forge(): void
    {
        $input = $this->input(riskLevel: 'R5');

        $decision = $this->engine()->decide($input, 'r1', 'tch', '2026-05-16T10:00:00Z');

        $this->assertNotNull($decision);
        $this->assertSame(EscalationDecision::TARGET_FORGE, $decision->target);
        $this->assertContains('risk_level_r5_forces_forge', $decision->reasons);
    }

    public function test_loop_signals_appear_in_reasons(): void
    {
        $input = $this->input(
            riskLevel: 'R2',
            fileCount: 6,
            layersTouched: 3,
            testCoverageGap: true,
            loopEscalationSignalDelta: ['same_signature_twice'],
        );

        $decision = $this->engine()->decide($input, 'r1', 'tch', '2026-05-16T10:00:00Z');

        $this->assertNotNull($decision);
        $this->assertContains('repair_loop_signal:same_signature_twice', $decision->reasons);
    }

    public function test_decision_round_trips_through_dto(): void
    {
        $input = $this->input(
            riskLevel: 'R3',
            fileCount: 7,
            layersTouched: 3,
            sameSignatureTwice: true,
            diffGrew: true,
            testCoverageGap: true,
        );

        $decision = $this->engine()->decide(
            $input,
            'r1',
            'tch',
            '2026-05-16T10:00:00Z',
            previewArtifactPath: '/storage/run/r1/preview.json',
        );

        $this->assertNotNull($decision);
        $rebuilt = EscalationDecision::fromArray($decision->toCanonicalArray());
        $this->assertSame($decision->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertSame($decision->decisionHash, $rebuilt->decisionHash);
    }

    public function test_reasons_are_sorted_and_unique(): void
    {
        $input = $this->input(
            riskLevel: 'R2',
            fileCount: 7,
            layersTouched: 3,
            riskKeywords: ['auth', 'pii'],
            testCoverageGap: true,
            loopEscalationSignalDelta: ['same_signature_twice', 'same_signature_twice'],
        );

        $decision = $this->engine()->decide($input, 'r1', 'tch', '2026-05-16T10:00:00Z');

        $this->assertNotNull($decision);
        $sorted = $decision->reasons;
        $expected = $sorted;
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $sorted, 'Reasons must be alphabetically sorted.');
        $this->assertSame(array_values(array_unique($sorted)), $sorted, 'Reasons must be unique.');
    }

    /**
     * @param  list<string>  $riskKeywords
     * @param  list<string>  $loopEscalationSignalDelta
     */
    private function input(
        string $riskLevel = 'R1',
        int $fileCount = 0,
        int $layersTouched = 0,
        array $riskKeywords = [],
        bool $sameSignatureTwice = false,
        bool $diffGrew = false,
        bool $testCoverageGap = false,
        bool $priorFailureInArea = false,
        ?int $contextRequiredChars = null,
        ?int $threadMessages = null,
        int $priorFailureCount = 0,
        array $loopEscalationSignalDelta = [],
    ): EscalationSignalsInput {
        return new EscalationSignalsInput(
            riskLevel: $riskLevel,
            fileCount: $fileCount,
            layersTouched: $layersTouched,
            riskKeywords: $riskKeywords,
            sameSignatureTwice: $sameSignatureTwice,
            diffGrew: $diffGrew,
            testCoverageGap: $testCoverageGap,
            priorFailureInArea: $priorFailureInArea,
            contextRequiredChars: $contextRequiredChars,
            threadMessages: $threadMessages,
            priorFailureCount: $priorFailureCount,
            loopEscalationSignalDelta: $loopEscalationSignalDelta,
        );
    }
}
