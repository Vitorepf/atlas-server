<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TestRunTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip_passed(): void
    {
        $t = new TestRun(
            command: 'composer test',
            ok: true,
            exitCode: 0,
            durationMs: 1234,
            outputHash: 'h1',
            outputPath: 'storage/atlas-dev/receipts/r1/test.log',
        );
        $rebuilt = TestRun::fromArray($t->toCanonicalArray());
        $this->assertHashStable($t, $rebuilt);
        $this->assertContractSurface($t);
    }

    public function test_ok_true_requires_exit_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TestRun(command: 'c', ok: true, exitCode: 1, durationMs: 0, outputHash: 'h', outputPath: null);
    }

    public function test_negative_duration_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TestRun(command: 'c', ok: false, exitCode: 1, durationMs: -1, outputHash: 'h', outputPath: null);
    }
}
