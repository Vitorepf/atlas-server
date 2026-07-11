<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ENG-05 — single landing authority: one main-merge lock for every main writer and
 * no derived-evidence fallback on the secondary Autonomos landing port.
 */
final class LandingAuthoritySingleLockTest extends TestCase
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
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    public function test_committer_refuses_when_main_merge_lock_is_held(): void
    {
        $repo = $this->repo();
        $held = fopen($repo.'/.git/'.AtlasLoopMergeActuator::LOCK_BASENAME, 'c');
        $this->assertTrue(flock($held, LOCK_EX));

        @file_put_contents($repo.'/docs/eng05.md', "eng05\n");
        $committer = new AtlasTaskScopedCommitter(null, $repo);
        $result = $committer->commitScope(['docs/eng05.md'], 'task-lock-a', 'client-a', 'eng05 lock');

        flock($held, LOCK_UN);
        fclose($held);

        $this->assertFalse($result['committed']);
        $this->assertSame('commit_lock_contended', $result['reason']);
    }

    public function test_secondary_port_defers_when_main_merge_lock_is_held(): void
    {
        $repo = $this->repo();
        $proposal = $this->certifiedProposal('lock-cross-1');
        config(['atlas.loop.main_merge_lock_timeout_seconds' => 0.2]);

        $held = fopen($repo.'/.git/'.AtlasLoopMergeActuator::LOCK_BASENAME, 'c');
        $this->assertTrue(flock($held, LOCK_EX));

        $headBefore = $this->headSha($repo);
        $svc = app(AtlasLoopAutoMergeService::class);
        $merge = new ReflectionMethod($svc, 'mergeOne');
        $merge->setAccessible(true);
        $res = $merge->invoke($svc, $proposal, $repo, false, null, null, null);

        flock($held, LOCK_UN);
        fclose($held);

        $this->assertFalse($res['merged']);
        $this->assertStringContainsString('main_merge_lock_timeout_requeued', (string) $res['reason']);
        $this->assertSame($headBefore, $this->headSha($repo));

        $proposal->refresh();
        $this->assertSame(1, (int) data_get($proposal->quality, '_lock_deferrals.count'));
        $this->assertNull($proposal->reviewed_at);
    }

    public function test_secondary_port_refuses_when_threaded_execution_evidence_is_absent(): void
    {
        $proposal = $this->certifiedProposal('sovereign-missing-evidence', [
            '_acceptance_contract' => ['commands' => ['true'], 'allowed_globs' => ['snippet.php']],
        ]);
        $canary = [
            'ran' => true,
            'commands' => ['vendor/bin/phpunit tests/Unit/ExampleTest.php'],
            'tests_run' => 3,
            'assertions_executed' => 5,
            'target' => 'tests/Unit/ExampleTest.php',
        ];

        $svc = app(AtlasLoopAutoMergeService::class);
        $verdictMethod = new ReflectionMethod($svc, 'sovereignGateVerdict');
        $verdictMethod->setAccessible(true);
        $result = $verdictMethod->invoke($svc, $proposal, ['snippet.php'], $canary);

        $this->assertFalse($result['promoted']);
        $this->assertContains('sovereign_evidence_missing', $result['blockers']);
    }

    public function test_committer_and_secondary_port_share_the_same_lock_basename(): void
    {
        $this->assertSame(
            'atlas-main-merge.lock',
            AtlasLoopMergeActuator::LOCK_BASENAME,
            'the scoped committer must serialize on the canonical main-merge lock path',
        );
    }

    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-landing-lock-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/docs-note.md', "seed\n");
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
            'goal' => 'eng05 lock proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'eng05 proof',
            'target_path' => 'snippet.php',
            'diff_text' => "diff --git a/snippet.php b/snippet.php\n",
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => array_merge([
                '_acceptance_contract' => ['commands' => ['true'], 'allowed_globs' => ['snippet.php']],
            ], $extraQuality),
        ]);
    }

    private function headSha(string $repo): string
    {
        $p = new Process(['git', 'rev-parse', 'HEAD'], $repo);
        $p->run();

        return trim($p->getOutput());
    }
}
