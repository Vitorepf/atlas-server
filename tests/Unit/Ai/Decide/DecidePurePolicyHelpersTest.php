<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Decide;

use App\Services\Ai\Decide\DecideProviderNormalization;
use PHPUnit\Framework\TestCase;

final class DecidePurePolicyHelpersTest extends TestCase
{
    public function test_confidence_band_and_quality_gate(): void
    {
        $host = new class
        {
            use DecideProviderNormalization;

            public function band(int $s, ?string $m): string
            {
                return $this->confidenceBand($s, $m);
            }

            public function gate(string $t, bool $p, bool $v): string
            {
                return $this->qualityGateForTask($t, $p, $v);
            }

            public function mode(mixed $v): ?string
            {
                return $this->cleanDecisionMode($v);
            }
        };

        $this->assertSame('manual', $host->band(10, 'codex_cli'));
        $this->assertSame('high', $host->band(90, null));
        $this->assertSame('medium', $host->band(75, null));
        $this->assertSame('low', $host->band(10, null));
        $this->assertSame('tests_or_static_review', $host->gate('chat', true, false));
        $this->assertSame('visual_consistency_review', $host->gate('chat', false, true));
        $this->assertSame('source_grounding_review', $host->gate('research', false, false));
        $this->assertSame('atlas_decide', $host->mode(' atlas_decide '));
        $this->assertNull($host->mode('nope'));
    }
}
