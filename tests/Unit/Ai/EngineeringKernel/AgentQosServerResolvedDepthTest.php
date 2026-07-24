<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\AgentQosExcellenceLaw;
use PHPUnit\Framework\TestCase;

/**
 * P2g-QOS / R106: excellence depth is server-resolved, raise-only (no vanity dial).
 */
final class AgentQosServerResolvedDepthTest extends TestCase
{
    public function test_baseline_for_low_risk_impl(): void
    {
        $depth = AgentQosExcellenceLaw::resolveDepth([
            'risk_class' => 'R1',
            'difficulty_level' => 1,
            'request_class' => AgentQosExcellenceLaw::CLASS_IMPL,
        ]);

        self::assertSame(AgentQosExcellenceLaw::DEPTH_BASELINE, $depth);
    }

    public function test_elevated_for_r4_or_difficulty_4(): void
    {
        self::assertSame(
            AgentQosExcellenceLaw::DEPTH_ELEVATED,
            AgentQosExcellenceLaw::resolveDepth(['risk_class' => 'R4', 'difficulty_level' => 1]),
        );
        self::assertSame(
            AgentQosExcellenceLaw::DEPTH_ELEVATED,
            AgentQosExcellenceLaw::resolveDepth(['risk_class' => 'R1', 'difficulty_level' => 4]),
        );
    }

    public function test_max_for_r5_arch_hard_or_mandate(): void
    {
        self::assertSame(
            AgentQosExcellenceLaw::DEPTH_MAX,
            AgentQosExcellenceLaw::resolveDepth(['risk_class' => 'R5']),
        );
        self::assertSame(
            AgentQosExcellenceLaw::DEPTH_MAX,
            AgentQosExcellenceLaw::resolveDepth([
                'risk_class' => 'R1',
                'request_class' => AgentQosExcellenceLaw::CLASS_ARCH,
            ]),
        );
        self::assertSame(
            AgentQosExcellenceLaw::DEPTH_MAX,
            AgentQosExcellenceLaw::resolveDepth([
                'risk_class' => 'R1',
                'request_class' => AgentQosExcellenceLaw::CLASS_HARD,
            ]),
        );
        self::assertSame(
            AgentQosExcellenceLaw::DEPTH_MAX,
            AgentQosExcellenceLaw::resolveDepth([
                'risk_class' => 'R1',
                'mandate_excellence_depth' => AgentQosExcellenceLaw::DEPTH_MAX,
            ]),
        );
    }

    public function test_raise_only_caller_cannot_lower_server_floor(): void
    {
        $depth = AgentQosExcellenceLaw::resolveDepth([
            'risk_class' => 'R5',
            'caller_requested_depth' => AgentQosExcellenceLaw::DEPTH_BASELINE,
        ]);

        self::assertSame(AgentQosExcellenceLaw::DEPTH_MAX, $depth);
    }

    public function test_caller_may_raise_above_server_floor(): void
    {
        $depth = AgentQosExcellenceLaw::resolveDepth([
            'risk_class' => 'R1',
            'difficulty_level' => 1,
            'caller_requested_depth' => AgentQosExcellenceLaw::DEPTH_MAX,
        ]);

        self::assertSame(AgentQosExcellenceLaw::DEPTH_MAX, $depth);
    }

    public function test_evaluate_exposes_r104_residual_honesty_default_open(): void
    {
        $eval = AgentQosExcellenceLaw::evaluate([
            'risk_class' => 'R1',
            'request_class' => AgentQosExcellenceLaw::CLASS_IMPL,
        ]);

        self::assertSame(AgentQosExcellenceLaw::SCHEMA, $eval['schema']);
        self::assertTrue($eval['accepted']);
        self::assertTrue($eval['raise_only']);
        self::assertTrue($eval['vanity_dial_forbidden']);
        self::assertTrue($eval['r104_residual_open_honesty']);
    }
}
