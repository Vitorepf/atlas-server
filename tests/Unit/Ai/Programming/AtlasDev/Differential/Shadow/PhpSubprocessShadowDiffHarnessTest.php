<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Differential\Shadow;

use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\PhpSubprocessShadowDiffHarness;
use PHPUnit\Framework\TestCase;

/**
 * E4 -- PhpSubprocessShadowDiffHarness unit tests.
 *
 * Proves the default harness ACTUALLY executes old vs new function bodies in
 * a subprocess and reports divergence / agreement / failure correctly. This
 * is the production substrate; the service-level tests use a fake harness.
 */
final class PhpSubprocessShadowDiffHarnessTest extends TestCase
{
    public function test_real_subprocess_detects_divergence(): void
    {
        $harness = new PhpSubprocessShadowDiffHarness;

        // add vs multiply: divergence on every non-zero input.
        $old = "function shadowdiff_old(int \$x, int \$y): int\n{\nreturn \$x + \$y;\n}";
        $new = "function shadowdiff_new(int \$x, int \$y): int\n{\nreturn \$x * \$y;\n}";
        $result = $harness->shadowDiff($old, $new, [[1, 2], [3, 4]]);

        $this->assertTrue($result->executed, 'add vs mul: subprocess executed');
        $this->assertSame(['3', '7'], $result->oldOutputs, 'old=add');
        $this->assertSame(['2', '12'], $result->newOutputs, 'new=multiply');
        $this->assertNotEquals($result->oldOutputs, $result->newOutputs, 'divergence');
    }

    public function test_real_subprocess_detects_agreement(): void
    {
        $harness = new PhpSubprocessShadowDiffHarness;

        // x+x vs x*2: behavior-preserving.
        $old = "function shadowdiff_old(int \$x): int\n{\nreturn \$x + \$x;\n}";
        $new = "function shadowdiff_new(int \$x): int\n{\nreturn \$x * 2;\n}";
        $result = $harness->shadowDiff($old, $new, [[0], [1], [-1], [42]]);

        $this->assertTrue($result->executed);
        $this->assertSame($result->oldOutputs, $result->newOutputs, 'identical outputs across all inputs');
    }

    public function test_real_subprocess_returns_failure_on_syntax_error(): void
    {
        $harness = new PhpSubprocessShadowDiffHarness;

        $old = "function shadowdiff_old(int \$x): int\n{\nreturn \$x;\n}";
        $new = "function shadowdiff_new(int \$x): int\n{\nreturn \$x // missing semicolon\n}";
        $result = $harness->shadowDiff($old, $new, [[1]]);

        $this->assertFalse($result->executed, 'syntax error => not executed');
        $this->assertNotEmpty($result->error, 'error carries a reason');
        $this->assertSame([], $result->oldOutputs);
        $this->assertSame([], $result->newOutputs);
    }

    public function test_real_subprocess_catches_runtime_throwable(): void
    {
        $harness = new PhpSubprocessShadowDiffHarness;

        // Old throws TypeError on string arg (declared int); the harness
        // records the throw per-input but still returns executed=true (the
        // subprocess itself ran fine).
        $old = "function shadowdiff_old(int \$x): int\n{\nreturn \$x;\n}";
        $new = "function shadowdiff_new(int \$x): int\n{\nreturn \$x;\n}";
        $result = $harness->shadowDiff($old, $new, [['not-an-int']]);

        $this->assertTrue($result->executed, 'subprocess itself ran fine');
        $this->assertStringContainsString('threw', $result->oldOutputs[0], 'recorded the throw per-input');
        // Both threw the same way => "agree" on the throw (honest: the input
        // is not valid for the declared type; a real probe set avoids this).
        $this->assertSame($result->oldOutputs, $result->newOutputs);
    }

    public function test_serialize_return_handles_arrays_and_null(): void
    {
        $this->assertSame('(null)', PhpSubprocessShadowDiffHarness::serializeReturn(null));
        $this->assertSame('42', PhpSubprocessShadowDiffHarness::serializeReturn(42));
        $this->assertSame("'hello'", PhpSubprocessShadowDiffHarness::serializeReturn('hello'));
        $this->assertSame(
            'array[0=>1,1=>2]',
            PhpSubprocessShadowDiffHarness::serializeReturn([1, 2]),
        );
    }
}
