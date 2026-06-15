<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lever 3 — the feature lane is operator-usable end to end: seeding a feature enqueues a grindable
 * `feature` task with the diff_earned anti-gaming contract, and missing inputs are rejected.
 */
final class AtlasLoopSeedFeatureCommandTest extends TestCase
{
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

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'feature seed test',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    public function test_seeds_a_grindable_feature_task(): void
    {
        $campaign = $this->campaign();

        $this->artisan('atlas:loop:seed-feature', [
            '--campaign-id' => (string) $campaign->id,
            '--name' => 'Rate-limit export',
            '--spec' => 'POST /export rejects a 4th request within 60s with HTTP 429.',
            '--test' => 'tests/Feature/Export/ExportRateLimitTest.php',
            '--files' => ['app/Http/Controllers/ExportController.php', 'app/Support/SlidingWindowLimiter.php'],
        ])->assertExitCode(0);

        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('feature', $payload['objective_kind']);
        $this->assertSame('framework', $payload['materializer']);
        $this->assertTrue($payload['acceptance']['revert_recheck'], 'diff_earned anti-gaming is on');
        $this->assertContains('tests/Feature/Export/ExportRateLimitTest.php', $payload['acceptance']['frozen_globs']);
        $this->assertSame('operator_seed:feature', $task->source);
    }

    public function test_rejects_missing_required_options(): void
    {
        $campaign = $this->campaign();

        $this->artisan('atlas:loop:seed-feature', [
            '--campaign-id' => (string) $campaign->id,
            '--name' => 'x',
            // no --spec, --test, --files
        ])->assertExitCode(2); // INVALID

        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->count(), 'nothing enqueued on invalid input');
    }
}
