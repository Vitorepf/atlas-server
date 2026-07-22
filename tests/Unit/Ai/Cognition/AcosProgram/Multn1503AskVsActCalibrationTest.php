<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Operator\AskVsActCalibrationPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1503AskVsActCalibrationTest extends TestCase
{
    #[Test]
    public function clean_low_risk_history_allows_auto_from_require_confirmation(): void
    {
        $out = AskVsActCalibrationPolicy::resolve('require_confirmation', [
            'n' => 12,
            'approvals' => 12,
            'denies' => 0,
            'reverts' => 0,
            'risk' => 'low',
            'class' => 'docs',
        ]);

        $this->assertSame('allow_auto', $out['decision']);
        $this->assertStringStartsWith('calibrated_auto:docs', $out['receipt']);
    }

    #[Test]
    public function one_deny_forces_confirmation_again(): void
    {
        $out = AskVsActCalibrationPolicy::resolve('require_confirmation', [
            'n' => 12,
            'approvals' => 11,
            'denies' => 1,
            'reverts' => 0,
            'risk' => 'low',
            'class' => 'docs',
        ]);

        $this->assertSame('require_confirmation', $out['decision']);
        $this->assertSame('deny_or_revert_seen', $out['basis']);
    }

    #[Test]
    public function policy_never_loosens_block(): void
    {
        $out = AskVsActCalibrationPolicy::resolve('block', [
            'n' => 99,
            'approvals' => 99,
            'denies' => 0,
            'risk' => 'low',
        ]);

        $this->assertSame('block', $out['decision']);
        $this->assertSame('base_decision_not_loosenable', $out['basis']);
    }
}
