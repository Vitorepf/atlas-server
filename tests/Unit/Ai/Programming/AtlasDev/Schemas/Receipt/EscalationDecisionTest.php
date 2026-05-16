<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Receipt;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

final class EscalationDecisionTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_forge_target_with_low_score_and_low_risk_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EscalationDecision::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['x'],
            signals: $this->signals(),
            score: 5,
            riskLevel: 'R2',
            humanActionRequired: true,
        );
    }

    public function test_forge_target_with_high_score_accepted(): void
    {
        $d = EscalationDecision::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['scope_explosion'],
            signals: $this->signals(),
            score: 8,
            riskLevel: 'R2',
            humanActionRequired: true,
            previewArtifactPath: 'storage/preview.json',
        );
        $this->assertContractSurface($d);
    }

    public function test_forge_target_with_high_risk_accepted_even_low_score(): void
    {
        $d = EscalationDecision::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['risk_keyword'],
            signals: $this->signals(),
            score: 3,
            riskLevel: 'R4',
            humanActionRequired: true,
        );
        $this->assertSame('R4', $d->riskLevel);
    }

    public function test_obra_candidate_requires_score_4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EscalationDecision::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_OBRA_CANDIDATE,
            reasons: ['x'],
            signals: $this->signals(),
            score: 3,
            riskLevel: 'R2',
            humanActionRequired: false,
        );
    }

    public function test_forge_target_requires_human_action(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EscalationDecision::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['x'],
            signals: $this->signals(),
            score: 9,
            riskLevel: 'R3',
            humanActionRequired: false,
        );
    }

    public function test_reasons_must_not_be_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EscalationDecision::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_OBRA_CANDIDATE,
            reasons: [],
            signals: $this->signals(),
            score: 5,
            riskLevel: 'R2',
            humanActionRequired: false,
        );
    }

    public function test_round_trip_and_hash_ignores_post_hoc_fields(): void
    {
        $d = EscalationDecision::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['scope_explosion'],
            signals: $this->signals(),
            score: 8,
            riskLevel: 'R2',
            humanActionRequired: true,
        );
        $reviewed = $d->withPostHocReview('atlas', true, '2026-05-17T10:00:00Z');
        $this->assertSame($d->decisionHash, $reviewed->decisionHash);
        $this->assertSame($d->hash(), $reviewed->hash());

        $rebuilt = EscalationDecision::fromArray($reviewed->toCanonicalArray());
        $this->assertSame($reviewed->toCanonicalArray(), $rebuilt->toCanonicalArray());
    }

    private function signals(): EscalationSignals
    {
        return new EscalationSignals(
            fileCount: 7,
            layersTouched: 3,
            riskKeywords: ['security'],
            contextRequiredChars: 8000,
            threadMessages: 2,
            priorFailureCount: 1,
        );
    }
}
