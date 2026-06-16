<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE S1 — END-TO-END marker propagation: a self-improvement TASK (payload.is_self_improvement=true) carried
 * through certify() stamps proposal.quality._is_self_improvement=true (the marker is NEVER set directly on the
 * proposal — it can only arrive from the operator-armed task payload), and the live drain PARKS that proposal
 * (never merges), while an identical UNMARKED task still merges (byte-identical) and the >=9 quality_bar value
 * cannot be used to dodge the park.
 */
final class AtlasLoopSelfImprovementMarkerE2ETest extends TestCase
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
        AtlasLoopTask::query()->delete();
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

    private function greenDiffRepo(): array
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $d = sys_get_temp_dir().'/atlas-s1e2e-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', $original);
        (new Process(['git', 'init', '-q'], $d))->run();
        (new Process(['git', 'add', '-A'], $d))->run();
        (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign'], $d))->run();
        file_put_contents($d.'/snippet.php', "<?php\nfunction val(){ return 2; }\n");
        $p = new Process(['git', 'diff'], $d);
        $p->run();
        $diff = $p->getOutput();
        file_put_contents($d.'/snippet.php', $original);
        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        $allowed = (array) config('atlas.ai.loop.multi_repo.allowed_repos', []);
        $allowed[] = realpath($d) ?: $d;
        config(['atlas.ai.loop.multi_repo.allowed_repos' => array_values(array_unique($allowed))]);

        return [$d, $diff];
    }

    private function task(array $payload): AtlasLoopTask
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 's1 e2e',
            'config' => [],
            'max_seconds' => 60,
        ]);

        return AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'source' => 'discovery',
            'objective' => 'improve snippet',
            'target_path' => 'snippet.php',
            'payload' => $payload,
            'status' => AtlasLoopTask::STATUS_DONE,
            'priority' => 1,
            'attempts' => 1,
            'max_attempts' => 3,
            'dedupe_key' => substr(hash('sha256', (string) Str::uuid()), 0, 60),
        ]);
    }

    private function certify(AtlasLoopTask $task, string $diff, string $hash): AtlasLoopProposal
    {
        AtlasLoopProposal::$governedMergeInProgress = false;

        return app(AtlasLoopStore::class)->certifyProposal($task, [
            'diff_text' => $diff,
            'proposal_hash' => $hash,
            'acceptance_contract' => [
                'commands' => ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
                'allowed_globs' => ['snippet.php'],
                'frozen_globs' => ['composer.json'],
                'metric_kind' => 'gate',
            ],
        ]);
    }

    public function test_marker_flows_from_task_payload_to_proposal_quality_and_the_drain_parks_it(): void
    {
        [$repo, $diff] = $this->greenDiffRepo();
        $task = $this->task(['is_self_improvement' => true, 'quality_bar' => 9]);

        $proposal = $this->certify($task, $diff, 's1e2e-marked');
        // the marker arrived via certify() from the task payload — NOT set directly on the proposal.
        $this->assertTrue(($proposal->quality['_is_self_improvement'] ?? false) === true, 'certify stamps the marker from the task payload');
        $this->assertSame(9, $proposal->quality['_quality_bar'] ?? null, 'the human-frozen bar rides as pure metadata');

        app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main, 'a self-improvement proposal is NEVER merged');
        $this->assertNotNull($fresh->reviewed_at, 'it is parked for operator review');
        $this->assertSame('self_improvement', $fresh->quality['_operator_review']['reason'] ?? null);
    }

    public function test_an_unmarked_task_yields_no_marker_and_still_merges_byte_identical(): void
    {
        [$repo, $diff] = $this->greenDiffRepo();
        $task = $this->task(['some' => 'ordinary']); // no is_self_improvement

        $proposal = $this->certify($task, $diff, 's1e2e-control');
        $this->assertArrayNotHasKey('_is_self_improvement', (array) $proposal->quality, 'no marker on an ordinary proposal (byte-identical)');

        app(AtlasLoopAutoMergeService::class)->drain($repo, 5);
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main, 'an ordinary proposal still merges');
    }

    public function test_a_low_quality_bar_cannot_dodge_the_park(): void
    {
        [$repo, $diff] = $this->greenDiffRepo();
        // a bargain-basement bar must NOT let a self-edit slip through — the park keys on the boolean only.
        $task = $this->task(['is_self_improvement' => true, 'quality_bar' => 1.0]);

        $proposal = $this->certify($task, $diff, 's1e2e-lowbar');
        app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertFalse((bool) $proposal->fresh()->merged_to_main, 'a low _quality_bar still parks — the gate ignores the bar value');
    }
}
