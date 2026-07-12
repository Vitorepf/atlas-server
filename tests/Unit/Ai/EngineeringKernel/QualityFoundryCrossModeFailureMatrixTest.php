<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundryCrossModeFailureMatrix;
use PHPUnit\Framework\TestCase;

final class QualityFoundryCrossModeFailureMatrixTest extends TestCase
{
    public function test_every_shared_boundary_has_equivalent_safe_terminal_fixture(): void
    {
        $row = [
            'applicability' => 'blocked', 'disposition' => 'block', 'verdict' => 'hold',
            'authorization' => 'refused', 'canary' => 'not_run', 'status' => 'blocked',
            'claim_eligible' => false, 'replay_status' => 'held',
            'provider_invocations_added' => 0, 'unauthorized_effects' => 0,
        ];
        $fixtures = [];
        foreach (QualityFoundryCrossModeFailureMatrix::BOUNDARIES as $boundary) {
            $fixtures[$boundary] = array_fill_keys(['dev', 'forge', 'autonomos'], $row);
        }

        $report = (new QualityFoundryCrossModeFailureMatrix)->evaluate($fixtures);

        self::assertTrue($report['accepted'], json_encode($report));
        self::assertCount(8, $report['boundaries']);
        self::assertSame(8, $report['fixture_count']);
    }

    public function test_one_mode_drift_or_unsafe_effect_blocks_the_whole_matrix(): void
    {
        $row = [
            'applicability' => 'blocked', 'disposition' => 'block', 'verdict' => 'hold',
            'authorization' => 'refused', 'canary' => 'not_run', 'status' => 'blocked',
            'claim_eligible' => false, 'replay_status' => 'held',
            'provider_invocations_added' => 0, 'unauthorized_effects' => 0,
        ];
        $fixtures = [];
        foreach (QualityFoundryCrossModeFailureMatrix::BOUNDARIES as $boundary) {
            $fixtures[$boundary] = array_fill_keys(['dev', 'forge', 'autonomos'], $row);
        }
        $fixtures['provider']['forge']['unauthorized_effects'] = 1;
        $fixtures['process_kill']['autonomos']['provider_invocations_added'] = 1;

        $report = (new QualityFoundryCrossModeFailureMatrix)->evaluate($fixtures);

        self::assertFalse($report['accepted']);
        self::assertContains('unauthorized_effects:forge', $report['failures']['provider']);
        self::assertContains('provider_reinvoked:autonomos', $report['failures']['process_kill']);
    }

    public function test_missing_or_unknown_boundary_is_not_silently_accepted(): void
    {
        $report = (new QualityFoundryCrossModeFailureMatrix)->evaluate([
            'ledger' => [],
            'unknown' => [],
        ]);

        self::assertFalse($report['accepted']);
        self::assertContains('unexpected_boundaries:unknown', $report['failures']['_schema']);
        self::assertContains('modes_missing:dev,forge,autonomos', $report['failures']['ledger']);
    }
}
