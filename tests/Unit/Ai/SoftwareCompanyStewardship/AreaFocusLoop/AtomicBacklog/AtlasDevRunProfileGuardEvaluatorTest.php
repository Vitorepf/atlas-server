<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\AtlasDevRunProfileGuardEvaluator;
use PHPUnit\Framework\TestCase;

final class AtlasDevRunProfileGuardEvaluatorTest extends TestCase
{
    private AtlasDevRunProfileGuardEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AtlasDevRunProfileGuardEvaluator();
    }

    public function testScopedProfileWithReceiptPermitsRunOnFastPath(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'scope' => 'scoped',
                'run_enabled' => true,
            ],
            [
                'tier' => 'A1',
                'pre_provider_receipt' => true,
            ],
            [
                'mode' => 'standard',
            ],
        );

        $this->assertSame('atlas.dev.run_profile_guard.v1', $result['schema_version']);
        $this->assertTrue($result['run_allowed']);
        $this->assertFalse($result['escalation_required']);
        $this->assertSame('fast_path', $result['route_decision']);
        $this->assertSame([], $result['blockers']);
        $this->assertFalse($result['packet_required']);
    }

    public function testGlobalRunProfileBlocksRun(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'global' => true,
                'run_enabled' => true,
            ],
            [
                'pre_provider_receipt' => true,
            ],
            [
                'mode' => 'standard',
            ],
        );

        $this->assertFalse($result['run_allowed']);
        $this->assertFalse($result['escalation_required']);
        $this->assertSame('blocked', $result['route_decision']);
        $this->assertSame(['run_profile_not_scoped'], $result['blockers']);
        $this->assertFalse($result['packet_required']);
    }

    public function testMissingPreProviderReceiptBlocksRun(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'scope' => 'scoped',
                'run_enabled' => true,
            ],
            [
                'tier' => 'A1',
            ],
            [
                'mode' => 'standard',
            ],
        );

        $this->assertFalse($result['run_allowed']);
        $this->assertSame('blocked', $result['route_decision']);
        $this->assertSame(['pre_provider_receipt_missing'], $result['blockers']);
        $this->assertFalse($result['packet_required']);
    }

    public function testDisabledRunProfileBlocksRun(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'scope' => 'scoped',
                'run_enabled' => false,
            ],
            [
                'pre_provider_receipt' => true,
            ],
            [
                'mode' => 'standard',
            ],
        );

        $this->assertFalse($result['run_allowed']);
        $this->assertSame('blocked', $result['route_decision']);
        $this->assertSame(['run_profile_disabled'], $result['blockers']);
    }

    public function testPromotionPreviewSetsEscalationWithPacketRequired(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'scope' => 'scoped',
                'run_enabled' => true,
            ],
            [
                'tier' => 'A1',
                'pre_provider_receipt' => true,
            ],
            [
                'mode' => 'promotion_preview',
            ],
        );

        $this->assertTrue($result['run_allowed']);
        $this->assertTrue($result['escalation_required']);
        $this->assertTrue($result['packet_required']);
        $this->assertSame('escalate_promotion_preview', $result['route_decision']);
        $this->assertSame([], $result['blockers']);
    }

    public function testOrdinaryA1JobStaysFastPathWithoutEscalation(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'scope' => 'scoped',
                'run_enabled' => true,
            ],
            [
                'tier' => 'A1',
                'pre_provider_receipt' => true,
            ],
            [
                'mode' => 'standard',
                'promotion_preview' => false,
            ],
        );

        $this->assertTrue($result['run_allowed']);
        $this->assertFalse($result['escalation_required']);
        $this->assertSame('fast_path', $result['route_decision']);
        $this->assertFalse($result['packet_required']);
    }

    public function testMultipleViolationsAccumulateInDeterministicOrder(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'scope' => 'global',
                'run_enabled' => false,
            ],
            [
                'tier' => 'A1',
            ],
            [
                'mode' => 'promotion_preview',
            ],
        );

        $this->assertFalse($result['run_allowed']);
        $this->assertSame('blocked', $result['route_decision']);
        $this->assertSame(
            ['run_profile_not_scoped', 'run_profile_disabled', 'pre_provider_receipt_missing'],
            $result['blockers'],
        );
        $this->assertFalse($result['escalation_required']);
        $this->assertFalse($result['packet_required']);
    }

    public function testPromotionPreviewBlockedWhenRunNotAllowed(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'scope' => 'scoped',
                'run_enabled' => true,
            ],
            [
                'tier' => 'A1',
            ],
            [
                'mode' => 'promotion_preview',
            ],
        );

        $this->assertFalse($result['run_allowed']);
        $this->assertFalse($result['escalation_required']);
        $this->assertFalse($result['packet_required']);
        $this->assertSame('blocked', $result['route_decision']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $profile = [
            'scope' => 'scoped',
            'run_enabled' => true,
        ];
        $job = [
            'tier' => 'A1',
            'pre_provider_receipt' => true,
        ];
        $decision = [
            'mode' => 'promotion_preview',
        ];

        $first = $this->evaluator->evaluate($profile, $job, $decision);
        $second = $this->evaluator->evaluate($profile, $job, $decision);

        $this->assertSame($first, $second);
    }
}
