<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTarget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * L4-7: fila de revisão do operador para propostas parqueadas.
 */
final class AtlasLoopOperatorReviewQueueTest extends TestCase
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
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (Schema::hasTable('atlas_loop_proposals') && ! Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }
        config(['atlas.loop.operator_review.enabled' => true]);
        foreach (['atlas_loop_targets', 'atlas_loop_proposals', 'atlas_loop_tasks', 'atlas_loop_campaigns'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        AtlasLoopProposal::$governedMergeInProgress = false;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        AtlasLoopProposal::$governedMergeInProgress = false;
        parent::tearDown();
    }

    public function test_lists_and_rejects_parked_proposal_with_ranking_feedback(): void
    {
        $campaign = $this->campaign();
        $proposal = $this->parkedProposal($campaign, 'diff --git a/x b/x', 'review-reject-1');
        AtlasLoopTarget::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => (string) $proposal->target_path,
            'target_key' => hash('sha256', $campaign->id.'|'.$proposal->target_path),
            'content_hash' => hash('sha256', 'x'),
            'status' => AtlasLoopTarget::STATUS_CANDIDATE,
            'score' => 1.0,
            'self_contained_score' => 1.0,
            'improvement_score' => 1.0,
            'novelty_score' => 1.0,
            'signals' => [],
            'lineage' => [],
        ]);

        Artisan::call('atlas:loop:operator-review', ['--json' => true]);
        $queue = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $queue['status']);
        $this->assertSame(1, $queue['count']);
        $this->assertSame((string) $proposal->getKey(), $queue['items'][0]['id']);
        $this->assertSame('forbidden_self_target', $queue['items'][0]['reason']);

        $exit = Artisan::call('atlas:loop:operator-review', [
            '--action' => 'reject',
            '--proposal' => (string) $proposal->getKey(),
            '--operator' => 'operator-test',
            '--reason' => 'not worth changing the judge',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('rejected', $payload['status']);
        $fresh = $proposal->fresh();
        $this->assertSame('rejected', $fresh->quality['_operator_review']['status']);
        $this->assertSame('downrank_or_quarantine', $fresh->quality['_ranking_feedback']['action']);
        $target = AtlasLoopTarget::query()->where('campaign_id', $campaign->id)->where('target_path', $proposal->target_path)->first();
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target?->status);

        Artisan::call('atlas:loop:operator-review', ['--json' => true]);
        $after = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $after['count'], 'rejeitada sai da fila');
    }

    public function test_approved_parked_proposal_merges_through_governed_path(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->repo($target, $original);
        $diff = $this->diffFor($repo, $target, $modified);
        $campaign = $this->campaign();
        $proposal = $this->parkedProposal($campaign, $diff, 'review-approve-1', $target, [
            'commands' => ["php -r \"require '{$target}'; exit(val()===2?0:1);\""],
            'allowed_globs' => [$target],
            'frozen_globs' => ['composer.json'],
            'metric_kind' => 'gate',
        ]);

        $exit = Artisan::call('atlas:loop:operator-review', [
            '--action' => 'approve',
            '--proposal' => (string) $proposal->getKey(),
            '--operator' => 'operator-test',
            '--reason' => 'reviewed judge diff',
            '--approve' => true,
            '--base' => $repo,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('merged', $payload['status'], json_encode($payload));
        $this->assertSame($modified, file_get_contents($repo.'/'.$target));
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
        $this->assertSame('merged', $proposal->fresh()->quality['_operator_review']['status']);
        $this->assertStringContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']));
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l4-7-operator-review-test',
            'base_workspace' => base_path(),
            'config' => [],
            'max_seconds' => 3600,
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $acceptance
     */
    private function parkedProposal(AtlasLoopCampaign $campaign, string $diff, string $hash, string $target = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', ?array $acceptance = null): AtlasLoopProposal
    {
        return AtlasLoopProposal::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'operator review parked proposal',
            'target_path' => $target,
            'diff_text' => $diff,
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => [
                '_operator_review' => [
                    'schema_version' => 'atlas.loop.operator_review.v1',
                    'status' => 'parked_for_operator_review',
                    'reason' => 'forbidden_self_target',
                    'operator_id' => 'auto_merge',
                    'reviewed_at' => now()->toIso8601String(),
                    'decision' => 'park',
                ],
                '_acceptance_contract' => $acceptance ?? [],
            ],
            'reviewed_at' => now(),
        ]);
    }

    private function repo(string $path, string $contents): string
    {
        $dir = sys_get_temp_dir().'/atlas-operator-review-'.bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        mkdir(dirname($dir.'/'.$path), 0o755, true);
        file_put_contents($dir.'/'.$path, $contents);
        file_put_contents($dir.'/composer.json', "{}\n");
        $this->git($dir, ['init', '-q']);
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        return $dir;
    }

    private function diffFor(string $repo, string $path, string $modified): string
    {
        file_put_contents($repo.'/'.$path, $modified);
        $diff = $this->git($repo, ['diff']);
        $this->git($repo, ['checkout', '--', $path]);

        return $diff;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): string
    {
        $process = new Process(array_merge(['git'], $argv), $cwd);
        $process->run();

        return trim($process->getOutput());
    }
}
