<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionDetector;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionLoopService;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementAdversarialRecheck;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementReceiptLog;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementRelevanceGate;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * S3.F4 — GOVERNANCE HARDENING (make the recursive loop safe to leave running).
 *
 * This is the highest-stakes capability (Atlas modifying Atlas), so the SAFETY FLOORS
 * are everything. Each test proves one floor, cost-free (a fake mission cuts a REAL
 * branch via the real materializer but spends ZERO tokens — the proven seam):
 *
 *  1. NEVER-MERGE invariant on a FULL cycle: main HEAD + working tree byte-identical
 *     before/after, the ONLY artifact is a branch, AND a grep proves there is no
 *     git merge/push/checkout-main code path in the loop. The operator-merge is the
 *     sole promotion path.
 *  2. ADVERSARIAL RE-CHECK rejects a crafted gate-pass-but-fail-on-recheck case → the
 *     branch is HELD as needs_review (not surfaced as accepted, not discarded).
 *  3. PER-RUN BRANCH CAP halts further delivery once the run's branch budget is spent.
 *  4. KILL-SWITCH tripped mid-run stops cleanly — processed outcomes kept, the rest
 *     skipped, never main.
 *  5. EVIDENCE / RECEIPT written for BOTH a pass and a reject (no silent action),
 *     auditable from the persisted log.
 */
