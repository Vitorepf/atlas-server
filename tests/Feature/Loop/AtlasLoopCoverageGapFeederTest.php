<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageGapFeeder;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopExecutionContract;
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
        config([
            'atlas.loop.pattern_advisory_enabled' => true,
            'atlas.loop.pattern_driver_enabled' => false,
        ]);
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
        $this->assertSame('advisory', data_get($payload, 'pattern.mode'));
        $this->assertSame('loop_harness_verification', data_get($payload, 'pattern.selected'));
        $this->assertSame([], AtlasLoopExecutionContract::missingFields((array) ($payload['execution_contract'] ?? [])));
        $this->assertSame('loop_harness_verification', data_get($payload, 'execution_contract.pattern_id'));
        $this->assertContains('tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilderTest.php', data_get($payload, 'execution_contract.allowed_scope'));
        $this->assertContains('mutant_flip_makes_suite_red', data_get($payload, 'execution_contract.expected_outputs'));
        $this->assertStringContainsString('CHARACTERIZATION TEST', (string) $task->objective);
    }

    public function test_pattern_driver_flag_marks_the_characterization_contract_as_driver_governed(): void
    {
        config(['atlas.loop.pattern_driver_enabled' => true]);
        $campaign = $this->campaign();
        $feeder = app(AtlasLoopCoverageGapFeeder::class);

        $taskId = $feeder->feedGap((string) $campaign->id, [
            'target_file' => 'app/Services/Ai/Z.php',
            'decision_operator' => 'return_false',
            'mutation_id' => 'coverage_deficit:return_false',
            'sibling_test' => 'tests/Unit/Ai/ZTest.php',
        ]);

        $this->assertNotNull($taskId);
        $task = AtlasLoopTask::query()->find($taskId);
        $payload = is_array($task?->payload) ? $task->payload : (array) json_decode((string) $task?->payload, true);

        $this->assertSame('driver', data_get($payload, 'pattern.mode'));
        $this->assertSame('loop_harness_verification', data_get($payload, 'pattern.selected'));
        $this->assertSame([], AtlasLoopExecutionContract::missingFields((array) ($payload['execution_contract'] ?? [])));
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

    public function test_coverage_deficit_can_create_a_new_sibling_characterization_task(): void
    {
        $campaign = $this->campaign();
        $feeder = app(AtlasLoopCoverageGapFeeder::class);

        $taskId = $feeder->feedGap((string) $campaign->id, [
            'target_file' => 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php',
            'decision_operator' => 'strict_equals',
            'mutation_id' => 'coverage_deficit:strict_equals',
            'sibling_test' => null,
        ], null, true);

        $this->assertNotNull($taskId);
        $task = AtlasLoopTask::query()->find($taskId);
        $this->assertNotNull($task);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);

        $expectedSibling = 'tests/Unit/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederServiceTest.php';
        $this->assertSame('create_new_sibling', $payload['characterization_mode'] ?? null);
        $this->assertSame($expectedSibling, $payload['characterization_sibling_test'] ?? null);
        $this->assertSame([$expectedSibling], $payload['allowed_files'] ?? null);
        $this->assertSame([$expectedSibling], $payload['acceptance']['allowed_globs'] ?? null);
        $this->assertContains('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php', $payload['acceptance']['frozen_globs']);
        $this->assertStringContainsString('Create a CHARACTERIZATION TEST', (string) $task->objective);
    }

    public function test_bounded_retry_re_enqueues_a_failed_gap_until_the_cap(): void
    {
        config(['atlas.loop.characterization_max_attempts_per_gap' => 3]);
        $campaign = $this->campaign();
        $feeder = app(AtlasLoopCoverageGapFeeder::class);
        $gap = [
            'target_file' => 'app/Services/Ai/Y.php',
            'decision_operator' => 'strict_equals',
            'mutation_id' => 'mz',
            'sibling_test' => 'tests/Unit/Ai/YTest.php',
        ];

        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $id = $feeder->feedGap((string) $campaign->id, $gap);
            if ($id !== null) {
                $ids[] = $id;
                // simulate a FAILED attempt (a re-detected gap means it did not close)
                AtlasLoopTask::query()->whereKey($id)->update(['status' => 'done']);
            }
        }

        // 3 distinct retry tasks (attempts 0,1,2), then the 4th feed is past the cap => null.
        $this->assertCount(3, $ids);
        $this->assertCount(3, array_unique($ids), 'each retry is a DISTINCT task, not a dedup no-op');
        $this->assertSame(3, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('source', 'coverage_gap_characterization')->count());

        // A 5th feed is still capped (idempotent give-up).
        $this->assertNull($feeder->feedGap((string) $campaign->id, $gap));
    }
}
