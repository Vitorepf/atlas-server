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

    public function test_refuses_a_thin_spec_when_amplification_floor_is_on(): void
    {
        // Lever 5: with the spec-amplification floor armed, a feature whose acceptance test is too thin to
        // pin a complex behaviour is refused — the spec must be amplified before provider budget is spent.
        config(['atlas.loop.spec_amplification.min_assertions' => 3, 'atlas.loop.spec_amplification.min_methods' => 2]);
        $campaign = $this->campaign();

        $thinPath = 'storage/framework/testing/atlas-thin-spec-'.bin2hex(random_bytes(4)).'.php';
        $abs = base_path($thinPath);
        @mkdir(dirname($abs), 0o755, true);
        file_put_contents($abs, "<?php\nfinal class ThinTest extends TestCase {\n    public function test_it(): void { \$this->assertTrue(true); }\n}\n");

        try {
            $this->artisan('atlas:loop:seed-feature', [
                '--campaign-id' => (string) $campaign->id,
                '--name' => 'Complex feature',
                '--spec' => 'A genuinely complex behaviour with many edge cases that one assertion cannot pin.',
                '--test' => $thinPath,
                '--files' => ['app/Http/Controllers/ComplexController.php'],
            ])->assertExitCode(2); // INVALID — spec too thin

            $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->count(), 'a thin-spec feature is not enqueued');
        } finally {
            @unlink($abs);
        }
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
