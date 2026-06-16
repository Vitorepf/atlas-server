<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 7 — censusObraSymbols() replays the assembled obra net diff and refuses it when a new public
 * symbol is not named by the frozen acceptance's test corpus. Driven via a thin protected->public shim
 * against a REAL git obra branch.
 */
final class AtlasLoopObraSymbolCensusWiringTest extends TestCase
{
    private string $repo = '';

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            (new Process(['rm', '-rf', $this->repo]))->run();
        }
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->repo, null, null, 30.0))->mustRun();
    }

    private function buildObraBranch(string $calcBody): array
    {
        $this->repo = sys_get_temp_dir().'/atlas-censusrepo-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/src', 0o755, true);
        @mkdir($this->repo.'/tests', 0o755, true);
        file_put_contents($this->repo.'/src/Calc.php', "<?php\nnamespace App;\nclass Calc { public function alpha(): int { return 1; } }\n");
        file_put_contents($this->repo.'/tests/CalcTest.php', "<?php\n\$c = new App\\Calc(); if (\$c->alpha() !== 1) { exit(1); }\n");
        foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            $this->git($argv);
        }
        $baseHead = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());

        $branch = 'atlas/obra/census-test';
        $this->git(['git', 'checkout', '-q', '-b', $branch]);
        file_put_contents($this->repo.'/src/Calc.php', "<?php\nnamespace App;\nclass Calc {\n{$calcBody}\n}\n");
        $this->git(['git', 'add', '-A']);
        $this->git(['git', 'commit', '-q', '-m', 'obra']);
        $this->git(['git', 'checkout', '-q', $baseHead]);

        return ['branch' => $branch, 'executor_receipt' => ['base_head' => $baseHead]];
    }

    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            /** @return array{ok:bool, reason:?string, census:array<string,mixed>|null} */
            public function census(string $repoRoot, array $envelope, array $acceptance): array
            {
                return $this->censusObraSymbols($repoRoot, $envelope, $acceptance);
            }
        };
    }

    public function test_an_uncovered_new_public_symbol_refuses_the_obra(): void
    {
        // The obra adds a public bravo() the frozen test never names.
        $env = $this->buildObraBranch("  public function alpha(): int { return 1; }\n  public function bravo(): int { return 2; }");

        $out = $this->adapter()->census($this->repo, $env, ['commands' => ['php tests/CalcTest.php']]);

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('bravo', (string) ($out['reason'] ?? ''));
    }

    public function test_a_covered_change_passes(): void
    {
        // Only the already-tested alpha() changes — no new uncovered public symbol.
        $env = $this->buildObraBranch('  public function alpha(): int { return 1 + 0; }');

        $out = $this->adapter()->census($this->repo, $env, ['commands' => ['php tests/CalcTest.php']]);

        $this->assertTrue($out['ok'], 'reason='.(string) ($out['reason'] ?? ''));
    }

    public function test_no_acceptance_commands_degrades_to_pass(): void
    {
        $env = $this->buildObraBranch("  public function alpha(): int { return 1; }\n  public function bravo(): int { return 2; }");

        $out = $this->adapter()->census($this->repo, $env, []);

        $this->assertTrue($out['ok']);
        $this->assertSame('no_acceptance_commands', $out['reason']);
    }
}
