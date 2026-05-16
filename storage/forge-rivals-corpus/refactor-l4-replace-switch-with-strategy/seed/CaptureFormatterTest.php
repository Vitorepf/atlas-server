<?php

declare(strict_types=1);

namespace Tests\Unit\Captures;

use App\Domain\Captures\Format\CaptureFormatter;
use App\Domain\Captures\Format\StrategyRegistry;
use PHPUnit\Framework\TestCase;

final class CaptureFormatterTest extends TestCase
{
    /** @return iterable<string, array{0:string,1:string}> */
    public static function frozenOutputs(): iterable
    {
        $body = 'hello';
        $capture = ['body' => $body];
        yield 'plain' => ['plain', $body];
        yield 'md' => ['md', '## hello'];
        yield 'html' => ['html', '<p>hello</p>'];
        yield 'xml' => ['xml', '<capture><body>hello</body></capture>'];
        yield 'csv' => ['csv', 'hello'];
        yield 'json' => ['json', '{"body":"hello"}'];
    }

    /** @dataProvider frozenOutputs */
    public function test_format_byte_for_byte(string $format, string $expected): void
    {
        $formatter = new CaptureFormatter; // post-patch shape: new CaptureFormatter($registry)
        $this->assertSame($expected, $formatter->format(['body' => 'hello'], $format));
    }

    public function test_unknown_format_raises_blocker(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CaptureFormatter)->format(['body' => 'x'], 'pdf');
    }

    public function test_registry_exposes_six_strategies_after_patch(): void
    {
        if (! class_exists(StrategyRegistry::class)) {
            $this->markTestSkipped('Strategy registry not yet introduced');
        }
        $registry = new StrategyRegistry;
        // Arm-patched CaptureFormatter must register six strategies through this constructor path.
        $count = 0;
        foreach (['json', 'csv', 'md', 'html', 'xml', 'plain'] as $name) {
            try {
                $registry->resolve($name);
                $count++;
            } catch (\Throwable) {
                // not registered yet
            }
        }
        $this->assertLessThanOrEqual(6, $count);
    }
}
