<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningGateSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorLearningGateSupportTest extends TestCase
{
    #[Test]
    public function low_confidence_requires_review(): void
    {
        $result = OperatorLearningGateSupport::evaluate(
            [
                'privacy_class' => 'normal',
                'risk_level' => 'low',
                'confidence' => 0.4,
                'scope_type' => 'session',
                'taxonomy_item_id' => 'OP-071',
                'metadata' => ['auto_apply_provenance' => 'comprehension'],
            ],
            true,
            ['high_stakes' => false, 'privacy_default' => 'normal'],
            ['comprehension', 'manual_operator'],
            0.85,
            true,
        );

        $this->assertTrue($result['requires_confirmation']);
        $this->assertFalse($result['auto_apply_eligible']);
        $this->assertContains('low_confidence_requires_review', $result['gate_receipt']['reasons']);
    }

    #[Test]
    public function taxonomy_unavailable_fails_closed(): void
    {
        $result = OperatorLearningGateSupport::evaluate(
            [
                'privacy_class' => 'normal',
                'risk_level' => 'low',
                'confidence' => 0.95,
                'scope_type' => 'session',
                'taxonomy_item_id' => 'OP-071',
                'metadata' => ['auto_apply_provenance' => 'comprehension'],
            ],
            false,
            null,
            ['comprehension'],
            0.85,
            true,
        );

        $this->assertTrue($result['requires_confirmation']);
        $this->assertContains('taxonomy_unavailable_fail_closed', $result['gate_receipt']['reasons']);
    }
}
