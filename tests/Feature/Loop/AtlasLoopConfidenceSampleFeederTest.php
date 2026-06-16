<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopConfidenceSample;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ITEM10 — the confidence-calibration OUTCOME FEEDER. After a REAL merge, with the calibration flag ON,
 * exactly one {predicted, correct} sample is persisted (predicted = the cert-time delivery_confidence
 * threaded into proposal.quality; correct = the canary verdict). With the flag OFF, zero rows
 * (byte-identical). The feeder is fail-open: a telemetry write never unwinds a completed merge.
 */
final class AtlasLoopConfidenceSampleFeederTest extends TestCase
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
        if (! Schema::hasTable('atlas_loop_confidence_samples')) {
            (require base_path('database/migrations/2026_06_15_000100_create_atlas_loop_confidence_samples_table.php'))->up();
        }

        config(['atlas.ai.loop.auto_merge_to_main' => true]);
        config(['atlas.ai.loop.impact_receipts_enabled' => true]);
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

    public function test_feeder_records_one_sample_when_flag_on(): void
    {
        config(['atlas.loop.confidence_calibration.enabled' => true]);

        $proposal = $this->mergeFixtureWithPredictedConfidence(0.94);
        $result = app(AtlasLoopAutoMergeService::class)->drain($this->repoPath, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));

        $samples = AtlasLoopConfidenceSample::query()->get();
        $this->assertCount(1, $samples, 'exactly one calibration sample after a successful merge');
        $sample = $samples->first();
        $this->assertEqualsWithDelta(0.94, (float) $sample->predicted, 0.0001);
        $this->assertTrue((bool) $sample->correct, 'a green/no-run canary is a correct outcome');
        $this->assertSame((string) $proposal->id, (string) $sample->proposal_id);
    }

    public function test_feeder_is_inert_when_flag_off(): void
    {
        config(['atlas.loop.confidence_calibration.enabled' => false]);

        $this->mergeFixtureWithPredictedConfidence(0.94);
        $result = app(AtlasLoopAutoMergeService::class)->drain($this->repoPath, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        $this->assertSame(0, AtlasLoopConfidenceSample::query()->count(), 'flag OFF => no sampling (byte-identical)');
    }

    public function test_feeder_no_ops_when_no_predicted_confidence(): void
    {
        // The grinder threads delivery_confidence only for graded certs; an old proposal without it
        // must no-op (predicted 0.0) — safe but inert, never a spurious sample.
        config(['atlas.loop.confidence_calibration.enabled' => true]);

        $this->mergeFixtureWithPredictedConfidence(null);
        $result = app(AtlasLoopAutoMergeService::class)->drain($this->repoPath, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        $this->assertSame(0, AtlasLoopConfidenceSample::query()->count(), 'no predicted confidence => no sample');
    }

    private string $repoPath = '';

    private function mergeFixtureWithPredictedConfidence(?float $confidence): AtlasLoopProposal
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $this->repoPath = $this->repo($original);
        $diff = $this->makeDiff($original, $modified, $this->repoPath);

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'feeder proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        $quality = ['_acceptance_contract' => [
            'commands' => ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
            'allowed_globs' => ['snippet.php'],
            'frozen_globs' => ['composer.json'],
            'metric_kind' => 'gate',
        ]];
        if ($confidence !== null) {
            // Mirror the grinder threading delivery_confidence into proposal.quality.
            $quality['delivery_confidence'] = ['confidence' => $confidence, 'threshold' => 0.93, 'passes' => true];
        }

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'fix val to 2',
            'target_path' => 'snippet.php',
            'diff_text' => $diff,
            'proposal_hash' => 'feeder-'.bin2hex(random_bytes(4)),
            'metric' => null,
            'quality' => $quality,
        ]);
    }

    private function repo(string $original): string
    {
        $d = sys_get_temp_dir().'/atlas-feeder-'.bin2hex(random_bytes(4));
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

    private function makeDiff(string $original, string $modified, string $repo): string
    {
        file_put_contents($repo.'/snippet.php', $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();
        file_put_contents($repo.'/snippet.php', $original);

        return $p->getOutput();
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
