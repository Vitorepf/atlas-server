<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Policy\PolicyCanon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * L6-14 capstone — the REAL-HISTORY FEED frozen test.
 *
 * The mechanism (ladder + release gate + admission re-bind) was already proven. The hole
 * this freezes is the one the doc flagged honest: nothing fed the CANONICAL ladder log
 * from real merges, so in production the class streak sat at 0 forever and could never
 * auto-green on real history. This proves the wired feed:
 *
 *   1. END-TO-END through the REAL Loop auto-merge pipeline: three real documentation_only
 *      merges land in main and auto-record clean_promotions keyed on the REAL commit SHAs,
 *      and that real history makes Admission allow_autonomous for the allowlisted class.
 *   2. The SAME production method (recordMergeOutcome) records a REVERT on a red post-merge
 *      canary — the v2 regression signal — and that revert resets the class streak to zero
 *      AND revokes autonomy at Admission. Asymmetric trust, from real history.
 *   3. The feed REFUSES TO FABRICATE: no-op when disabled (default), no clean promotion
 *      without a real landed commit, and replaying the same commit cannot inflate.
 *
 * Frozen invariants exercised: governed merge door (only the service marks merged),
 * never-merge untouched, evidence-only accrual, asymmetric revert, default-max-friction.
 */
final class AtlasLoopTrustLadderRealHistoryFeedTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private string $ledger = '';

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

        $this->ledger = sys_get_temp_dir().'/atlas-l6-14-feed-'.bin2hex(random_bytes(4)).'.jsonl';
        File::delete($this->ledger);

        config([
            'atlas.ai.loop.auto_merge_to_main' => true,
            'atlas.ai.loop.impact_receipts_enabled' => true,
            // Trust ladder ON, with the operator's L6-14 documentation_only allowlist + thresholds,
            // and the canonical ledger pinned to an isolated temp file (the real production seam).
            'atlas.ai.trust_ladder.enabled' => true,
            'atlas.ai.trust_ladder.log_path' => $this->ledger,
            'atlas.ai.trust_ladder.thresholds' => ['draft' => 1, 'execute_with_approval' => 2, 'autonomous' => 3],
            'atlas.ai.trust_ladder.eligible_classes' => ['documentation_only', 'tests_only', 'docs_and_tests'],
            'atlas.ai.trust_ladder.blocked_class_patterns' => [
                'never_merge', 'merge_gate', 'constitutional_kernel', 'harness_guard',
                'frozen_judge', 'formal_invariant', 'kernel',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->ledger);
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    public function test_real_doc_merges_auto_record_clean_promotions_and_the_class_earns_autonomy_end_to_end(): void
    {
        // Three real, distinct documentation_only merges land in main through the REAL
        // auto-merge pipeline. Each feeds the canonical ladder a clean_promotion keyed on
        // the REAL commit SHA. (Docs have no sibling test, so the canary never runs => clean.)
        $commits = [];
        for ($i = 1; $i <= 3; $i++) {
            $result = $this->drainOneDocMerge("doc body v{$i}\n");
            $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
            $commit = (string) ($result['results'][0]['commit'] ?? '');
            $this->assertNotSame('', $commit, 'a real merge must produce a commit');
            $this->assertFalse((bool) ($result['results'][0]['canary']['ran'] ?? true), 'docs have no sibling canary');
            $commits[] = $commit;
        }

        $clean = $this->ledgerEntries('documentation_only', AtlasChangeClassTrustLadder::EVIDENCE_CLEAN_PROMOTION);
        $this->assertCount(3, $clean, 'three real doc merges => three clean promotions on the canonical ledger');
        foreach ($clean as $idx => $e) {
            // Evidence-only: the ref is the REAL commit SHA, never a synthetic placeholder.
            $this->assertSame('commit:'.$commits[$idx], $e['ref']);
            $this->assertTrue((bool) $e['clean']);
        }

        // The class auto-greens from REAL history: streak past the operator threshold, and
        // Admission (re-bound under the risk cap) now allows autonomous for this class.
        $ladder = $this->ladder();
        $this->assertSame(3, $ladder->cleanStreak('documentation_only'));
        $this->assertSame(PolicyCanon::AUTONOMY_AUTONOMOUS, $ladder->earnedAutonomy('documentation_only'));

        $env = $this->admit('documentation_only');
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS, $env['decision']);
        $this->assertFalse((bool) $env['requires_human_approval']);

        // HARD INVARIANT: the governed door is the ONLY thing that marked merged; no proposal
        // outside the governed scope was flipped, and nothing claims a merge-gate change.
        $this->assertSame(3, AtlasLoopProposal::query()->where('merged_to_main', true)->count());
    }

    public function test_a_red_post_merge_canary_records_a_revert_that_resets_the_class_and_revokes_autonomy(): void
    {
        // Earn the streak from REAL doc merges first (auto-green).
        for ($i = 1; $i <= 3; $i++) {
            $this->drainOneDocMerge("doc body v{$i}\n");
        }
        $ladder = $this->ladder();
        $this->assertSame(3, $ladder->cleanStreak('documentation_only'));
        $this->assertSame(PolicyCanon::AUTONOMY_AUTONOMOUS, $ladder->earnedAutonomy('documentation_only'));

        // A regression: the SAME production method the merge pipeline calls, with a RED
        // post-merge canary outcome (the v2 regression signal — fix-forward, never auto-
        // revert). It must asymmetrically revoke the class trust from real history.
        $recordedClass = $ladder->recordMergeOutcome(
            ['docs/guide.md'],
            'deadbeefcafe',
            ['ran' => true, 'passed' => false, 'target' => 'tests/Unit/Docs/GuideTest.php'],
        );
        $this->assertSame('documentation_only', $recordedClass);

        // Trust is asymmetric: one real red canary returns the class to MAX friction.
        $fresh = $this->ladder();
        $this->assertSame(0, $fresh->cleanStreak('documentation_only'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $fresh->earnedAutonomy('documentation_only'));

        $reverts = $this->ledgerEntries('documentation_only', AtlasChangeClassTrustLadder::EVIDENCE_REVERT);
        $this->assertCount(1, $reverts, 'the red canary recorded exactly one revert on the canonical ledger');
        $this->assertFalse((bool) $reverts[0]['clean']);
        $this->assertSame('canary-red:deadbeefcafe', $reverts[0]['ref']);

        // Regression revoked autonomy through Admission too.
        $env = $this->admit('documentation_only');
        $this->assertNotSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS, $env['decision']);
        $this->assertTrue((bool) $env['requires_human_approval']);
    }

    public function test_the_feed_refuses_to_fabricate_when_disabled_or_without_a_real_commit_and_cannot_inflate(): void
    {
        // Disabled (the production default): a real merge accrues NOTHING — no fabricated green.
        config(['atlas.ai.trust_ladder.enabled' => false]);
        $result = $this->drainOneDocMerge("doc body off\n");
        $this->assertSame(1, $result['merged_count']);
        $this->assertSame([], $this->ledgerEntries(), 'disabled ladder records nothing from a real merge');

        // Re-enable. Evidence-only + distinct-ref guards make the green un-fabricable.
        config(['atlas.ai.trust_ladder.enabled' => true]);
        $ladder = $this->ladder();
        $green = ['ran' => true, 'passed' => true, 'target' => null];

        $ladder->recordMergeOutcome(['docs/x.md'], 'abc123', $green);
        $ladder->recordMergeOutcome(['docs/x.md'], 'abc123', $green); // replay — same SHA
        $this->assertSame(1, $ladder->cleanStreak('documentation_only'), 'replaying the same commit cannot inflate the streak');

        // No commit landed => no clean promotion fabricated (nothing happened to re-check).
        $this->assertSame('', $ladder->recordMergeOutcome(['docs/y.md'], null, $green));
        $this->assertSame('', $ladder->recordMergeOutcome(['docs/y.md'], '', $green));
        $this->assertSame(1, $ladder->cleanStreak('documentation_only'));

        // A code file in the set demotes the whole merge to `code` (never allowlisted by
        // default) — it accrues HONEST history but earns nothing until the operator
        // allowlists the class. This is why ordinary code merges can never silently self-trust.
        $this->assertSame('code', $ladder->classifyChangedFiles(['docs/z.md', 'app/Foo.php']));
        $ladder->recordMergeOutcome(['docs/z.md', 'app/Foo.php'], 'codecommit', $green);
        $this->assertSame(1, $ladder->cleanStreak('code'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $ladder->earnedAutonomy('code'));
    }

    // ---------- harness ----------

    private function ladder(): AtlasChangeClassTrustLadder
    {
        return app(AtlasChangeClassTrustLadder::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function admit(string $class): array
    {
        $admission = new AtlasAutonomyAdmissionService(app(AtlasConstitutionalKernelService::class));
        $admission->setTicketsLogPathForTesting(sys_get_temp_dir().'/atlas-l6-14-feed-admit-'.bin2hex(random_bytes(4)).'.jsonl');
        $admission->setChangeClassLadder($this->ladder());

        return $admission->admit([
            'actor' => 'atlas-l6-14-feed-test',
            'change_kind' => $class,
            'change_class' => $class,
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'risk_level' => PolicyCanon::RISK_LOW,
            'proposed_effect' => 'real-history feed verification',
            'scope' => ['privacy_class' => 'public'],
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ledgerEntries(string $class = '', string $kind = ''): array
    {
        if (! is_file($this->ledger)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', (string) file_get_contents($this->ledger)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }
            if ($class !== '' && ($row['change_class'] ?? '') !== $class) {
                continue;
            }
            if ($kind !== '' && ($row['evidence_kind'] ?? '') !== $kind) {
                continue;
            }
            $out[] = $row;
        }

        return array_values($out);
    }

    /**
     * Stage one real documentation_only proposal whose acceptance contract re-proves green,
     * and drain it to main through the REAL pipeline.
     *
     * @return array<string,mixed>
     */
    private function drainOneDocMerge(string $modifiedBody): array
    {
        $repo = $this->docRepo("doc body original\n", $modifiedBody);

        return app(AtlasLoopAutoMergeService::class)->drain($repo, 5);
    }

    /**
     * Build an isolated foreign repo containing a docs/guide.md target, register it on the
     * multi-repo allow-list (the governed door), and persist a certified proposal whose
     * acceptance contract trivially re-proves green so the real merge pipeline runs end to end.
     */
    private function docRepo(string $original, string $modified): string
    {
        $d = sys_get_temp_dir().'/atlas-l6-14-repo-'.bin2hex(random_bytes(4));
        mkdir($d.'/docs', 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/docs/guide.md', $original);

        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        $allowed = (array) config('atlas.ai.loop.multi_repo.allowed_repos', []);
        $allowed[] = realpath($d) ?: $d;
        config(['atlas.ai.loop.multi_repo.allowed_repos' => array_values(array_unique($allowed))]);

        $diff = $this->diffFor($d, $modified);
        $this->makeCertifiedDocProposal($diff, bin2hex(random_bytes(6)));

        return $d;
    }

    private function diffFor(string $repo, string $modified): string
    {
        file_put_contents($repo.'/docs/guide.md', $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();
        $diff = $p->getOutput();
        // Restore so the apply in the real pipeline starts from the committed state.
        $this->git($repo, ['checkout', '--', 'docs/guide.md']);

        return $diff;
    }

    private function makeCertifiedDocProposal(string $diff, string $hash): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l6-14 real-history feed proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'improve docs/guide.md',
            'target_path' => 'docs/guide.md',
            'diff_text' => $diff,
            'proposal_hash' => $hash,
            'metric' => null,
            // Trivial gate contract that always re-proves green (the doc file exists),
            // exercising the real reprove path without a provider.
            'quality' => ['_acceptance_contract' => [
                'commands' => ["php -r \"exit(is_file('docs/guide.md')?0:1);\""],
                'allowed_globs' => ['docs/guide.md'],
                'frozen_globs' => ['composer.json'],
                'metric_kind' => 'gate',
            ]],
        ]);
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();

        return trim($p->getOutput());
    }
}
