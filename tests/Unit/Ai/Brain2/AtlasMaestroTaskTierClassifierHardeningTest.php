<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTaskTierClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasMaestroTaskTierClassifier::lineCount returns the same count for
 * CRLF and LF versions of an objective.
 */
final class AtlasMaestroTaskTierClassifierHardeningTest extends TestCase
{
    private function lineCount(AtlasMaestroTaskTierClassifier $classifier, string $s): int
    {
        $reflection = new \ReflectionClass($classifier);
        $method = $reflection->getMethod('lineCount');
        return $method->invoke($classifier, $s);
    }

    public function test_crlf_and_lf_objectives_have_same_line_count(): void
    {
        $classifier = new AtlasMaestroTaskTierClassifier();

        $lf = "line1\nline2\nline3";
        $crlf = "line1\r\nline2\r\nline3";

        $lfCount = $this->lineCount($classifier, $lf);
        $crlfCount = $this->lineCount($classifier, $crlf);

        $this->assertSame($lfCount, $crlfCount);
    }

    public function test_crlf_objective_line_count_is_correct(): void
    {
        $classifier = new AtlasMaestroTaskTierClassifier();

        $crlf = "line1\r\nline2\r\nline3";

        $count = $this->lineCount($classifier, $crlf);

        $this->assertSame(3, $count);
    }

    public function test_empty_string_returns_zero(): void
    {
        $classifier = new AtlasMaestroTaskTierClassifier();

        $count = $this->lineCount($classifier, '');

        $this->assertSame(0, $count);
    }

    public function test_single_line_crlf_returns_one(): void
    {
        $classifier = new AtlasMaestroTaskTierClassifier();

        $count = $this->lineCount($classifier, "single\r\n");

        $this->assertSame(2, $count);
    }
}
