<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRedReasonGate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE U2 — proves the red-REASON discriminator separates a genuine BEHAVIORAL red (the test pins the claimed
 * improvement and that assertion fails) from a STRUCTURAL defect (a non-parsing test, a test that never
 * exercises the target, or a wrong require path) that merely exits non-zero. Deterministic, zero-model.
 */
final class AtlasLoopRedReasonGateTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-redreason-'.bin2hex(random_bytes(5));
        @mkdir($this->base.'/tests', 0o755, true);
        // The target: a function whose CURRENT behavior the red test will assert against (2+2 returns 4).
        file_put_contents($this->base.'/Target.php', "<?php\n\nfunction atlas_add(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}\n");
    }

    protected function tearDown(): void
    {
        if ($this->base !== '' && is_dir($this->base)) {
            (new Process(['rm', '-rf', $this->base]))->run();
        }
        parent::tearDown();
    }

    private function writeTest(string $php): void
    {
        file_put_contents($this->base.'/tests/t.php', $php);
    }

    public function test_behavioral_red_is_accepted(): void
    {
        // Requires the target, asserts an IMPROVED behavior the current code does NOT satisfy => exit 1, no
        // structural signature => a genuine behavioral RED.
        $this->writeTest("<?php\nrequire __DIR__.'/../Target.php';\nif (atlas_add(2, 2) === 5) { exit(0); }\nfwrite(STDERR, \"assertion failed: improved behavior not present\\n\");\nexit(1);\n");

        $r = (new AtlasLoopRedReasonGate)->evaluate($this->base, 'tests/t.php', 'Target.php');

        $this->assertTrue($r['is_red'], 'a test that requires the target and fails a behavioral assertion is a real RED');
        $this->assertSame('red_for_behavioral_reason', $r['reason']);
    }

    public function test_non_parsing_test_is_rejected(): void
    {
        $this->writeTest("<?php\nrequire __DIR__.'/../Target.php';\nif (atlas_add(2, 2) { exit(1)\n"); // missing ) and ;

        $r = (new AtlasLoopRedReasonGate)->evaluate($this->base, 'tests/t.php', 'Target.php');

        $this->assertFalse($r['is_red']);
        $this->assertSame('test_does_not_parse', $r['reason']);
    }

    public function test_test_that_never_exercises_the_target_is_rejected(): void
    {
        $this->writeTest("<?php\n// no require/include of the target at all\nexit(1);\n");

        $r = (new AtlasLoopRedReasonGate)->evaluate($this->base, 'tests/t.php', 'Target.php');

        $this->assertFalse($r['is_red']);
        $this->assertSame('test_does_not_exercise_target', $r['reason']);
    }

    public function test_wrong_require_path_is_structural_not_behavioral(): void
    {
        // Mentions Target.php (passes the exercise check) but the path is wrong => failed-require => structural.
        $this->writeTest("<?php\nrequire __DIR__.'/nonexistent/Target.php';\nexit(1);\n");

        $r = (new AtlasLoopRedReasonGate)->evaluate($this->base, 'tests/t.php', 'Target.php');

        $this->assertFalse($r['is_red'], 'a non-zero exit caused by a wrong require path is NOT a behavioral red');
        $this->assertSame('red_for_structural_reason', $r['reason']);
    }

    public function test_green_test_is_not_red(): void
    {
        $this->writeTest("<?php\nrequire __DIR__.'/../Target.php';\nif (atlas_add(2, 2) === 4) { exit(0); }\nexit(1);\n");

        $r = (new AtlasLoopRedReasonGate)->evaluate($this->base, 'tests/t.php', 'Target.php');

        $this->assertFalse($r['is_red']);
        $this->assertSame('test_is_green', $r['reason']);
    }
}
