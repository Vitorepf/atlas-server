<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringRouteHandler;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING execution — the route handler end-to-end with a fixture engine: a genuine wiring is
 * certified and its net diff (test + wiring) is captured for review; a cosmetic wiring is rejected with no diff.
 * The base workspace is never mutated (the executor runs in a discarded standalone copy).
 */
final class AtlasLoopOrphanWiringRouteHandlerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_wired_proof' => true]);

        $this->base = sys_get_temp_dir().'/atlas-orphan-route-base-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/app', 0o755, true);
        file_put_contents($this->base.'/app/Orphan.php', "<?php\nclass Orphan { public function contribute(int \$n): int { return \$n * 10; } }\n");
        file_put_contents($this->base.'/app/Consumer.php', "<?php\nclass Consumer { public function total(int \$n): int { return \$n; } }\n");
        // base is a clean git repo (the materializer snapshots its working tree into a standalone copy).
        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'b']);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->base))->run();
    }

    private function authorWiredBehaviorTest(string $ws): array
    {
        file_put_contents(
            $ws.'/tests/wiring_test.php',
            "<?php\nrequire __DIR__.'/../app/Consumer.php';\nif ((new Consumer)->total(2) !== 20) { fwrite(STDERR, 'red'); exit(1); }\necho 'green';\n",
        );

        return ['test_rel' => 'tests/wiring_test.php', 'test_command' => 'php tests/wiring_test.php', 'allowed_globs' => ['app/**']];
    }

    public function test_genuine_wiring_certifies_and_parks_a_real_diff_without_touching_the_base(): void
    {
        $result = (new AtlasLoopOrphanWiringRouteHandler)->handle(
            'app/Orphan.php',
            $this->base,
            $this->makeAuthorTest(),
            $this->makeAuthorWiring(genuine: true),
        );

        $this->assertTrue($result['certified'], (string) $result['reason']);
        $this->assertNotSame('', $result['proposal_diff'], 'a certified wiring parks its net diff for review');
        $this->assertStringContainsString('Orphan', $result['proposal_diff']);
        // the base is untouched (Consumer still unwired) — the executor ran in a discarded standalone copy.
        $this->assertStringContainsString('return $n;', (string) file_get_contents($this->base.'/app/Consumer.php'));
    }

    public function test_cosmetic_wiring_is_rejected_with_no_diff(): void
    {
        $result = (new AtlasLoopOrphanWiringRouteHandler)->handle(
            'app/Orphan.php',
            $this->base,
            $this->makeAuthorTest(),
            $this->makeAuthorWiring(genuine: false),
        );

        $this->assertFalse($result['certified']);
        $this->assertSame('wiring_not_earned', $result['reason']);
        $this->assertSame('', $result['proposal_diff']);
    }

    // The executor invokes the authoring callables with no args, into the standalone workspace it materialized.
    // The fixture engine writes into that workspace by locating the single in-flight atlas-orphan-ws-* temp dir.
    private function makeAuthorTest(): callable
    {
        return function (): array {
            $ws = $this->activeWorkspace();
            @mkdir($ws.'/tests', 0o755, true);

            return $this->authorWiredBehaviorTest($ws);
        };
    }

    private function makeAuthorWiring(bool $genuine): callable
    {
        return function () use ($genuine): void {
            $ws = $this->activeWorkspace();
            $body = $genuine
                ? "<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer { public function total(int \$n): int { return (new Orphan)->contribute(\$n); } }\n"
                : "<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer { public function total(int \$n): int { \$u = new Orphan(); return 20; } }\n";
            file_put_contents($ws.'/app/Consumer.php', $body);
        };
    }

    /** The single standalone workspace the materializer created for this in-flight handle() call. */
    private function activeWorkspace(): string
    {
        $matches = glob(sys_get_temp_dir().'/atlas-orphan-ws-*');
        $dirs = array_values(array_filter((array) $matches, 'is_dir'));
        $this->assertCount(1, $dirs, 'exactly one active standalone workspace during the call');

        return (string) $dirs[0];
    }
}
