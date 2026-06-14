<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OPTION-3 #7 — the grinder's EXECUTION-lane wiring (default-OFF). When multi_file_execution_enabled
 * is ON and no operator L4-10 is supplied, the lane invokes the execution adapter; an adapter that
 * does NOT return a parkable real L4-10 (here: a forbidden self-target in the cluster fails fast,
 * BEFORE any provider call) makes the grinder loop back HONESTLY — never park a non-real obra, never
 * single-file-materialize. With the flag OFF the lane is byte-identical to today (covered elsewhere).
 */
final class AtlasLoopMultiFileExecutionWiringTest extends TestCase
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

    public function test_flag_on_execution_failure_loops_back_honestly_never_parks(): void
    {
        config([
            'atlas.loop.refactor_multi_file_via_obra' => true,
            'atlas.loop.multi_file_execution_enabled' => true,
        ]);

        $campaign = AtlasLoopCampaign::create(['schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'mfx', 'config' => [], 'max_seconds' => 60]);
        // A multi-file refactor task whose cluster includes a FORBIDDEN self-target — the adapter
        // refuses it BEFORE any provider call, so this exercises the wiring with zero spend.
        $payload = [
            'objective_kind' => 'refactor_reduce_complexity',
            'allowed_files' => ['app/Services/Hub.php', 'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php'],
            'multi_file' => true,
        ];
        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id, 'schema_version' => 'atlas.loop.task.v1', 'status' => AtlasLoopTask::STATUS_CLAIMED,
            'source' => 'discovery', 'self_contained' => true, 'target_path' => 'app/Services/Hub.php',
            'objective' => 'Reduce cluster complexity', 'payload' => $payload, 'priority' => 90, 'attempts' => 1, 'max_attempts' => 2,
            'dedupe_key' => 'mfx-'.bin2hex(random_bytes(4)), 'claimed_by' => 'w', 'claimed_at' => now(), 'lease_expires_at' => now()->addMinutes(10),
        ]);

        $grinder = app(AtlasLoopTaskGrinder::class);
        $m = new \ReflectionMethod($grinder, 'maybeRouteMultiFileRefactorToObra');
        $m->setAccessible(true);
        $result = (array) $m->invoke($grinder, $task, $payload, 'w', microtime(true));

        $this->assertSame('no_winner', $result['status'] ?? null);
        $this->assertStringContainsString('obra_execution_not_certified', (string) ($result['reason'] ?? ''));
        $this->assertStringContainsString('forbidden_self_target', (string) ($result['reason'] ?? ''));
        $this->assertSame(0, (int) ($result['proposals'] ?? -1), 'a non-certified obra never produces a proposal');
    }
}
