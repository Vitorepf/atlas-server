<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObraAutoMergeService;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Obra\AtlasObraExecutor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * OBRA-AUTO-MERGE (Phase 2): a GENUINELY CERTIFIED obra branch is auto-merged to main WITHOUT
 * operator review — BUT ONLY AFTER the BROADER REGRESSION GATE passes. This is the
 * consequential, load-bearing crossing; these are its frozen tests:
 *
 *  (2) CERTIFIED obra + flag ON + broader-gate GREEN  => auto-merges to main, no review.
 *  (3) THE KEY SAFETY TEST: certified obra whose change breaks a test OUTSIDE its own nodes
 *      => broader-gate RED => merge BLOCKED, main BYTE-IDENTICAL.
 *  (4) DEFAULT-OFF => obra NEVER auto-merges (operator-review path identical to today).
 *  (5) never-merge / governed-door / net-direction STILL govern.
 *
 * The broader gate is INJECTED behind {@see BroaderRegressionGateContract} so the merge
 * orchestration is exercised deterministically (a fake green/red gate) WITHOUT spawning the
 * whole atlas-server suite. The real gate's mapping logic is proven separately in
 * {@see AtlasLoopBroaderRegressionGateTest}.
 */
final class AtlasLoopObraAutoMergeServiceTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private string $trustLedger = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        // The crossing is OFF by default; the happy-path/safety tests flip it ON explicitly.
        config(['atlas.loop.obra_auto_merge_enabled' => true]);
        // These tests exercise certification / net-direction / clean-tree / lock / broader-gate in
        // ISOLATION. The park-first maturity interlock is a SEPARATE day-2 layer proven on its own
        // (see the trust tests at the bottom); disable it here so these gates are tested cleanly.
        config(['atlas.loop.obra_auto_merge_require_trust' => false]);
    }

    protected function tearDown(): void
    {
        if ($this->trustLedger !== '') {
            File::delete($this->trustLedger);
        }
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    /** Pin the single-source change-class trust ladder to an isolated temp ledger for a test. */
    private function isolateTrustLedger(): void
    {
        $this->trustLedger = sys_get_temp_dir().'/atlas-obra-trust-'.bin2hex(random_bytes(4)).'.jsonl';
        File::delete($this->trustLedger);
        config(['atlas.ai.trust_ladder.log_path' => $this->trustLedger]);
    }

    /**
     * A throwaway git repo with a real `atlas/obra/<id>` branch carrying ONE commit that adds
     * a file. main HEAD is the base; the branch is the obra. Returns [repo, branch, headBefore].
     *
     * @return array{0:string,1:string,2:string}
     */
    private function repoWithObraBranch(string $obraId): array
    {
        $d = sys_get_temp_dir().'/atlas-obra-automerge-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/base.php', "<?php\nreturn 1;\n");
        $this->git($d, ['init', '-q', '-b', 'main']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        $headBefore = trim($this->gitOut($d, ['rev-parse', 'HEAD']));
        $baseBranch = trim($this->gitOut($d, ['rev-parse', '--abbrev-ref', 'HEAD']));

        $branch = 'atlas/obra/'.$obraId;
        $this->git($d, ['checkout', '-q', '-b', $branch]);
        file_put_contents($d.'/feature.php', "<?php\n// obra change\nreturn 2;\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'obra step', '--no-gpg-sign']);
        // Return to the base branch so the obra branch is a sibling ref the service merges in.
        $this->git($d, ['checkout', '-q', $baseBranch]);

        return [$d, $branch, $headBefore];
    }

    /** @param list<string> $argv */
    private function git(string $cwd, array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $cwd))->run();
    }

    /** @param list<string> $argv */
    private function gitOut(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();

        return $p->getOutput();
    }

    /**
     * A genuinely-certified obra envelope (the AtlasObraExecutor result shape).
     *
     * @return array<string,mixed>
     */
    private function certifiedObra(string $obraId, string $branch): array
    {
        return [
            'schema' => AtlasObraExecutor::SCHEMA,
            'plan_id' => $obraId,
            'status' => AtlasObraExecutor::STATUS_DONE,
            'branch' => $branch,
            'node_count' => 1,
            'delivered_nodes' => 1,
            'failed_node' => null,
            'certified' => true,
            'nodes' => [
                ['id' => $obraId.':n0', 'seq' => 0, 'status' => AtlasObraExecutor::NODE_DONE, 'files_changed' => ['feature.php'], 'commit' => 'abc'],
            ],
            'integrated_test_result' => ['supplied' => true, 'ran' => true, 'passed' => true, 'exit_code' => 0],
            'main_untouched' => true,
            'never_merged' => true,
            'never_pushed' => true,
        ];
    }

    private function bindGate(bool $passed, ?string $reason = null): void
    {
        $this->app->instance(BroaderRegressionGateContract::class, new class($passed, $reason) implements BroaderRegressionGateContract
        {
            public function __construct(private bool $passed, private ?string $reason) {}

            public function evaluate(string $repoRoot, array $changedFiles): array
            {
                return [
                    'schema_version' => 'fake.broader_gate.v1',
                    'passed' => $this->passed,
                    'reason' => $this->reason,
                    'suites' => [['path' => 'tests/Feature/Loop', 'ran' => true, 'passed' => $this->passed, 'exit_code' => $this->passed ? 0 : 1]],
                    'boot_smoke' => ['ran' => true, 'passed' => true],
                    'php_lint' => ['ran' => true, 'passed' => true, 'failed_file' => null],
                    'selected_tests' => $changedFiles,
                ];
            }
        });
    }

    private function service(): AtlasLoopObraAutoMergeService
    {
        return $this->app->make(AtlasLoopObraAutoMergeService::class);
    }

    // ------------------------------------------------------------------
    // (2) HAPPY PATH — certified + flag ON + broader-gate GREEN => merged.
    // ------------------------------------------------------------------

    public function test_certified_obra_with_green_broader_gate_auto_merges_to_main_no_operator_review(): void
    {
        $this->bindGate(passed: true);
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-green');

        $result = $this->service()->autoMerge($this->certifiedObra('obra-green', $branch), $repo);

        $this->assertSame('merged', $result['status'], (string) ($result['reason'] ?? ''));
        $this->assertTrue($result['merged']);
        $this->assertTrue((bool) data_get($result, 'broader_gate.passed'));

        // The obra change is REALLY on main now, and main moved forward from headBefore.
        $this->assertFileExists($repo.'/feature.php');
        $head = trim($this->gitOut($repo, ['rev-parse', 'HEAD']));
        $this->assertNotSame($headBefore, $head, 'main advanced (the merge committed)');
        $this->assertStringContainsString('obra auto-merge', $this->gitOut($repo, ['log', '-1', '--pretty=%s']));
    }

    // ------------------------------------------------------------------
    // (3) THE KEY SAFETY TEST — broader-gate RED => merge BLOCKED, main untouched.
    // ------------------------------------------------------------------

    public function test_dirty_working_tree_is_refused_never_resets_over_uncommitted_work(): void
    {
        $this->bindGate(passed: true);
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-dirty');
        // Uncommitted work in the tree — a recovery reset --hard would clobber it.
        file_put_contents($repo.'/UNCOMMITTED.txt', "operator work in progress\n");

        $result = $this->service()->autoMerge($this->certifiedObra('obra-dirty', $branch), $repo);

        $this->assertFalse((bool) $result['merged'], 'never merges into a dirty tree');
        $this->assertStringContainsString('working_tree_not_clean', (string) ($result['reason'] ?? ''));
        $this->assertSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])), 'main HEAD untouched');
        $this->assertFileExists($repo.'/UNCOMMITTED.txt', 'the uncommitted work was NOT clobbered');
    }

    public function test_an_in_flight_crossing_lock_refuses_a_concurrent_merge(): void
    {
        $this->bindGate(passed: true);
        [$repo, $branch] = $this->repoWithObraBranch('obra-locked');
        // Simulate another crossing already holding the SINGLE main-merge lock (collapsed from the old
        // per-path locks in LOOP-OS Slice 1 — obra now shares atlas-main-merge.lock with the drain + cycle).
        $held = fopen($repo.'/.git/'.AtlasLoopMergeActuator::LOCK_BASENAME, 'c');
        $this->assertNotFalse($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $result = $this->service()->autoMerge($this->certifiedObra('obra-locked', $branch), $repo);

        $this->assertFalse((bool) $result['merged'], 'a second concurrent crossing is refused, not raced');
        $this->assertStringContainsString('merge_lock_held', (string) ($result['reason'] ?? ''));
        flock($held, LOCK_UN);
        fclose($held);
    }

    public function test_certified_obra_breaking_an_outside_test_is_blocked_main_untouched(): void
    {
        // The obra is GENUINELY CERTIFIED (its own nodes + integrated test green), but its
        // change breaks a DIFFERENT test the per-node canary never ran — the broader gate
        // catches it (regression_suite_red) and the merge MUST be blocked.
        $this->bindGate(passed: false, reason: 'regression_suite_red:tests/Feature/Loop/AtlasLoopQualityPersistenceTest.php');
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-red');

        $result = $this->service()->autoMerge($this->certifiedObra('obra-red', $branch), $repo);

        $this->assertSame('broader_gate_red', $result['status']);
        $this->assertFalse($result['merged']);
        $this->assertStringContainsString('broader_regression_gate_red', (string) $result['reason']);

        // MAIN IS BYTE-IDENTICAL: HEAD unchanged, the obra file is NOT on the working tree,
        // and the tree is clean (the --no-commit merge was aborted + hard-reset).
        $this->assertSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])), 'HEAD must not move on a red gate');
        $this->assertFileDoesNotExist($repo.'/feature.php', 'the obra change must be fully reverted on a red gate');
        $this->assertSame('', trim($this->gitOut($repo, ['status', '--porcelain'])), 'working tree must be clean after a blocked merge');
    }

    // ------------------------------------------------------------------
    // (4) DEFAULT-OFF — never auto-merges; operator-review path identical to today.
    // ------------------------------------------------------------------

    public function test_default_off_never_auto_merges_operator_review_path(): void
    {
        config(['atlas.loop.obra_auto_merge_enabled' => false]);
        $this->bindGate(passed: true); // even with a green gate available, OFF must not merge
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-off');

        $result = $this->service()->autoMerge($this->certifiedObra('obra-off', $branch), $repo);

        $this->assertSame('disabled', $result['status']);
        $this->assertFalse($result['merged']);
        // Main is untouched; the obra file never reached the working tree.
        $this->assertSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])));
        $this->assertFileDoesNotExist($repo.'/feature.php');
    }

    // ------------------------------------------------------------------
    // (5a) A NON-certified obra is NEVER auto-merged (even with the flag ON + green gate).
    // ------------------------------------------------------------------

    public function test_non_certified_obra_is_never_auto_merged(): void
    {
        $this->bindGate(passed: true);
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-needs-review');

        // needs_review: steps passed but the integrated test did NOT pass — NOT certified.
        $obra = $this->certifiedObra('obra-needs-review', $branch);
        $obra['status'] = AtlasObraExecutor::STATUS_NEEDS_REVIEW;
        $obra['certified'] = false;
        $obra['integrated_test_result'] = ['supplied' => true, 'ran' => true, 'passed' => false];

        $result = $this->service()->autoMerge($obra, $repo);

        $this->assertSame('not_certified', $result['status']);
        $this->assertFalse($result['merged']);
        $this->assertSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])));
        $this->assertFileDoesNotExist($repo.'/feature.php');
    }

    public function test_certified_but_absent_integrated_test_is_never_auto_merged(): void
    {
        $this->bindGate(passed: true);
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-no-integrated');

        $obra = $this->certifiedObra('obra-no-integrated', $branch);
        $obra['integrated_test_result'] = ['supplied' => false, 'ran' => false, 'passed' => false];

        $result = $this->service()->autoMerge($obra, $repo);

        $this->assertSame('not_certified', $result['status']);
        $this->assertStringContainsString('integrated_test_not_green', (string) $result['reason']);
        $this->assertSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])));
    }

    // ------------------------------------------------------------------
    // (5b) NET-DIRECTION still governs the obra crossing.
    // ------------------------------------------------------------------

    public function test_net_direction_throttle_blocks_the_obra_crossing(): void
    {
        // Seed 4 merged proposals with RED canaries so the measured net direction is negative.
        $seeded = [];
        for ($i = 0; $i < 4; $i++) {
            $seeded[] = AtlasLoopProposal::create([
                'campaign_id' => \App\Models\AtlasLoopCampaign::create([
                    'schema_version' => 'atlas.loop.campaign.v1',
                    'status' => \App\Models\AtlasLoopCampaign::STATUS_RUNNING,
                    'goal' => 'net-dir', 'config' => [], 'max_seconds' => 60,
                ])->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => 'x', 'target_path' => 'x.php', 'diff_text' => 'd',
                'proposal_hash' => 'net-obra-'.$i, 'metric' => null,
                'quality' => [],
            ]);
        }
        AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            foreach ($seeded as $i => $p) {
                $p->forceFill([
                    'merged_to_main' => true,
                    'reviewed_at' => now()->subMinutes(10 - $i),
                    'quality' => ['_canary' => ['ran' => true, 'passed' => false, 'target' => 't']],
                ])->save();
            }
        } finally {
            AtlasLoopProposal::$governedMergeInProgress = false;
        }

        $this->bindGate(passed: true);
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-throttle');

        $result = $this->service()->autoMerge($this->certifiedObra('obra-throttle', $branch), $repo);

        $this->assertSame('throttled', $result['status'], json_encode($result));
        $this->assertFalse($result['merged']);
        $this->assertSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])));
    }

    // ------------------------------------------------------------------
    // (5c) GOVERNED DOOR — nothing outside the governed scope marks merged_to_main.
    //      (the obra path reuses the SAME model guard the single-file path uses)
    // ------------------------------------------------------------------

    public function test_governed_door_still_forces_merged_false_outside_scope(): void
    {
        $campaign = \App\Models\AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => \App\Models\AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'door', 'config' => [], 'max_seconds' => 60,
        ]);
        $proposal = AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'x', 'target_path' => 'x.php', 'diff_text' => 'd',
            'proposal_hash' => 'door-1', 'metric' => null, 'quality' => [],
        ]);

        // Outside the governed scope, a forced merged_to_main=true is structurally reverted.
        $proposal->forceFill(['merged_to_main' => true])->save();

        $this->assertFalse((bool) $proposal->fresh()->merged_to_main, 'the governed door still forces false outside scope');
    }

    // ------------------------------------------------------------------
    // (6) PARK-FIRST MATURITY INTERLOCK (day-2) — autonomy is EARNED from real merge history,
    //     never granted on a first run. Default ON; uses the SAME single-source change-class
    //     trust ladder the single-file pipeline feeds (no parallel trust source).
    // ------------------------------------------------------------------

    public function test_park_first_interlock_parks_an_unproven_class_even_with_everything_else_green(): void
    {
        // Trust required (the production default), certified + flag ON + green broader gate — but the
        // obra's change class (`code`, from feature.php) has NO proven merge history. It MUST park.
        config(['atlas.loop.obra_auto_merge_require_trust' => true]);
        $this->isolateTrustLedger(); // empty ledger => zero clean streak for every class
        $this->bindGate(passed: true);
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-unproven');

        $result = $this->service()->autoMerge($this->certifiedObra('obra-unproven', $branch), $repo);

        $this->assertSame('trust_not_earned', $result['status'], (string) ($result['reason'] ?? ''));
        $this->assertFalse((bool) $result['merged']);
        $this->assertStringContainsString('has_not_earned_autonomy', (string) $result['reason']);
        // Refused BEFORE any apply: main is byte-identical and the obra file never reached the tree.
        $this->assertSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])), 'main HEAD untouched');
        $this->assertFileDoesNotExist($repo.'/feature.php');
        $this->assertSame('', trim($this->gitOut($repo, ['status', '--porcelain'])), 'working tree untouched');
    }

    public function test_a_class_that_earned_autonomy_from_real_history_is_allowed_to_cross(): void
    {
        // Same crossing, trust STILL required — but now the operator has allowlisted the class and
        // it has earned a clean streak past the autonomous threshold from REAL evidence. It crosses.
        config(['atlas.loop.obra_auto_merge_require_trust' => true]);
        $this->isolateTrustLedger();
        config([
            'atlas.ai.trust_ladder.enabled' => true,
            'atlas.ai.trust_ladder.eligible_classes' => ['code'],   // operator allowlists the class
            'atlas.ai.trust_ladder.thresholds' => ['autonomous' => 1],
        ]);
        // One re-checkable clean promotion keyed on a distinct ref => cleanStreak('code') = 1 => AUTONOMOUS.
        app(AtlasChangeClassTrustLadder::class)->recordEvidence(
            'code',
            AtlasChangeClassTrustLadder::EVIDENCE_CLEAN_PROMOTION,
            'real-commit:'.bin2hex(random_bytes(6)),
        );

        $this->bindGate(passed: true);
        [$repo, $branch, $headBefore] = $this->repoWithObraBranch('obra-proven');

        $result = $this->service()->autoMerge($this->certifiedObra('obra-proven', $branch), $repo);

        $this->assertSame('merged', $result['status'], (string) ($result['reason'] ?? ''));
        $this->assertTrue((bool) $result['merged'], 'a class that earned autonomy crosses (still behind every other gate)');
        $this->assertFileExists($repo.'/feature.php');
        $this->assertNotSame($headBefore, trim($this->gitOut($repo, ['rev-parse', 'HEAD'])), 'main advanced');
    }
}
