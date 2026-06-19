<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 1 · Slice 1 — the single-file drain's commit now runs UNDER the single path-stable
 * main-merge lock. The proof forces contention THROUGH the lock itself (a real held handle), not through
 * the dirty-tree precondition — so it actually demonstrates serialization, and pins the lock_timeout
 * sentinel so an earlier-skip (reprove/apply/canary) can never masquerade as the lock path.
 */
final class AtlasLoopMainMergeLockWiringTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

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
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-lockwire-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', "<?php\nfunction val(){ return 1; }\n");
        foreach ([['init', '-q'], ['add', '-A'], ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']] as $argv) {
            (new Process(array_merge(['git'], $argv), $d))->run();
        }

        return $d;
    }

    private function certifiedProposal(string $hash, array $extraQuality = []): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'lock-wire proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'fix val to 2',
            'target_path' => 'snippet.php',
            'diff_text' => "diff --git a/snippet.php b/snippet.php\n",
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => array_merge(['_acceptance_contract' => ['commands' => ['true'], 'allowed_globs' => ['snippet.php']]], $extraQuality),
        ]);
    }

    private function headSha(string $repo): string
    {
        $p = new Process(['git', 'rev-parse', 'HEAD'], $repo);
        $p->run();

        return trim($p->getOutput());
    }

    private function isDrainable(AtlasLoopProposal $p): bool
    {
        return AtlasLoopProposal::query()
            ->where('status', AtlasLoopProposal::STATUS_CERTIFIED)
            ->where('merged_to_main', false)
            ->whereNull('reviewed_at')
            ->whereKey($p->getKey())
            ->exists();
    }

    public function test_a_held_lock_makes_the_drain_defer_without_committing_and_stays_drainable(): void
    {
        $repo = $this->repo();
        $proposal = $this->certifiedProposal('lock-defer-1');
        config(['atlas.loop.main_merge_lock_timeout_seconds' => 0.2]);

        // Hold the SINGLE main-merge lock on a real handle — exactly what a concurrent crossing would hold.
        $held = fopen($repo.'/.git/'.AtlasLoopMergeActuator::LOCK_BASENAME, 'c');
        $this->assertTrue(flock($held, LOCK_EX), 'pre-condition: hold the main-merge lock');

        $headBefore = $this->headSha($repo);
        $svc = app(AtlasLoopAutoMergeService::class);
        $merge = new ReflectionMethod($svc, 'mergeOne');
        $merge->setAccessible(true);
        $res = $merge->invoke($svc, $proposal, $repo, false, null, null, null);

        flock($held, LOCK_UN);
        fclose($held);

        // Pinned to the lock path — not an earlier reprove/apply skip (those run INSIDE mergeOneCritical,
        // which never executes because the lock was never acquired).
        $this->assertFalse($res['merged']);
        $this->assertStringContainsString('main_merge_lock_timeout_requeued', (string) $res['reason']);
        $this->assertSame($headBefore, $this->headSha($repo), 'no commit object landed while the lock was held');

        $proposal->refresh();
        $this->assertSame(1, (int) data_get($proposal->quality, '_lock_deferrals.count'));
        $this->assertNull($proposal->reviewed_at, 'a deferral never stamps reviewed_at');
        $this->assertTrue($this->isDrainable($proposal), 'the deferred proposal is re-drainable next pass');
    }

    public function test_K_consecutive_deferrals_park_for_operator_review(): void
    {
        $repo = $this->repo();
        config(['atlas.loop.main_merge_lock_timeout_seconds' => 0.15, 'atlas.loop.main_merge_lock_max_deferrals' => 5]);
        // Already deferred 4× — the next miss is the 5th ⇒ park (never an infinite spin).
        $proposal = $this->certifiedProposal('lock-park-1', ['_lock_deferrals' => ['count' => 4]]);

        $held = fopen($repo.'/.git/'.AtlasLoopMergeActuator::LOCK_BASENAME, 'c');
        flock($held, LOCK_EX);

        $svc = app(AtlasLoopAutoMergeService::class);
        $merge = new ReflectionMethod($svc, 'mergeOne');
        $merge->setAccessible(true);
        $res = $merge->invoke($svc, $proposal, $repo, false, null, null, null);

        flock($held, LOCK_UN);
        fclose($held);

        $this->assertStringContainsString('lock_contention_starvation', (string) $res['reason']);
        $proposal->refresh();
        $this->assertNotNull($proposal->reviewed_at, 'starvation park terminates drainability');
        $this->assertFalse($this->isDrainable($proposal));
    }

    public function test_structural_lock_failure_stays_drainable_without_bumping_the_counter(): void
    {
        $repo = $this->repo();
        $proposal = $this->certifiedProposal('lock-struct-1');
        $svc = app(AtlasLoopAutoMergeService::class);
        $handler = new ReflectionMethod($svc, 'handleMainMergeLockMiss');
        $handler->setAccessible(true);

        // A non-timeout reason (repo gone / lock open failed) is surfaced as-is; the row stays drainable and
        // the deferral counter is untouched (only genuine timeouts count toward starvation).
        $res = $handler->invoke($svc, $proposal, $repo, 'repo_not_git');

        $this->assertStringContainsString('main_merge_repo_not_git', (string) $res['reason']);
        $proposal->refresh();
        $this->assertSame(0, (int) data_get($proposal->quality, '_lock_deferrals.count', 0));
        $this->assertTrue($this->isDrainable($proposal));
    }
}
