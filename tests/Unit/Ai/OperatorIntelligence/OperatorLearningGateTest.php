<?php

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorLearningGate;
use Tests\TestCase;

final class OperatorLearningGateTest extends TestCase
{
    public function test_low_risk_high_confidence_signal_can_be_auto_apply_eligible(): void
    {
        config(['atlas_operator_intelligence.min_auto_apply_confidence' => 0.85]);

        $gate = app(OperatorLearningGate::class)->evaluate([
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.95,
            'scope_type' => 'global',
        ]);

        $this->assertFalse($gate['requires_confirmation']);
        $this->assertTrue($gate['auto_apply_eligible']);
        $this->assertSame('candidate', $gate['status']);
    }

    public function test_private_or_low_confidence_signal_requires_review(): void
    {
        $gate = app(OperatorLearningGate::class)->evaluate([
            'privacy_class' => 'private',
            'risk_level' => 'low',
            'confidence' => 0.6,
            'scope_type' => 'global',
        ]);

        $this->assertTrue($gate['requires_confirmation']);
        $this->assertFalse($gate['auto_apply_eligible']);
        $this->assertSame('needs_review', $gate['status']);
        $this->assertContains('privacy_requires_review:private', $gate['gate_receipt']['reasons']);
    }
}
