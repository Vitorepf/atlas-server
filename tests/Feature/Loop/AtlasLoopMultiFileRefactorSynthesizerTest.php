<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMultiFileRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraClusterCandidate;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * OPTION 3 · PRODUCE half — frozen proof of the multi-file refactor synthesizer (the autonomous
 * producer of a coherent >=2-file refactor objective). It anchors EVERY covered file on a real
 * convention sibling test, drops unanchored callers, requires the hub sibling, rejects any
 * forbidden-self-target member, and NEVER measures complexity itself (the judge re-measures the
 * aggregated AST drop). Provider-free, mutation-free; the change never auto-merges downstream.
 */
final class AtlasLoopMultiFileRefactorSynthesizerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /**
     * Temp repo with a hub + callers; $withSiblings lists the app files that get a sibling test.
     *
     * @param  list<string>  $appFiles  repo-relative app paths to create
     * @param  list<string>  $withSiblings  subset of $appFiles that get a tests/Unit sibling
     */
    private function repo(array $appFiles, array $withSiblings): string
    {
        $d = sys_get_temp_dir().'/atlas-mfrs-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        foreach ($appFiles as $rel) {
            File::ensureDirectoryExists($d.'/'.dirname($rel));
            $class = pathinfo($rel, PATHINFO_FILENAME);
            File::put($d.'/'.$rel, "<?php\nnamespace App\\X;\nfinal class {$class} { public function go(int \$n): int { return \$n > 0 ? \$n : 0; } }\n");
            if (in_array($rel, $withSiblings, true)) {
                $sibDir = $d.'/tests/Unit/'.dirname(substr($rel, strlen('app/')));
                File::ensureDirectoryExists($sibDir);
                File::put($sibDir.'/'.$class.'Test.php', "<?php\nnamespace Tests\\Unit;\nfinal class {$class}Test { public function t(): void {} }\n");
            }
        }

        return $d;
    }

    private function candidate(string $hub, array $callers): AtlasLoopObraClusterCandidate
    {
        return AtlasLoopObraClusterCandidate::fromHub(
            $hub, $callers,
            ['cyclomatic' => 14, 'cyclomatic_total' => 40, 'refactor_leverage' => 0.8, 'measured_caller_count' => count($callers)],
            ['measured_impact_callers' => count($callers)],
        );
    }

    public function test_builds_a_well_formed_multi_file_payload_when_hub_and_a_caller_are_anchored(): void
    {
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Services/CallerA.php', 'app/Services/CallerB.php'],
            ['app/Services/Hub.php', 'app/Services/CallerA.php'], // CallerB has NO sibling -> dropped
        );
        $cluster = $this->candidate('app/Services/Hub.php', ['app/Services/CallerA.php', 'app/Services/CallerB.php']);

        $out = (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($cluster, $repo);

        $this->assertIsArray($out, 'a hub + >=1 anchored caller yields a multi-file refactor objective');
        $payload = $out['payload'];
        $this->assertTrue((bool) $payload['multi_file']);
        $this->assertSame('refactor_reduce_complexity', $payload['objective_kind']);
        $this->assertSame('framework', $payload['materializer']);
        $this->assertSame(['app/Services/CallerA.php', 'app/Services/Hub.php'], $payload['allowed_files'], 'CallerB (no sibling) is dropped; hub+CallerA remain (>=2)');
        $this->assertCount(2, $payload['frozen_tests'], 'one frozen sibling per covered file');
        $this->assertTrue((bool) $payload['acceptance']['complexity_proof']);
        $this->assertSame(AtlasEvolutionFrozenJudge::METRIC_MINIMIZE, $payload['acceptance']['metric_kind']);
        $this->assertFalse((bool) $payload['acceptance']['revert_recheck']);
        $this->assertStringContainsString('REDUCE complexity', $out['objective']);
        $this->assertNotSame('', $out['acceptance_hash']);
    }

    public function test_null_when_hub_has_no_sibling(): void
    {
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
            ['app/Services/CallerA.php'], // hub has NO sibling
        );
        $cluster = $this->candidate('app/Services/Hub.php', ['app/Services/CallerA.php']);

        $this->assertNull(
            (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($cluster, $repo),
            'the hub MUST have a real behavior anchor (fail-closed)',
        );
    }

    public function test_null_when_fewer_than_two_covered_files_remain(): void
    {
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
            ['app/Services/Hub.php'], // only the hub is anchored -> <2 covered
        );
        $cluster = $this->candidate('app/Services/Hub.php', ['app/Services/CallerA.php']);

        $this->assertNull(
            (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($cluster, $repo),
            'a single anchored file is not a multi-file refactor',
        );
    }

    public function test_null_when_a_cluster_member_is_a_forbidden_self_target(): void
    {
        // A caller path that is a PÉTREO forbidden self-target rejects the whole cluster.
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Models/AtlasLoopProposal.php'],
            ['app/Services/Hub.php', 'app/Models/AtlasLoopProposal.php'],
        );
        $cluster = $this->candidate('app/Services/Hub.php', ['app/Models/AtlasLoopProposal.php']);

        $this->assertNull(
            (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($cluster, $repo),
            'any forbidden self-target member kills the candidate',
        );
    }

    public function test_acceptance_hash_is_stable_for_the_same_cluster(): void
    {
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
        );
        $cluster = $this->candidate('app/Services/Hub.php', ['app/Services/CallerA.php']);
        $syn = new AtlasLoopMultiFileRefactorSynthesizer();

        $a = $syn->synthesizeMultiFileRefactor($cluster, $repo);
        $b = $syn->synthesizeMultiFileRefactor($cluster, $repo);
        $this->assertSame($a['acceptance_hash'], $b['acceptance_hash'], 'deterministic over the same cluster (dedup)');
    }
}
