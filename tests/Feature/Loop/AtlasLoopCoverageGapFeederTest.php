<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageGapFeeder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The feeder turns a coverage gap into a grindable characterization_test task whose contract INVERTS
 * a refactor's: the provider may edit only the sibling TEST while the production target is frozen.
 */
final class AtlasLoopCoverageGapFeederTest extends TestCase
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
            'goal' => 'feeder test',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    public function test_feeds_a_characterization_task_with_inverted_allow_frozen_contract(): void
    {
        $campaign = $this->campaign();
        $feeder = app(AtlasLoopCoverageGapFeeder::class);

        $taskId = $feeder->feedGap((string) $campaign->id, [
            'target_file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilder.php',
            'decision_operator' => 'strict_equals',
            'mutation_id' => 'c054b759ca7c3ee5',
            'sibling_test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilderTest.php',
        ]);

        $this->assertNotNull($taskId);
        $task = AtlasLoopTask::query()->find($taskId);
        $this->assertNotNull($task);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);

        $this->assertSame('characterization_test', $payload['objective_kind']);
        $this->assertSame('framework', $payload['materializer']);
        $this->assertSame('strict_equals', $payload['characterization_operator']);
        // Provider edits the TEST...
        $this->assertSame(['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilderTest.php'], $payload['acceptance']['allowed_globs']);
        // ...the production target is FROZEN (coverage-only change).
        $this->assertContains('app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilder.php', $payload['acceptance']['frozen_globs']);
        $this->assertStringContainsString('CHARACTERIZATION TEST', (string) $task->objective);
    }

    public function test_unactionable_gap_without_sibling_test_is_not_enqueued(): void
    {
        $campaign = $this->campaign();
        $feeder = app(AtlasLoopCoverageGapFeeder::class);

        $taskId = $feeder->feedGap((string) $campaign->id, [
            'target_file' => 'app/Services/Ai/X.php',
            'decision_operator' => 'strict_equals',
            'mutation_id' => 'm1',
            'sibling_test' => null,
        ]);

        $this->assertNull($taskId);
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->count());
    }
}
