<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationSignalScorer;
use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationSignalsInput;
use PHPUnit\Framework\TestCase;

final class EscalationSignalScorerTest extends TestCase
{
    public function test_quiet_signals_produce_zero_score(): void
    {
        $scorer = new EscalationSignalScorer();
        $input = $this->input(riskLevel: 'R1');

        $result = $scorer->score($input);

        $this->assertSame(0, $result['score']);
        $this->assertSame([], $result['contributions']);
    }

    public function test_risk_r4_alone_contributes_5(): void
    {
        $scorer = new EscalationSignalScorer();
        $result = $scorer->score($this->input(riskLevel: 'R4'));
        $this->assertSame(5, $result['score']);
        $this->assertArrayHasKey('risk_r4_or_r5', $result['contributions']);
    }

    public function test_high_file_count_contributes_2(): void
    {
        $scorer = new EscalationSignalScorer();
        $result = $scorer->score($this->input(riskLevel: 'R2', fileCount: 7));
        $this->assertSame(2, $result['score']);
        $this->assertArrayHasKey('file_count_gt_5', $result['contributions']);
    }

    public function test_medium_file_count_contributes_1(): void
    {
        $scorer = new EscalationSignalScorer();
        $result = $scorer->score($this->input(riskLevel: 'R2', fileCount: 4));
        $this->assertSame(1, $result['score']);
        $this->assertArrayHasKey('file_count_gt_3', $result['contributions']);
    }

    public function test_same_signature_twice_contributes_2(): void
    {
        $scorer = new EscalationSignalScorer();
        $result = $scorer->score($this->input(riskLevel: 'R2', sameSignatureTwice: true));
        $this->assertSame(2, $result['score']);
    }

    public function test_risk_keywords_cap_at_2(): void
    {
        $scorer = new EscalationSignalScorer();
        $result = $scorer->score($this->input(
            riskLevel: 'R2',
            riskKeywords: ['auth', 'billing', 'pii', 'migration'],
        ));
        $this->assertSame(2, $result['score']);
        $this->assertSame(2, $result['contributions']['risk_keywords']);
    }

    public function test_score_is_clamped_to_10(): void
    {
        $scorer = new EscalationSignalScorer();
        $result = $scorer->score($this->input(
            riskLevel: 'R5',
            fileCount: 10,
            layersTouched: 5,
            riskKeywords: ['auth', 'billing'],
            sameSignatureTwice: true,
            diffGrew: true,
            testCoverageGap: true,
            priorFailureInArea: true,
            contextRequiredChars: 50_000,
            threadMessages: 40,
            priorFailureCount: 3,
        ));

        $this->assertSame(EscalationSignalScorer::MAX_SCORE, $result['score']);
    }

    public function test_threshold_pass_to_forge_at_score_7(): void
    {
        $scorer = new EscalationSignalScorer();
        // 5 + 2 = 7
        $result = $scorer->score($this->input(
            riskLevel: 'R4',
            fileCount: 7,
        ));
        $this->assertSame(7, $result['score']);
    }

    public function test_threshold_obra_at_score_4(): void
    {
        $scorer = new EscalationSignalScorer();
        // 2 (file>5) + 1 (layers>=3) + 1 (test_coverage_gap) = 4
        $result = $scorer->score($this->input(
            riskLevel: 'R2',
            fileCount: 6,
            layersTouched: 3,
            testCoverageGap: true,
        ));
        $this->assertSame(4, $result['score']);
    }

    public function test_score_is_deterministic_across_runs(): void
    {
        $scorer = new EscalationSignalScorer();
        $input = $this->input(
            riskLevel: 'R3',
            fileCount: 5,
            layersTouched: 3,
            riskKeywords: ['auth'],
            diffGrew: true,
        );

        $a = $scorer->score($input);
        $b = $scorer->score($input);

        $this->assertSame($a, $b);
    }

    private function input(
        string $riskLevel = 'R0',
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
        );
    }
}
