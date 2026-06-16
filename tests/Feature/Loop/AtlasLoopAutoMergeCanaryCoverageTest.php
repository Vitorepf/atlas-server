<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE lever #2 — the full-coverage canary. The default canary proved only the FIRST changed file with a
 * sibling (returning on the first match) and let a no-sibling diff through — the literal seam behind the 12
 * canary-RED merges that leaked. Armed, it runs EVERY changed file's sibling and fails CLOSED on any RED.
 */
final class AtlasLoopAutoMergeCanaryCoverageTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            if (is_dir($d)) {
                (new Process(['rm', '-rf', $d]))->run();
            }
        }
        parent::tearDown();
    }

    private function git(string $d, array $argv): void
    {
        (new Process(array_merge(['git', '-C', $d], $argv), null, null, null, 30.0))->mustRun();
    }

    /**
     * A repo with two source files, each with a convention sibling, plus a fake ./vendor/bin/phpunit that
     * exits RED only for BetaTest, and a fake `artisan` that exits GREEN (for the OFF path). Reproduces the
     * leak shape: Alpha (green) is examined first, Beta (red) only if the canary keeps going.
     */
    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-canary-cov-'.bin2hex(random_bytes(4));
        @mkdir($d.'/app', 0o755, true);
        @mkdir($d.'/tests/Unit', 0o755, true);
        @mkdir($d.'/vendor/bin', 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/app/Alpha.php', "<?php\nclass Alpha {}\n");
        file_put_contents($d.'/app/Beta.php', "<?php\nclass Beta {}\n");
        file_put_contents($d.'/tests/Unit/AlphaTest.php', "<?php\n// sibling presence; the fake phpunit decides.\n");
        file_put_contents($d.'/tests/Unit/BetaTest.php', "<?php\n// sibling presence; the fake phpunit decides.\n");
        // The full-coverage canary runs `php ./vendor/bin/phpunit <sibling>`; RED only for BetaTest.
        file_put_contents($d.'/vendor/bin/phpunit', "<?php\nexit(str_contains(\$argv[1] ?? '', 'BetaTest') ? 1 : 0);\n");
        // The OFF path runs `php artisan test <sibling>`; always GREEN here.
        file_put_contents($d.'/artisan', "<?php\nexit(0);\n");
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        return $d;
    }

    /** @return array<string,mixed> */
    private function canary(string $repo, array $changed): array
    {
        $svc = app(AtlasLoopAutoMergeService::class);
        $m = new ReflectionMethod($svc, 'canary');
        $m->setAccessible(true);

        return $m->invoke($svc, $repo, $changed);
    }

    public function test_full_coverage_runs_every_sibling_and_blocks_on_a_later_red(): void
    {
        config(['atlas.ai.loop.canary_full_coverage' => true]);
        $r = $this->canary($this->repo(), ['app/Alpha.php', 'app/Beta.php']);

        $this->assertTrue($r['block'], 'a RED sibling on file #2 blocks — the leak the default canary missed');
        $this->assertSame(false, $r['passed']);
        $this->assertContains('tests/Unit/AlphaTest.php', $r['ran_targets'], 'the green sibling #1 still ran (not short-circuited away)');
        $this->assertContains('tests/Unit/BetaTest.php', $r['ran_targets'], 'and the canary kept going to the red sibling #2');
    }

    public function test_full_coverage_all_green_does_not_block(): void
    {
        config(['atlas.ai.loop.canary_full_coverage' => true]);
        $r = $this->canary($this->repo(), ['app/Alpha.php']); // only the green-sibling file

        $this->assertFalse($r['block']);
        $this->assertTrue($r['passed']);
    }

    public function test_require_coverage_blocks_an_unprovable_source(): void
    {
        config(['atlas.ai.loop.canary_full_coverage' => true, 'atlas.ai.loop.canary_require_coverage' => true]);
        // app/NoSibling.php has no tests/Unit/NoSiblingTest.php => unprovable => blocked when coverage required.
        $repo = $this->repo();
        file_put_contents($repo.'/app/NoSibling.php', "<?php\nclass NoSibling {}\n");
        $r = $this->canary($repo, ['app/NoSibling.php']);

        $this->assertTrue($r['block'], 'require-coverage => an app/**.php source with no sibling cannot merge');
        $this->assertStringStartsWith('uncovered:', (string) $r['target']);
    }

    public function test_off_path_is_byte_identical_first_sibling_only(): void
    {
        config(['atlas.ai.loop.canary_full_coverage' => false]);
        // OFF: the first sibling (Alpha, GREEN via the fake artisan) decides and the canary returns — it
        // never reaches Beta. block=false, exactly today's behaviour.
        $r = $this->canary($this->repo(), ['app/Alpha.php', 'app/Beta.php']);

        $this->assertFalse($r['block']);
        $this->assertTrue($r['passed']);
        $this->assertSame('tests/Unit/AlphaTest.php', $r['target'], 'OFF stops at the first sibling (no Beta)');
        $this->assertArrayNotHasKey('ran_targets', $r, 'OFF returns the legacy shape');
    }
}
