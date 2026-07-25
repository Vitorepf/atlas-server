<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorComprehensionGateSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorComprehensionGateSupportTest extends TestCase
{
    #[Test]
    public function quote_is_grounded_requires_contiguous_folded_substring(): void
    {
        $this->assertTrue(OperatorComprehensionGateSupport::quoteIsGrounded(
            'Prefiro respostas curtas',
            'Eu disse: Prefiro respostas curtas, por favor.',
            10,
        ));
        $this->assertFalse(OperatorComprehensionGateSupport::quoteIsGrounded(
            'curtas',
            'Eu prefiro respostas longas',
            10,
        ));
    }

    #[Test]
    public function is_hedged_detects_uncertainty_markers(): void
    {
        $this->assertTrue(OperatorComprehensionGateSupport::isHedged('eu acho que gosto', ['eu acho', 'talvez']));
        $this->assertFalse(OperatorComprehensionGateSupport::isHedged('eu prefiro curto', ['eu acho', 'talvez']));
    }
}
