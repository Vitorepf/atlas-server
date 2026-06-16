<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\FixtureRefactorObraNodeDelivery;
use App\Services\Ai\Obra\AtlasObraExecutor;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ITEM8 — the PLANNING PHASE ahead of best-of-N (default-OFF). When ARMED and the task qualifies, the
 * adapter compiles the natural-language objective into a falsifiable spec (AtlasLoopIntentSpecCompiler),
 * then decomposes it into a readiness-gated DAG (AtlasLoopObraDecompositionPlanner -> the REAL
 * AtlasLoopPlanReadinessGate) with a CREATE-CLASS node at seq 0 for any NEW file + redirect-caller nodes
 * at higher seq — fixing the class-not-found root (the executor walks by seq ascending).
 *
 * The full machinery is exercised with ZERO provider spend by overriding the two PROTECTED provider seams
 * (generateSpecViaProvider / generatePlanViaProvider) with fake-but-ready returns. With the flag OFF,
 * maybePlan() short-circuits before any new code runs => buildPlan() runs exactly as today (byte-identical).
 */
final class AtlasLoopObraExecutionPlanningTest extends TestCase
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

    /** A 2-file committed cluster repo (mirrors the frozen adapter test). */
    private function buildRepo(): void
    {
        $this->repo = sys_get_temp_dir().'/atlas-obra-plan-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/app', 0o755, true);
        file_put_contents($this->repo.'/app/HubA.php', $this->klass('HubA', 12));
        file_put_contents($this->repo.'/app/HubB.php', $this->klass('HubB', 8));
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            $this->git($argv);
        }
    }

    private function payload(): array
    {
        return [
            'objective' => 'Reduce the cyclomatic complexity of the HubA/HubB cluster, preserving behaviour.',
            'allowed_files' => ['app/HubA.php', 'app/HubB.php'],
            'target_relative_path' => 'app/HubA.php',
            'target_repo_path' => $this->repo,
            'cluster_hash' => 'cluster-plan-'.bin2hex(random_bytes(3)),
            'acceptance' => ['commands' => ['php -r "exit(0);"']],
        ];
    }

    private function fixture(): FixtureRefactorObraNodeDelivery
    {
        return new FixtureRefactorObraNodeDelivery([
            'app/HubA.php' => $this->klass('HubA', 3),
            'app/HubB.php' => $this->klass('HubB', 2),
        ]);
    }

    /**
     * Flag OFF (default): maybePlan() short-circuits => buildPlan() runs => the fixture drives the
     * executor end-to-end and the L4-10 gate REJECTS the fixture, exactly like the frozen end-to-end
     * test. This locks the byte-identical-OFF contract.
     */
    public function test_planning_off_is_byte_identical_to_build_plan(): void
    {
        config(['atlas.loop.planning_enabled' => false]);
        $this->buildRepo();
        $headBefore = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());

        $result = (new AtlasLoopObraExecutionAdapter)->executeAndProve($this->payload(), $this->fixture());

        $env = $result['envelope'] ?? [];
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $env['status'] ?? null, json_encode($env['reason'] ?? $result['reason']));
        $this->assertTrue((bool) ($env['certified'] ?? false), 'the assembled multi-node obra certified');
        $this->assertSame(2, (int) ($env['delivered_nodes'] ?? 0), 'both cluster files delivered as nodes');
        $this->assertTrue((bool) ($env['main_untouched'] ?? false), 'main is byte-identical (isolated worktree)');

        $this->assertFalse((bool) $result['ok'], 'a fixture run never produces a parkable real L4-10');
        $this->assertStringContainsString('l4_10_not_real', (string) $result['reason']);
        $this->assertNull($result['l4_10_evidence_path'], 'the rejected evidence file is cleaned up');

        $headAfter = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->repo))->mustRun()->getOutput());
        $this->assertSame($headBefore, $headAfter, 'main HEAD unchanged');
        $branches = (new Process(['git', 'branch', '--list', 'atlas/obra/*'], $this->repo))->mustRun()->getOutput();
        $this->assertSame('', trim($branches), 'the obra branch was discarded on the honest no-park');
    }

    /**
     * Flag ON + qualifying task: the planning machinery runs end-to-end (real compiler + planner +
     * readiness gate, fake provider seams). The plan handed to the executor has the CREATE-CLASS node at
     * seq 0 and the NEW file 'app/NewHelper.php' was folded into $allowed.
     */
    public function test_planning_on_emits_create_class_node_at_seq_0(): void
    {
        config(['atlas.loop.planning_enabled' => true]);
        $adapter = $this->plannedAdapter();

        $out = $adapter->callMaybePlan($this->planningPayload(), ['app/HubA.php', 'app/HubB.php']);

        $this->assertIsArray($out, 'a qualifying armed task produces a planned obra, not a buildPlan fallback');
        $this->assertContains('app/NewHelper.php', $out['allowed'], 'the planner-introduced new file is folded into allowed');
        $this->assertContains('app/NewHelper.php', $out['new_files'], 'the new file is reported for the structural aggregate-drop lane');

        $nodes = $out['plan']['nodes'] ?? [];
        $this->assertNotEmpty($nodes);
        // Locate the create-class node and assert it carries seq 0 (the executor walks seq ascending).
        $createNode = null;
        foreach ($nodes as $n) {
            if (($n['id'] ?? '') === 'create-helper') {
                $createNode = $n;
            }
        }
        $this->assertNotNull($createNode, 'the plan contains the create-class node');
        $this->assertSame(0, (int) ($createNode['seq'] ?? -1), 'the create-class node is seq 0 (runs FIRST, before the redirect)');
        $this->assertSame('app/NewHelper.php', $createNode['target_area'] ?? null);

        // The redirect node depends on the create node and runs at a higher seq.
        $redirect = null;
        foreach ($nodes as $n) {
            if (($n['id'] ?? '') === 'redirect-hub') {
                $redirect = $n;
            }
        }
        $this->assertNotNull($redirect, 'the plan contains the redirect-caller node');
        $this->assertSame(1, (int) ($redirect['seq'] ?? -1), 'the redirect node runs AFTER the create node');
        $this->assertContains('create-helper', (array) ($redirect['depends_on'] ?? []));
    }

    /**
     * A spec that never reaches ready (vague criteria) must NOT abort the run — maybePlan returns null
     * and the adapter falls back to buildPlan, behaving exactly like the flag-off path (no exception).
     */
    public function test_planning_refusal_falls_back_to_build_plan(): void
    {
        config(['atlas.loop.planning_enabled' => true]);
        $this->buildRepo();

        // A subclass whose spec seam always returns a vague (never-ready) spec.
        $adapter = new class extends AtlasLoopObraExecutionAdapter
        {
            protected function generateSpecViaProvider(string $goal, array $priorGaps, array $payload, array $allowed): array
            {
                // No acceptance_criteria => the compiler refuses-with-gaps on every attempt.
                return ['summary' => '', 'acceptance_criteria' => []];
            }
        };

        $result = $adapter->executeAndProve($this->planningPayload(true), $this->fixture());

        // The run did NOT throw; it fell back to buildPlan and behaves like the OFF path (fixture rejected).
        $this->assertFalse((bool) $result['ok']);
        $this->assertStringContainsString('l4_10_not_real', (string) $result['reason'], 'a planning refusal degrades to the buildPlan lane, never an exception');
        $branches = (new Process(['git', 'branch', '--list', 'atlas/obra/*'], $this->repo))->mustRun()->getOutput();
        $this->assertSame('', trim($branches));
    }

    /**
     * The PRODUCTION provider seam is FAIL-OPEN: with the flag ON but no overridden seam (the live []),
     * the compiler refuses-with-gaps => maybePlan returns null => buildPlan fallback => SAFE.
     */
    public function test_live_provider_seam_is_fail_open_and_falls_back(): void
    {
        config(['atlas.loop.planning_enabled' => true]);
        $this->buildRepo();

        // The real adapter: generateSpecViaProvider returns [] (no real seam wired) => buildPlan fallback.
        $result = (new AtlasLoopObraExecutionAdapter)->executeAndProve($this->planningPayload(true), $this->fixture());

        $this->assertFalse((bool) $result['ok']);
        $this->assertStringContainsString('l4_10_not_real', (string) $result['reason'], 'fail-open seam degrades to buildPlan, never breaks the run');
    }

    // --- helpers --------------------------------------------------------------------------------

    /** A planning payload (refactor_ kind so it qualifies even on a fresh allowed set). */
    private function planningPayload(bool $withRepo = false): array
    {
        $p = $withRepo ? $this->payload() : [
            'objective' => 'Extract the validation cluster into a cohesive new helper and redirect the hub.',
            'allowed_files' => ['app/HubA.php', 'app/HubB.php'],
            'target_relative_path' => 'app/HubA.php',
            'cluster_hash' => 'cluster-plan-'.bin2hex(random_bytes(3)),
            'acceptance' => ['commands' => ['php -r "exit(0);"']],
        ];
        $p['objective_kind'] = 'refactor_complexity';
        if ($withRepo) {
            $p['objective'] = 'Extract the validation cluster into a cohesive new helper and redirect the hub.';
        }

        return $p;
    }

    /**
     * An adapter test double that (a) exposes maybePlan via callMaybePlan, and (b) overrides the two
     * provider seams with a ready spec (suggested_files=['app/NewHelper.php']) and a valid create-class
     * -at-seq-0 DAG, so the REAL compiler + planner + readiness gate run with no provider.
     */
    private function plannedAdapter(): object
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            /** @return array{plan:array<string,mixed>, allowed:list<string>, new_files:list<string>}|null */
            public function callMaybePlan(array $payload, array $allowed): ?array
            {
                return $this->maybePlan($payload, $allowed);
            }

            protected function generateSpecViaProvider(string $goal, array $priorGaps, array $payload, array $allowed): array
            {
                return [
                    'summary' => 'Extract the validation cluster into a cohesive new helper class and redirect the hub.',
                    'acceptance_criteria' => [
                        ['id' => 'c1', 'description' => 'The new helper class encapsulates the validation cluster.', 'required' => true],
                        ['id' => 'c2', 'description' => 'The hub delegates to the new helper, preserving behaviour exactly.', 'required' => true],
                    ],
                    'suggested_files' => ['app/NewHelper.php'],
                    'decomposition_hint' => 'Create app/NewHelper.php first, then redirect the hub to it.',
                ];
            }

            protected function generatePlanViaProvider(string $goal, array $context, array $priorGaps, array $payload, array $allowed, array $newFiles, array $spec): array
            {
                // The create-class node MUST carry seq=0 and its request MUST reference its target file
                // (basename or path) or the readiness gate flags 'request_does_not_reference_its_target'.
                return [
                    'plan_id' => 'obra-plan-create-redirect',
                    'nodes' => [
                        [
                            'id' => 'create-helper',
                            'seq' => 0,
                            'request' => 'Create app/NewHelper.php holding the cohesive validation cluster extracted from the hub.',
                            'target_area' => 'app/NewHelper.php',
                            'depends_on' => [],
                            'complexity_proof' => true,
                        ],
                        [
                            'id' => 'redirect-hub',
                            'seq' => 1,
                            'request' => 'Redirect app/HubA.php to delegate to the new app/NewHelper.php, preserving behaviour exactly.',
                            'target_area' => 'app/HubA.php',
                            'depends_on' => ['create-helper'],
                            'complexity_proof' => true,
                        ],
                    ],
                ];
            }
        };
    }
}
