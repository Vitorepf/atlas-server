<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE S1 (safety gate) — proves the self-edit park gate: a CERTIFIED proposal that WOULD merge (its frozen
 * contract re-proves GREEN) is PARKED for operator review, never merged, the moment it carries the
 * is_self_improvement marker — while an identical UNMARKED proposal still merges (byte-identical). This closes
 * the adversarial finding that certified self-edits auto-merged unreviewed.
 */
final class AtlasLoopSelfImprovementParkGateTest extends TestCase
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
        AtlasLoopProposal::query()->delete();
        config(['atlas.ai.loop.auto_merge_to_main' => true]);
        config(['atlas.ai.loop.value_gate_enabled' => false]);
        config(['atlas.ai.loop.substance_floor_enabled' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function git(string $cwd, array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $cwd))->run();
    }

    private function repo(string $original): string
    {
        $d = sys_get_temp_dir().'/atlas-selfimprove-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', $original);
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        $allowed = (array) config('atlas.ai.loop.multi_repo.allowed_repos', []);
        $allowed[] = realpath($d) ?: $d;
        config(['atlas.ai.loop.multi_repo.allowed_repos' => array_values(array_unique($allowed))]);

        return $d;
    }

    private function greenDiffRepo(): array
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repo($original);
        file_put_contents($repo.'/snippet.php', "<?php\nfunction val(){ return 2; }\n");
        $p = new Process(['git', 'diff'], $repo);
        $p->run();
        $diff = $p->getOutput();
        // restore the base so the diff applies cleanly during reprove.
        file_put_contents($repo.'/snippet.php', $original);

        return [$repo, $diff];
    }

    private function certifiedProposal(string $diff, string $hash, array $extraQuality = []): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'self-improve park proof',
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
            'diff_text' => $diff,
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => array_merge(['_acceptance_contract' => [
                'commands' => ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
                'allowed_globs' => ['snippet.php'],
                'frozen_globs' => ['composer.json'],
                'metric_kind' => 'gate',
            ]], $extraQuality),
        ]);
    }

    public function test_an_unmarked_proposal_still_merges_byte_identical(): void
    {
        [$repo, $diff] = $this->greenDiffRepo();
        $proposal = $this->certifiedProposal($diff, 'si-control-1');

        app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertTrue((bool) $proposal->fresh()->merged_to_main, 'a normal proposal still merges (byte-identical)');
    }

    public function test_a_self_improvement_proposal_is_parked_never_merged(): void
    {
        [$repo, $diff] = $this->greenDiffRepo();
        // SAME green-reproving contract, but marked as a self-edit => must be parked, never merged.
        $proposal = $this->certifiedProposal($diff, 'si-marked-1', ['_is_self_improvement' => true]);

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main, 'a self-improvement proposal NEVER auto-merges');
        $this->assertNotNull($fresh->reviewed_at, 'it is parked for operator review (leaves the drain queue)');
        $review = (array) ($fresh->quality['_operator_review'] ?? []);
        $this->assertSame('self_improvement', $review['reason'] ?? null, 'parked with the self_improvement reason');
    }
}
