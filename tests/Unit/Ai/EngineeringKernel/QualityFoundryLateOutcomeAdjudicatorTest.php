<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryLateOutcomeAdjudicator;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;
use PHPUnit\Framework\TestCase;

final class QualityFoundryLateOutcomeAdjudicatorTest extends TestCase
{
    public function test_late_adverse_outcome_holds_learning_requests_rivals_review_and_revokes_only_through_authority(): void
    {
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        // learning.hold.requested + rivals.claim.evaluation.requested + canonical claim.revoked
        $ledger->expects(self::exactly(3))->method('record');
        $claim = ['claim_id' => 'claim-1', 'status' => 'issued', 'claim_eligible' => true];
        $result = (new QualityFoundryLateOutcomeAdjudicator(new RivalsClaimAuthority))->adjudicate([
            'projection_hash' => hash('sha256', 'projection'),
            'windows' => [
                '24h' => ['state' => 'observed', 'observations' => [['status' => 'regressed']]],
                '7d' => ['state' => 'unknown', 'observations' => []],
            ],
        ], $claim, $ledger);

        self::assertSame('held', $result['status']);
        self::assertTrue($result['learning_hold']);
        self::assertTrue($result['rivals_evaluation']);
        self::assertSame('revoked', $result['claim']['status']);
        self::assertFalse($result['claim']['claim_eligible']);
        self::assertFalse($result['claim_mutated_directly']);
    }

    public function test_healthy_or_unknown_windows_do_not_trigger_hold(): void
    {
        $claims = new RivalsClaimAuthority;
        $result = (new QualityFoundryLateOutcomeAdjudicator($claims))->adjudicate([
            'windows' => [
                '0h' => ['state' => 'observed', 'observations' => [['status' => 'healthy']]],
                '24h' => ['state' => 'unknown', 'observations' => []],
            ],
        ]);

        self::assertSame('no_action', $result['status']);
        self::assertFalse($result['learning_hold']);
        self::assertFalse($result['rivals_evaluation']);
    }
}
