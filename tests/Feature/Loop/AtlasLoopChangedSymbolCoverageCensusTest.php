<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopChangedSymbolCoverageCensus;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 7 — the changed-public-symbol coverage census: every public method declared in the diff's
 * ADDED lines must be NAMED in the coverage corpus (the source of the test files the frozen acceptance
 * commands run), else 'changed_symbol_uncovered'. Container-string / reflection sites are recorded as an
 * audit signal, never gated. Honest limit: name-reference is necessary, not sufficient.
 */
final class AtlasLoopChangedSymbolCoverageCensusTest extends TestCase
{
    private string $ws = '';

    protected function tearDown(): void
    {
        if ($this->ws !== '' && is_dir($this->ws)) {
            (new Process(['rm', '-rf', $this->ws]))->run();
        }
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->ws, null, null, 30.0))->mustRun();
    }

    /** Build a workspace with a committed baseline + an unstaged change adding the given Calc body. */
    private function workspace(string $calcBody, string $testSrc): void
    {
        $this->ws = sys_get_temp_dir().'/atlas-census-'.bin2hex(random_bytes(5));
        @mkdir($this->ws.'/src', 0o755, true);
        @mkdir($this->ws.'/tests', 0o755, true);
        file_put_contents($this->ws.'/src/Calc.php', "<?php\nnamespace App;\nclass Calc { public function alpha(): int { return 1; } }\n");
        file_put_contents($this->ws.'/tests/CalcTest.php', $testSrc);
        foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'base']] as $argv) {
            $this->git($argv);
        }
        // The unstaged change under test (the "candidate" dirty tree the certifier measures).
        file_put_contents($this->ws.'/src/Calc.php', "<?php\nnamespace App;\nclass Calc {\n{$calcBody}\n}\n");
    }

    private function census(): AtlasLoopChangedSymbolCoverageCensus
    {
        return new AtlasLoopChangedSymbolCoverageCensus;
    }

    public function test_a_new_public_symbol_with_no_covering_test_is_uncovered(): void
    {
        $this->workspace(
            "  public function alpha(): int { return 1; }\n  public function bravo(): int { return 2; }",
            "<?php\n\$c = new App\\Calc(); if (\$c->alpha() !== 1) { exit(1); }\n", // names alpha only
        );

        $out = $this->census()->evaluate($this->ws, ['php tests/CalcTest.php']);

        $this->assertFalse($out['passed']);
        $this->assertContains('src/Calc.php::bravo', $out['uncovered']);
        $this->assertNotContains('src/Calc.php::alpha', $out['uncovered'], 'alpha IS named by the test');
    }

    public function test_a_new_public_symbol_named_by_the_test_is_covered(): void
    {
        $this->workspace(
            "  public function alpha(): int { return 1; }\n  public function bravo(): int { return 2; }",
            "<?php\n\$c = new App\\Calc(); if (\$c->alpha() + \$c->bravo() !== 3) { exit(1); }\n", // names both
        );

        $out = $this->census()->evaluate($this->ws, ['php tests/CalcTest.php']);

        $this->assertTrue($out['passed'], 'uncovered='.implode(',', $out['uncovered']));
    }

    public function test_a_new_private_method_is_not_a_public_coverage_obligation(): void
    {
        $this->workspace(
            "  public function alpha(): int { return 1; }\n  private function helper(): int { return 9; }",
            "<?php\n\$c = new App\\Calc(); if (\$c->alpha() !== 1) { exit(1); }\n",
        );

        $out = $this->census()->evaluate($this->ws, ['php tests/CalcTest.php']);

        $this->assertTrue($out['passed'], 'a private method carries no public-surface obligation');
    }

    public function test_reflection_and_container_sites_are_recorded_as_audit_flags(): void
    {
        $this->workspace(
            "  public function alpha(): int { return 1; }\n  public function dyn() { return app(\\App\\Other::class) ?? (new \\ReflectionClass(self::class)); }",
            "<?php\n\$c = new App\\Calc(); if (\$c->alpha() + \$c->dyn() !== 1) {}\n", // names alpha + dyn
        );

        $out = $this->census()->evaluate($this->ws, ['php tests/CalcTest.php']);

        $this->assertNotEmpty($out['dynamic_dispatch_sites'], 'container/reflection sites are recorded');
        $this->assertTrue(
            (bool) array_filter($out['dynamic_dispatch_sites'], static fn (string $s): bool => str_contains($s, 'Other') || str_contains($s, 'reflection')),
        );
    }
}
