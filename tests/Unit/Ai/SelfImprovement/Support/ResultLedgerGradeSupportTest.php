<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use App\Services\Ai\SelfImprovement\Support\ResultLedgerGradeSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResultLedgerGradeSupportTest extends TestCase
{
    #[Test]
    public function grade_policy_and_trust_maps(): void
    {
        $this->assertSame('invalid', ResultLedgerGradeSupport::evidenceStrength([]));
        $this->assertSame('strong', ResultLedgerGradeSupport::evidenceStrength([
            'evidence_refs' => ['a', 'b', 'c', 'd'],
        ]));

        $grade = ResultLedgerGradeSupport::deriveGrade(
            [
                'hard_regression_detected' => false,
                'recommendation' => AtlasSelfImprovementDeltaScorecardService::RECOMMEND_PROMOTE,
                'normalized_score' => 30.0,
            ],
            ['status' => 'passed'],
            ['status' => 'clear'],
            ['evidence_refs' => ['r1', 'r2', 'r3', 'r4']],
        );
        $this->assertSame(AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT, $grade);
        $this->assertSame(1.0, ResultLedgerGradeSupport::trustDeltaFor($grade));
        $this->assertStringContainsString('major', ResultLedgerGradeSupport::trustOutcomeFor($grade));
        $this->assertStringContainsString('promoting', ResultLedgerGradeSupport::nextActionFor($grade));
        $this->assertSame(0.9, ResultLedgerGradeSupport::learningConfidenceFor($grade));
    }

    #[Test]
    public function invalid_and_regressed_paths(): void
    {
        $this->assertSame(
            AtlasSelfImprovementResultLedgerService::GRADE_INVALID,
            ResultLedgerGradeSupport::deriveGrade([], ['status' => 'passed'], ['status' => 'clear'], []),
        );
        $this->assertSame(
            AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED,
            ResultLedgerGradeSupport::deriveGrade(
                ['hard_regression_detected' => true],
                ['status' => 'passed'],
                ['status' => 'clear'],
                ['evidence_refs' => ['x']],
            ),
        );
    }
}
