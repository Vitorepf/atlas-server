<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Gates;

use App\Services\Ai\Context\Gates\IrrelevantContextRatioGate;
use PHPUnit\Framework\TestCase;

final class IrrelevantContextRatioGateTest extends TestCase
{
    private function gate(): IrrelevantContextRatioGate
    {
        return new IrrelevantContextRatioGate;
    }

    public function test_ratio_within_ceiling_is_clean_and_use_context(): void
    {
        $result = $this->gate()->evaluate(0.03, 0.05);

        $this->assertSame('ready', $result['status']);
        $this->assertSame('clean', $result['severity']);
        $this->assertSame('use_context', $result['recommended_action']);
    }

    public function test_mild_breach_is_warn_and_trim_context(): void
    {
        $result = $this->gate()->evaluate(0.07, 0.05);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('warn', $result['severity']);
        $this->assertSame('trim_context', $result['recommended_action']);
    }

    public function test_large_breach_is_catastrophic_and_rebuild_context_pack(): void
    {
        $result = $this->gate()->evaluate(0.9, 0.05);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('catastrophic', $result['severity']);
        $this->assertSame('rebuild_context_pack', $result['recommended_action']);
    }
}