final class AtlasSelfConstructionGovernanceHardeningTest extends TestCase
{
    private string $receiptLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiptLog = sys_get_temp_dir().'/atlas-sc-f4-receipts-'.substr(md5(uniqid('', true)), 0, 8).'.jsonl';
        @unlink($this->receiptLog);
        config()->set('atlas.self_construction.receipt_log_path', $this->receiptLog);
        // Default-OFF autonomy is the contract; assert from the explicit baseline.
        config()->set('atlas.self_construction.autonomous_enabled', false);
    }

    protected function tearDown(): void
    {
        @unlink($this->receiptLog);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1) NEVER-MERGE invariant on a full cycle + no merge/push/checkout code path.
    // ------------------------------------------------------------------

    public function test_full_cycle_never_touches_main_and_the_only_artifact_is_a_branch(): void
    {
        $repo = $this->bootGitRepo();

        // The main-untouched baseline: HEAD + working-tree status before the cycle.
        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);
        $statusBefore = $this->git($repo, ['status', '--porcelain']);
        $branchesBefore = $this->branches($repo);

        // A full on-target cycle that cuts a REAL branch (zero spend).
        $mission = $this->realBranchMission($repo, touchedFile: 'app/Services/Widget.php', content: $this->onConcernContent());
        $loop = $this->loop($mission);
        $r = $loop->run(['repo_dir' => $repo, 'max' => 1]);

        $first = $r['outcomes'][0];
        $this->assertTrue($first['accepted'], 'a real on-target cycle is accepted');
        $this->assertStringStartsWith('atlas/materialize/', (string) $first['branch']);

        // ---- THE NEVER-MERGE PROOF ----
        // main HEAD + working tree byte-identical before/after.
        $this->assertSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD']), 'main HEAD must be byte-identical');
        $this->assertSame($statusBefore, $this->git($repo, ['status', '--porcelain']), 'the working tree must be byte-identical');

        // The ONLY new artifact is exactly one branch under atlas/materialize/.
        $branchesAfter = $this->branches($repo);
        $new = array_values(array_diff($branchesAfter, $branchesBefore));
        $this->assertCount(1, $new, 'exactly one new ref — the materialize branch');
        $this->assertStringContainsString('atlas/materialize/', $new[0]);
        $this->assertSame($r['branches'][0], ltrim($new[0], '* '), 'the surfaced branch is the only artifact');

        // The current branch is unchanged — the loop never checked out the materialize branch.
        $this->assertSame(
            'main',
            trim($this->git($repo, ['rev-parse', '--abbrev-ref', 'HEAD'])),
            'the loop must never checkout away from main',
        );

        // The payload asserts the invariants honestly.
        $this->assertTrue($r['never_merged']);
        $this->assertTrue($r['main_untouched']);

        File::deleteDirectory($repo);
    }

    public function test_loop_source_has_no_git_merge_push_or_checkout_main_code_path(): void
    {
        // The structural half of the never-merge proof: a grep over the loop's OWN source
        // (and the F4 collaborators) proves there is no code path that could merge, push,
        // or checkout main — the operator-merge is the sole promotion path, by construction.
        $sources = [
            base_path('app/Services/Ai/SelfConstruction/AtlasSelfConstructionLoopService.php'),
            base_path('app/Services/Ai/SelfConstruction/AtlasSelfImprovementAdversarialRecheck.php'),
            base_path('app/Services/Ai/SelfConstruction/AtlasSelfImprovementReceiptLog.php'),
        ];

        foreach ($sources as $src) {
            $code = (string) file_get_contents($src);
            // Strip comments + docblocks so prose like "the operator merges" never trips
            // the assertion — only EXECUTABLE git operations would (and there are none).
            $stripped = $this->stripCommentsAndStrings($code);

            $this->assertDoesNotMatchRegularExpression(
                '/\bgit\s+merge\b/i',
                $stripped,
                $src.' must contain no git-merge code path',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bgit\s+push\b/i',
                $stripped,
                $src.' must contain no git-push code path',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bcheckout\b/i',
                $stripped,
                $src.' must contain no git-checkout code path',
            );
            // It also never invokes a Process / shell directly — all git goes through the
            // governed materializer (branch/discard only), never raw git from the loop.
            $this->assertStringNotContainsString(
                'Process',
                $stripped,
                $src.' must not invoke a raw process — git is the materializer\'s job',
            );
        }
    }

    // ------------------------------------------------------------------
    // 2) ADVERSARIAL RE-CHECK — gate pass but re-check refusal → needs_review (HELD).
    // ------------------------------------------------------------------

    public function test_adversarial_recheck_holds_a_gate_pass_whose_branch_measure_failed(): void
    {
        // The crafted case: an ON-TARGET, ON-CONCERN delivery (the relevance gate PASSES)
        // whose own branch success-metric FAILED. The first gate has no view of the
        // measure; the independent adversarial re-check confirms the measure and REFUSES.
        // The branch must be HELD as needs_review — not surfaced as accepted, NOT discarded.
        $repo = $this->bootGitRepo();
        $mission = $this->realBranchMission(
            $repo,
            touchedFile: 'app/Services/Widget.php',
            content: $this->onConcernContent(),
            // A measure that RAN and FAILED rides the delivery envelope.
            measure: ['ran' => true, 'passed' => false],
        );
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 1]);

        $first = $r['outcomes'][0];
        $this->assertTrue($first['delivered']);
        $this->assertTrue($first['relevant'], 'the relevance gate alone PASSES (on-target, on-concern)');
        $this->assertFalse($first['accepted'], 'a gate pass is NOT enough — the re-check must also confirm');
        $this->assertTrue($first['needs_review'], 'a gate-pass-but-recheck-fail is HELD as needs_review');
        $this->assertSame('measure_failed', $first['recheck']['reason'] ?? null);
        $this->assertNull($first['branch'], 'a held outcome is not surfaced as an accepted branch');
        $this->assertNotNull($first['held_branch'], 'but the branch is HELD for operator review');

        // The held branch STILL EXISTS in the repo (not discarded — the operator decides).
        $heldExists = new Process(
            ['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$first['held_branch']],
            $repo,
        );
        $heldExists->run();
        $this->assertTrue($heldExists->isSuccessful(), 'the held branch must NOT be discarded');

        // Honest summary: the third bucket is surfaced, accepted/rejected stay clean.
        $this->assertSame(0, (int) $r['accepted_count']);
        $this->assertSame(0, (int) $r['rejected_count'], 'needs_review is NOT a rejection');
        $this->assertSame(1, (int) $r['needs_review_count']);
        $this->assertSame([$first['held_branch']], $r['held_branches']);
        // No accepted branch ⇒ no on-target rate to claim (precision is over accepted).
        $this->assertSame(0.0, $r['relevance_precision'], 'delivered but none accepted ⇒ 0.0');

        File::deleteDirectory($repo);
    }

    public function test_adversarial_recheck_confirms_a_clean_on_target_on_concern_passing_branch(): void
    {
        // The positive control: the SAME on-target/on-concern delivery, but with a
        // PASSING measure, sails through both the gate and the re-check → accepted.
        $repo = $this->bootGitRepo();
        $mission = $this->realBranchMission(
            $repo,
            touchedFile: 'app/Services/Widget.php',
            content: $this->onConcernContent(),
            measure: ['ran' => true, 'passed' => true],
        );
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 1]);

        $first = $r['outcomes'][0];
        $this->assertTrue($first['accepted'], 'gate + re-check both pass ⇒ accepted');
        $this->assertFalse($first['needs_review']);
        $this->assertTrue((bool) ($first['recheck']['confirmed'] ?? false));
        $this->assertSame('confirmed', $first['recheck']['reason'] ?? null);
        $this->assertNotNull($first['branch']);
        $this->assertSame(1, (int) $r['accepted_count']);

        File::deleteDirectory($repo);
    }

    // ------------------------------------------------------------------
    // 3) PER-RUN BRANCH CAP — halts further delivery once the budget is spent.
    // ------------------------------------------------------------------

    public function test_per_run_branch_cap_halts_further_delivery(): void
    {
        // Three signals are present, but the per-run cap is 2 — only two branches may be
        // KEPT this run; the third signal is never processed (no extra spend, no branch).
        $repo = $this->bootMultiSignalRepo(3);
        $mission = $this->capCountingMission($repo);
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 3, 'max_branches_per_run' => 2]);

        $this->assertSame(3, (int) $r['detected'], 'all three TODOs are detected');
        $this->assertSame(2, (int) $r['accepted_count'], 'only TWO branches were kept — the cap held');
        $this->assertCount(2, $r['outcomes'], 'the third signal was never processed (halted)');
        $this->assertSame(2, (int) $r['branch_cap']);
        $this->assertTrue((bool) $r['branch_cap_reached'], 'the run reports it halted on the cap');
        // The mission was invoked exactly twice — the third delivery never ran (no spend).
        $this->assertSame(2, $mission->calls, 'no third delivery — the cap stops before spending');

        File::deleteDirectory($repo);
    }

    public function test_branch_cap_is_clamped_to_the_signal_fan_out_bound(): void
    {
        // Even an over-large per-run cap can never exceed max_signals — the loop clamps it
        // so a misconfiguration cannot widen the self-modifying fan-out.
        config()->set('atlas.self_construction.max_signals', 2);
        $repo = $this->bootMultiSignalRepo(4);
        $mission = $this->capCountingMission($repo);
        $loop = $this->loop($mission);

        // Ask for 100 branches AND max 100 signals — both are clamped to max_signals=2.
        $r = $loop->run(['repo_dir' => $repo, 'max' => 100, 'max_branches_per_run' => 100]);

        $this->assertSame(2, (int) $r['branch_cap'], 'the cap is clamped to max_signals');
        $this->assertLessThanOrEqual(2, (int) $r['accepted_count']);

        File::deleteDirectory($repo);
    }

    // ------------------------------------------------------------------
    // 4) KILL-SWITCH — tripped mid-run stops cleanly.
    // ------------------------------------------------------------------

    public function test_kill_switch_tripped_mid_run_stops_cleanly(): void
    {
        // Two signals; a kill-switch file is pre-tripped (the strongest mid-run case:
        // it is honored BEFORE the first signal). NOTHING is delivered, NOTHING spent,
        // and the run returns a clean, honest summary (never main).
        $repo = $this->bootMultiSignalRepo(2);
        $killFile = sys_get_temp_dir().'/atlas-sc-f4-kill-'.substr(md5(uniqid('', true)), 0, 8);
        @touch($killFile);

        $mission = $this->capCountingMission($repo);
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 2, 'kill_file' => $killFile]);

        $this->assertTrue((bool) $r['killed_mid_run'], 'the run reports it was killed');
        $this->assertSame(0, $mission->calls, 'a pre-tripped kill-switch stops before any delivery (no spend)');
        $this->assertSame(0, (int) $r['accepted_count']);
        $this->assertSame([], $r['branches']);
        $this->assertTrue($r['never_merged'] && $r['main_untouched']);

        @unlink($killFile);
        File::deleteDirectory($repo);
    }

    public function test_kill_switch_after_first_signal_keeps_the_first_outcome(): void
    {
        // The kill-switch trips AFTER the first signal is processed (the mission itself
        // touches the file mid-run). The first outcome + its branch are KEPT; the second
        // signal is skipped. Processed work is never lost when the operator stops a run.
        $repo = $this->bootMultiSignalRepo(2);
        $killFile = sys_get_temp_dir().'/atlas-sc-f4-kill2-'.substr(md5(uniqid('', true)), 0, 8);
        @unlink($killFile);

        // A mission that trips the kill-switch the moment it delivers the FIRST branch.
        $mission = $this->killOnDeliverMission($repo, $killFile);
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 2, 'kill_file' => $killFile]);

        $this->assertTrue((bool) $r['killed_mid_run']);
        $this->assertCount(1, $r['outcomes'], 'only the first signal was processed before the kill');
        $this->assertSame(1, (int) $r['accepted_count'], 'the first outcome is KEPT, not lost');
        $this->assertNotEmpty($r['branches']);

        @unlink($killFile);
        File::deleteDirectory($repo);
    }

    // ------------------------------------------------------------------
    // 5) EVIDENCE / RECEIPT — written for BOTH a pass and a reject (no silent action).
    // ------------------------------------------------------------------

    public function test_receipt_written_for_an_accepted_cycle(): void
    {
        $repo = $this->bootGitRepo();
        $mission = $this->realBranchMission($repo, touchedFile: 'app/Services/Widget.php', content: $this->onConcernContent(), measure: ['ran' => true, 'passed' => true]);
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 1]);

        $first = $r['outcomes'][0];
        $this->assertTrue($first['accepted']);
        $this->assertTrue((bool) ($first['receipt_written'] ?? false), 'an accepted decision writes a receipt');
        $this->assertSame('accepted', $first['decision'] ?? null);

        $receipts = (new AtlasSelfImprovementReceiptLog($this->receiptLog))->read();
        $this->assertCount(1, $receipts);
        $this->assertSame('accepted', $receipts[0]['decision']);
        $this->assertTrue((bool) $receipts[0]['relevant']);
        $this->assertTrue((bool) $receipts[0]['recheck_confirmed']);
        $this->assertStringStartsWith('atlas/materialize/', (string) $receipts[0]['branch']);
        $this->assertTrue((bool) $receipts[0]['never_merged']);
        // Provenance only — no source content ever crosses into the receipt.
        $this->assertArrayNotHasKey('content', $receipts[0]);

        File::deleteDirectory($repo);
    }

    public function test_receipt_written_for_a_rejected_cycle_honestly(): void
    {
        // An OFF-TARGET delivery (the 412-style failure). The receipt must record the
        // REJECTION honestly — never hidden, never relabelled as progress.
        $repo = $this->bootGitRepo();
        $mission = $this->realBranchMission($repo, touchedFile: 'app/Generated/OffTarget.php', content: "<?php\nclass OffTarget {}\n");
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 1]);

        $first = $r['outcomes'][0];
        $this->assertTrue($first['delivered']);
        $this->assertFalse($first['relevant']);
        $this->assertTrue((bool) ($first['receipt_written'] ?? false), 'a rejected decision ALSO writes a receipt');
        $this->assertSame('rejected', $first['decision'] ?? null);

        $receipts = (new AtlasSelfImprovementReceiptLog($this->receiptLog))->read();
        $this->assertCount(1, $receipts);
        $this->assertSame('rejected', $receipts[0]['decision']);
        $this->assertFalse((bool) $receipts[0]['relevant'], 'the rejection is recorded AS a rejection');
        $this->assertSame('off_target_generation', $receipts[0]['relevance_reason']);
        $this->assertNull($receipts[0]['branch'], 'no kept branch on a rejected cycle');

        File::deleteDirectory($repo);
    }

    public function test_receipts_accumulate_for_pass_and_reject_in_one_audit_trail(): void
    {
        // Two cycles into the SAME log: one accepted, one rejected. Both are auditable —
        // the trail is COMPLETE (no silent action), counts honest.
        $repo = $this->bootGitRepo();

        // Cycle 1 — accepted.
        $ok = $this->realBranchMission($repo, touchedFile: 'app/Services/Widget.php', content: $this->onConcernContent(), measure: ['ran' => true, 'passed' => true]);
        $this->loop($ok)->run(['repo_dir' => $repo, 'max' => 1]);

        // Resolve the marker so cycle 2 lands a DISTINCT mission id, then reject it.
        File::put(
            $repo.'/app/Services/Widget.php',
            "<?php\n\nnamespace App\\Services;\n\nclass Widget\n{\n    // FIXME: reinforce the workspace boundary check\n    public function run(): void {}\n}\n",
        );
        $off = $this->realBranchMission($repo, touchedFile: 'app/Generated/OffTarget2.php', content: "<?php\nclass OffTarget2 {}\n");
        $this->loop($off)->run(['repo_dir' => $repo, 'max' => 1]);

        $receipts = (new AtlasSelfImprovementReceiptLog($this->receiptLog))->read();
        $decisions = array_column($receipts, 'decision');
        $this->assertContains('accepted', $decisions);
        $this->assertContains('rejected', $decisions);
        $this->assertCount(2, $receipts, 'both decisions are in the one audit trail');

        File::deleteDirectory($repo);
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private function loop(AtlasMissionService $mission): AtlasSelfConstructionLoopService
    {
        return new AtlasSelfConstructionLoopService(
            new AtlasSelfConstructionDetector,
            $mission,
            new AtlasSelfImprovementRelevanceGate,
            new GovernedBranchMaterializationService,
            null, // no meta-metric wiring needed for these floors
            new AtlasSelfImprovementAdversarialRecheck,
            new AtlasSelfImprovementReceiptLog($this->receiptLog),
        );
    }

    /**
     * Content whose tokens cover the Widget concern ("tighten the workspace guard" +
     * the Widget filename) so the relevance gate's content dimension passes.
     */
    private function onConcernContent(): string
    {
        return "<?php\n\nnamespace App\\Services;\n\n// tighten the workspace guard for Widget\nclass Widget { public function guard(): bool { return true; } }\n";
    }

    /**
     * A fake mission that cuts a REAL branch via the real materializer (zero spend) and
     * carries {path, content} + an optional measure result so all three relevance
     * dimensions AND the adversarial re-check are exercised end to end.
     *
     * @param  array<string,mixed>|null  $measure
     */
    private function realBranchMission(string $repo, string $touchedFile, string $content, ?array $measure = null): AtlasMissionService
    {
        return new class($repo, $touchedFile, $content, $measure) extends AtlasMissionService
        {
            public ?string $lastRequest = null;

            /** @var array<string,mixed> */
            public array $lastOpts = [];

            /** @param array<string,mixed>|null $measure */
            public function __construct(
                private string $repo,
                private string $touchedFile,
                private string $body,
                private ?array $measure,
            ) {}

            public function run(string $request, array $opts = []): array
            {
                $this->lastRequest = $request;
                $this->lastOpts = $opts;
                $id = (string) ($opts['id'] ?? 'x');

                $m = (new GovernedBranchMaterializationService)->materialize([
                    'id' => $id,
                    'files' => [['path' => $this->touchedFile, 'content' => $this->body]],
                    'repo_dir' => $this->repo,
                    'certified' => true,
                    'gate_receipt' => hash('sha256', 'gate'.$id),
                ]);

                $env = [
                    'schema_version' => AtlasMissionService::SCHEMA,
                    'mission_id' => $id,
                    'request' => $request,
                    'delivered' => (bool) ($m['materialized'] ?? false),
                    'branch' => $m['branch'] ?? null,
                    'brain_context_used' => true,
                    'evidence_recorded' => true,
                    'main_untouched' => (bool) ($m['main_untouched'] ?? true),
                    'never_merged' => true,
                    'review_commands' => $m['review_commands'] ?? [],
                    'delivery' => ['files' => [['path' => $this->touchedFile, 'content' => $this->body]]],
                ];
                if ($this->measure !== null) {
                    $env['measure'] = $this->measure;
                }

                return $env;
            }
        };
    }

    /**
     * A cap/kill counting mission: on-target on-concern delivery (always accepted),
     * counts how many times it was invoked, so the cap/kill "no extra spend" claims are
     * provable. Each call cuts a DISTINCT branch (id from opts).
     */
    private function capCountingMission(string $repo): AtlasMissionService
    {
        return new class($repo) extends AtlasMissionService
        {
            public int $calls = 0;

            public function __construct(private string $repo) {}

            public function run(string $request, array $opts = []): array
            {
                $this->calls++;
                $id = (string) ($opts['id'] ?? ('x'.$this->calls));
                $target = is_string($opts['target_file'] ?? null) ? (string) $opts['target_file'] : 'app/Services/F'.$this->calls.'.php';
                // Content covers the concern token "todo guard" generically + the filename.
                $base = basename($target, '.php');
                $body = "<?php\n// resolve guard cursor enforcement for {$base}\nclass {$base}Fix {}\n";

                $m = (new GovernedBranchMaterializationService)->materialize([
                    'id' => $id,
                    'files' => [['path' => $target, 'content' => $body]],
                    'repo_dir' => $this->repo,
                    'certified' => true,
                    'gate_receipt' => hash('sha256', 'gate'.$id),
                ]);

                return [
                    'schema_version' => AtlasMissionService::SCHEMA,
                    'mission_id' => $id,
                    'request' => $request,
                    'delivered' => (bool) ($m['materialized'] ?? false),
                    'branch' => $m['branch'] ?? null,
                    'brain_context_used' => true,
                    'evidence_recorded' => true,
                    'main_untouched' => (bool) ($m['main_untouched'] ?? true),
                    'never_merged' => true,
                    'review_commands' => $m['review_commands'] ?? [],
                    'delivery' => ['files' => [['path' => $target, 'content' => $body]]],
                    'measure' => ['ran' => true, 'passed' => true],
                ];
            }
        };
    }

    /**
     * A mission that trips the kill-switch the moment it delivers its first branch — so
     * the NEXT signal's pre-loop kill check fires and the run stops with the first
     * outcome kept.
     */
    private function killOnDeliverMission(string $repo, string $killFile): AtlasMissionService
    {
        return new class($repo, $killFile) extends AtlasMissionService
        {
            public int $calls = 0;

            public function __construct(private string $repo, private string $killFile) {}

            public function run(string $request, array $opts = []): array
            {
                $this->calls++;
                $id = (string) ($opts['id'] ?? ('x'.$this->calls));
                $target = is_string($opts['target_file'] ?? null) ? (string) $opts['target_file'] : 'app/Services/K'.$this->calls.'.php';
                $base = basename($target, '.php');
                $body = "<?php\n// resolve guard cursor enforcement for {$base}\nclass {$base}Fix {}\n";

                $m = (new GovernedBranchMaterializationService)->materialize([
                    'id' => $id,
                    'files' => [['path' => $target, 'content' => $body]],
                    'repo_dir' => $this->repo,
                    'certified' => true,
                    'gate_receipt' => hash('sha256', 'gate'.$id),
                ]);

                // Trip the kill-switch right after the first delivery.
                @touch($this->killFile);

                return [
                    'schema_version' => AtlasMissionService::SCHEMA,
                    'mission_id' => $id,
                    'request' => $request,
                    'delivered' => (bool) ($m['materialized'] ?? false),
                    'branch' => $m['branch'] ?? null,
                    'brain_context_used' => true,
                    'evidence_recorded' => true,
                    'main_untouched' => (bool) ($m['main_untouched'] ?? true),
                    'never_merged' => true,
                    'review_commands' => $m['review_commands'] ?? [],
                    'delivery' => ['files' => [['path' => $target, 'content' => $body]]],
                    'measure' => ['ran' => true, 'passed' => true],
                ];
            }
        };
    }

    private function bootGitRepo(): string
    {
        $repo = sys_get_temp_dir().'/atlas-sc-f4-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($repo.'/app/Services', 0777, true, true);
        File::put(
            $repo.'/app/Services/Widget.php',
            "<?php\n\nnamespace App\\Services;\n\nclass Widget\n{\n    // TODO: tighten the workspace guard here\n    public function run(): void {}\n}\n",
        );
        $this->initRepo($repo);

        return $repo;
    }

    /**
     * A repo with N distinct signal-bearing files (each a unique TODO) so a multi-signal
     * cycle (the cap / kill tests) has the fan-out it needs.
     */
    private function bootMultiSignalRepo(int $n): string
    {
        $repo = sys_get_temp_dir().'/atlas-sc-f4-multi-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($repo.'/app/Services', 0777, true, true);
        for ($i = 1; $i <= $n; $i++) {
            File::put(
                $repo.'/app/Services/F'.$i.'.php',
                "<?php\n\nnamespace App\\Services;\n\nclass F{$i}\n{\n    // TODO: resolve the guard cursor enforcement {$i}\n    public function run(): void {}\n}\n",
            );
        }
        $this->initRepo($repo);

        return $repo;
    }

    private function initRepo(string $repo): void
    {
        $this->git($repo, ['init', '-q']);
        $this->git($repo, ['symbolic-ref', 'HEAD', 'refs/heads/main']);
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
    }

    /**
     * @return list<string>
     */
    private function branches(string $repo): array
    {
        $out = $this->git($repo, ['branch', '--format=%(refname:short)']);

        return array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $repo, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $repo);
        $p->run();

        return $p->getOutput();
    }

    /**
     * Crude comment/string stripper for the structural grep: removes // line comments,
     * # comments, block comments, and single/double-quoted string literals so only
     * EXECUTABLE tokens remain. Good enough to prove there is no git merge/push/checkout
     * CALL (prose in docblocks must not trip the assertion).
     */
    private function stripCommentsAndStrings(string $code): string
    {
        // Block comments (incl. docblocks).
        $code = preg_replace('#/\*.*?\*/#s', ' ', $code) ?? $code;
        // Line comments (// and #).
        $code = preg_replace('#//.*$#m', ' ', $code) ?? $code;
        $code = preg_replace('/^\s*#.*$/m', ' ', $code) ?? $code;
        // String literals (single + double quoted, non-greedy, escaped-quote tolerant).
        $code = preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '""', $code) ?? $code;
        $code = preg_replace("/'(?:\\\\.|[^'\\\\])*'/s", "''", $code) ?? $code;

        return $code;
    }
}
