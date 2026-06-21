<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringExecutionAdapter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING — the SECOND work type's full pipeline, end-to-end, with the frontier engine as an
 * injected fixture seam (ZERO provider spend). Proves: comprehension-originated orphan → engine authors a
 * wired-behavior test → EARNED-RED + freeze → engine authors the wiring → Guard-4e certification.
 *
 * The cert is AUTHOR-BLIND: a genuine load-bearing wiring certifies; a cosmetic one + a self-graded
 * always-green test are both rejected — regardless that a fixture "engine" wrote them.
 */
final class AtlasLoopOrphanWiringExecutionAdapterTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_wired_proof' => true]);

        $this->ws = sys_get_temp_dir().'/atlas-orphan-exec-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/app', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);

        // BASELINE (committed): Orphan is a real dead capability; Consumer does NOT reference it; NO test yet.
        file_put_contents($this->ws.'/app/Orphan.php', "<?php\nclass Orphan\n{\n    public function contribute(int \$n): int { return \$n * 10; }\n}\n");
        file_put_contents($this->ws.'/app/Consumer.php', "<?php\nclass Consumer\n{\n    public function total(int \$n): int { return \$n; }\n}\n");

        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline']);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->ws]))->run();
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->ws))->run();
    }

    /** Fixture engine: authors a test that asserts the WIRED behavior (total==20) — earned-RED on the baseline. */
    private function authorWiredBehaviorTest(): callable
    {
        return function (): array {
            file_put_contents(
                $this->ws.'/tests/wiring_test.php',
                "<?php\nrequire __DIR__.'/../app/Consumer.php';\nif ((new Consumer)->total(2) !== 20) { fwrite(STDERR, 'red'); exit(1); }\necho 'green';\n",
            );

            return ['test_rel' => 'tests/wiring_test.php', 'test_command' => 'php tests/wiring_test.php', 'allowed_globs' => ['app/**']];
        };
    }

    public function test_genuine_orphan_wiring_certifies_end_to_end(): void
    {
        $adapter = new AtlasLoopOrphanWiringExecutionAdapter;

        $result = $adapter->execute(
            'app/Orphan.php',
            $this->ws,
            $this->authorWiredBehaviorTest(),
            // genuine wiring: Consumer now DELEGATES to the orphan's behavior.
            fn () => file_put_contents(
                $this->ws.'/app/Consumer.php',
                "<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer\n{\n    public function total(int \$n): int { return (new Orphan)->contribute(\$n); }\n}\n",
            ),
        );

        $this->assertTrue($result['certified'], json_encode($result['verdict']['details'] ?? $result));
        $this->assertSame('accepted', $result['reason']);
    }

    public function test_cosmetic_wiring_is_rejected_author_blind(): void
    {
        $adapter = new AtlasLoopOrphanWiringExecutionAdapter;

        $result = $adapter->execute(
            'app/Orphan.php',
            $this->ws,
            $this->authorWiredBehaviorTest(),
            // THE FARM: instantiate the orphan (a caller) but HARDCODE the value — Guard 4e must reject it.
            fn () => file_put_contents(
                $this->ws.'/app/Consumer.php',
                "<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer\n{\n    public function total(int \$n): int { \$u = new Orphan(); return 20; }\n}\n",
            ),
        );

        $this->assertFalse($result['certified'], 'a cosmetic wiring must be rejected even though a fixture engine wrote it');
        $this->assertSame('wiring_not_earned', $result['reason']);
    }

    public function test_a_self_graded_always_green_test_aborts_before_any_wiring(): void
    {
        $adapter = new AtlasLoopOrphanWiringExecutionAdapter;

        $wiringRan = false;
        $result = $adapter->execute(
            'app/Orphan.php',
            $this->ws,
            // self-graded: a test that already passes on the baseline proves no wiring is needed.
            function (): array {
                file_put_contents($this->ws.'/tests/green.php', "<?php\nrequire __DIR__.'/../app/Consumer.php';\nif ((new Consumer)->total(2) !== 2) { exit(1); }\necho 'green';\n");

                return ['test_rel' => 'tests/green.php', 'test_command' => 'php tests/green.php', 'allowed_globs' => ['app/**']];
            },
            function () use (&$wiringRan): void { $wiringRan = true; },
        );

        $this->assertFalse($result['certified']);
        $this->assertSame('test_not_earned_red', $result['reason']);
        $this->assertFalse($wiringRan, 'the wiring is never authored once the test fails earned-RED');
    }
}
