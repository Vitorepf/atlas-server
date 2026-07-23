<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * TRI-HYGIENE W6 — thin smoke. Full surface frozen in Feature golden test.
 * Pre-split unit tumor archived at tests/Archive/AgenticEngineeringOs/.
 */
final class AtlasUniversalGatesEvaluatorTest extends TestCase
{
    public function test_catalogue_has_fifteen_gates(): void
    {
        $gates = new AtlasUniversalGatesEvaluator;
        $cat = $gates->catalogue();
        $this->assertIsArray($cat);
        $this->assertCount(15, $cat);
    }

    public function test_evaluate_returns_schema(): void
    {
        $gates = new AtlasUniversalGatesEvaluator;
        $report = $gates->evaluate('intent-smoke', ['lint_green' => true]);
        $this->assertSame('atlas.aaeos.gate_report.v1', $report['schema']);
        // schema key may vary — assert structure
        $this->assertArrayHasKey('outcome', $report);
        $this->assertArrayHasKey('gate_count', $report);
    }
}
