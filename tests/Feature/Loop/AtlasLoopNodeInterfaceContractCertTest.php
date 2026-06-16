<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceContract;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 6 (design-judgement ceiling) — certifyNodeInterfaces() replays the assembled obra net diff and
 * proves each contracted file's REAL AST surface honours the HUMAN-frozen interface contract. A plan can
 * superset the boundary-oracle FILES and still draw the wrong abstraction INSIDE them; this refuses that.
 * No contract for the goal => no check (byte-identical). Driven via a thin protected->public shim against a
 * REAL git obra branch.
 */
final class AtlasLoopNodeInterfaceContractCertTest extends TestCase
{
    private string $repo = '';

    private string $dir = '';

    private string $goal = 'Extract a HubHelper from app/Services/Hub.php with a clean inward dependency.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-iface-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        config()->set('atlas.loop.interface_contract_dir', $this->dir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->repo, $this->dir] as $d) {
            if ($d !== '' && is_dir($d)) {
                (new Process(['rm', '-rf', $d]))->run();
            }
        }
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->repo, null, null, 30.0))->mustRun();
    }

    private function freezeContract(): void
    {
        $reader = new AtlasLoopNodeInterfaceContract($this->dir);
        file_put_contents($reader->fixturePath($this->goal), json_encode(['files' => [
            'app/Support/HubHelper.php' => [
                'fqn' => 'App\\Support\\HubHelper',
                'required_public_methods' => ['compute'],
                'implements' => ['App\\Contracts\\Helper'],
                'forbidden_imports' => ['App\\Services\\Hub'], // the helper must NOT depend back on the hub
            ],
        ]]));
    }

    /** Build an obra branch whose net diff creates app/Support/HubHelper.php with the given body. */
    private function buildObraBranch(string $helperSource): array
    {
        $this->repo = sys_get_temp_dir().'/atlas-ifacerepo-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/app/Support', 0o755, true);
        @mkdir($this->repo.'/app/Contracts', 0o755, true);
        @mkdir($this->repo.'/app/Services', 0o755, true);
        file_put_contents($this->repo.'/app/Contracts/Helper.php', "<?php\nnamespace App\\Contracts;\ninterface Helper { public function compute(): int; }\n");
        file_put_contents($this->repo.'/app/Services/Hub.php', "<?php\nnamespace App\\Services;\nclass Hub { public function run(): int { return 1; } }\n");
        foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            $this->git($argv);
        }
        $baseHead = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());

        $branch = 'atlas/obra/iface-test';
        $this->git(['git', 'checkout', '-q', '-b', $branch]);
        file_put_contents($this->repo.'/app/Support/HubHelper.php', $helperSource);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', 'commit', '-q', '-m', 'obra']);
        $this->git(['git', 'checkout', '-q', $baseHead]);

        return ['branch' => $branch, 'executor_receipt' => ['base_head' => $baseHead]];
    }

    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            /** @return array{ok:bool, reason:?string, violations:list<string>} */
            public function ifaceCheck(string $repoRoot, array $envelope, string $goal): array
            {
                return $this->certifyNodeInterfaces($repoRoot, $envelope, $goal);
            }
        };
    }

    public function test_a_clean_extraction_that_honours_the_contract_passes(): void
    {
        $this->freezeContract();
        $env = $this->buildObraBranch(
            "<?php\nnamespace App\\Support;\nuse App\\Contracts\\Helper;\nfinal class HubHelper implements Helper {\n  public function compute(): int { return 42; }\n}\n",
        );

        $out = $this->adapter()->ifaceCheck($this->repo, $env, $this->goal);

        $this->assertTrue($out['ok'], 'reason='.(string) ($out['reason'] ?? '').' violations='.implode(',', $out['violations']));
    }

    public function test_a_wrong_abstraction_inverted_dependency_is_refused(): void
    {
        $this->freezeContract();
        // Supersets the file boundary AND has compute() — but imports the Hub back (inverted dependency),
        // exactly the wrong-abstraction-below-the-named-seam the boundary-oracle alone cannot catch.
        $env = $this->buildObraBranch(
            "<?php\nnamespace App\\Support;\nuse App\\Contracts\\Helper;\nuse App\\Services\\Hub;\nfinal class HubHelper implements Helper {\n  public function compute(): int { return (new Hub)->run(); }\n}\n",
        );

        $out = $this->adapter()->ifaceCheck($this->repo, $env, $this->goal);

        $this->assertFalse($out['ok'], 'a forbidden inward->outward import must refuse');
        $this->assertContains('interface_contract_violation:app/Support/HubHelper.php:forbidden_import:App\\Services\\Hub', $out['violations']);
    }

    public function test_a_missing_required_method_is_refused(): void
    {
        $this->freezeContract();
        $env = $this->buildObraBranch(
            "<?php\nnamespace App\\Support;\nuse App\\Contracts\\Helper;\nfinal class HubHelper implements Helper {\n  public function somethingElse(): int { return 1; }\n}\n",
        );

        $out = $this->adapter()->ifaceCheck($this->repo, $env, $this->goal);

        $this->assertFalse($out['ok']);
        $this->assertContains('interface_contract_violation:app/Support/HubHelper.php:missing_public_method:compute', $out['violations']);
    }

    public function test_no_frozen_contract_for_the_goal_degrades_to_no_check(): void
    {
        // No fixture frozen => certifyNodeInterfaces is a no-op pass (byte-identical to today).
        $env = $this->buildObraBranch(
            "<?php\nnamespace App\\Support;\nfinal class HubHelper {}\n",
        );

        $out = $this->adapter()->ifaceCheck($this->repo, $env, $this->goal);

        $this->assertTrue($out['ok']);
        $this->assertSame('no_interface_contract', $out['reason']);
    }
}
