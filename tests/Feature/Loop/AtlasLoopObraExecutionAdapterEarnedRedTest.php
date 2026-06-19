<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\FixtureRefactorObraNodeDelivery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §11.3 EARNED-RED GATE — a behavior-CHANGING obra (objective_kind feature_* / bug_fix) must have a
 * frozen acceptance that is provably RED against the PRE-implementation tree before the executor runs.
 * A green-on-arrival command is a no-op the implementation satisfies by changing nothing, so the obra
 * is REFUSED ('acceptance_not_earned_red') before any obra branch is cut. A command that is RED today
 * clears the bar (the gate passes through to the executor). refactor_* obras are behavior-PRESERVING —
 * their acceptance is a frozen sibling test that must stay GREEN — so they are EXEMPT from the gate.
 */
final class AtlasLoopObraExecutionAdapterEarnedRedTest extends TestCase
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
        (new Process($argv, $this->repo, null, null, 30.0))->run();
    }

    /** A class whose single method has cyclomatic ≈ $ifs + 1. */
    private function klass(string $class, int $ifs): string
    {
        $body = "    public function run(int \$v): string {\n";
        for ($i = 0; $i < $ifs; $i++) {
            $body .= "        if (\$v > {$i}) { return 'b{$i}'; }\n";
        }
        $body .= "        return 'z';\n    }";

        return "<?php\nnamespace App;\nfinal class {$class} {\n{$body}\n}\n";
    }

    /** A 2-file committed cluster repo (same pattern as AtlasLoopObraExecutionAdapterTest). */
    private function buildRepo(): void
    {
        $this->repo = sys_get_temp_dir().'/atlas-obra-earnedred-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/app', 0o755, true);
        file_put_contents($this->repo.'/app/HubA.php', $this->klass('HubA', 12));
        file_put_contents($this->repo.'/app/HubB.php', $this->klass('HubB', 8));
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            $this->git($argv);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Add a new feature X to the HubA/HubB cluster.',
            'allowed_files' => ['app/HubA.php', 'app/HubB.php'],
            'target_relative_path' => 'app/HubA.php',
            'target_repo_path' => $this->repo,
            'cluster_hash' => 'cluster-earnedred-'.bin2hex(random_bytes(3)),
            'acceptance' => ['commands' => ['php -r "exit(0);"']],
        ], $overrides);
    }

    private function fixture(): FixtureRefactorObraNodeDelivery
    {
        return new FixtureRefactorObraNodeDelivery([
            'app/HubA.php' => $this->klass('HubA', 3),
            'app/HubB.php' => $this->klass('HubB', 2),
        ]);
    }

    private function obraBranches(): string
    {
        return trim((new Process(['git', 'branch', '--list', 'atlas/obra/*'], $this->repo))->mustRun()->getOutput());
    }

    /** Case 1 — FIRES: feature_* with a green-on-arrival acceptance is NOT earned-red ⇒ refused, no obra branch. */
    public function test_behavior_changing_obra_with_green_on_arrival_acceptance_is_refused_as_not_earned_red(): void
    {
        $this->buildRepo();

        $result = (new AtlasLoopObraExecutionAdapter())->executeAndProve(
            $this->payload(['objective_kind' => 'feature_x', 'acceptance' => ['commands' => ['php -r "exit(0);"']]]),
            $this->fixture(),
        );

        $reason = (string) $result['reason'];
        $this->assertFalse((bool) $result['ok'], 'a green-on-arrival feature acceptance never earns the RED bar');
        $this->assertStringContainsString('acceptance_not_earned_red', $reason);
        // The gate refused BEFORE the executor — no obra branch was ever cut.
        $this->assertSame('', $this->obraBranches(), 'the obra branch is never created when the earned-RED gate fires');
    }

    /** Case 2 — PASS-THROUGH: feature_* with an acceptance that is RED today clears the gate (reason is something else). */
    public function test_behavior_changing_obra_with_red_acceptance_passes_the_earned_red_gate(): void
    {
        $this->buildRepo();

        $result = (new AtlasLoopObraExecutionAdapter())->executeAndProve(
            $this->payload(['objective_kind' => 'feature_x', 'acceptance' => ['commands' => ['php -r "exit(1);"']]]),
            $this->fixture(),
        );

        // The gate let it through (the command was RED today). The obra still fails downstream (fixture L4-10),
        // but the reason is NOT the earned-RED refusal.
        $this->assertStringNotContainsString('acceptance_not_earned_red', (string) $result['reason'], 'a red-today acceptance must pass the earned-RED gate');
    }

    /** Case 3 — REFACTOR-EXEMPT: refactor_* is behavior-preserving (green acceptance) ⇒ the gate must NOT fire. */
    public function test_refactor_obra_with_green_acceptance_is_exempt_from_the_earned_red_gate(): void
    {
        $this->buildRepo();

        $result = (new AtlasLoopObraExecutionAdapter())->executeAndProve(
            $this->payload(['objective_kind' => 'refactor_complexity', 'acceptance' => ['commands' => ['php -r "exit(0);"']]]),
            $this->fixture(),
        );

        // A legit refactor with a frozen-green sibling test must never be false-rejected by the earned-RED gate.
        $this->assertStringNotContainsString('acceptance_not_earned_red', (string) $result['reason'], 'refactor obras are behavior-preserving and exempt');
    }
}
