<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopExplorerStrategyBanditService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACDE lever #7 — the per-TARGET_PATH verdict. A file with >= threshold REAL attempts and ZERO certs is
 * 'hopeless' (already-clean / unfixable-as-framed); the grinder skips it before best-of-N burns. Anti-gaming:
 * only provider-invoked, non-trivial-token attempts count, so an injected row cannot manufacture a skip.
 */
final class AtlasLoopPerTargetVerdictTest extends TestCase
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
        if (! Schema::hasColumn('atlas_loop_explorations', 'attempt_metrics')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }
        AtlasLoopExploration::query()->delete();
        AtlasLoopTask::query()->delete();
        AtlasLoopCampaign::query()->delete();
        config(['atlas.loop.per_target_skip_min_attempts' => 6]);
    }

    /**
     * @param  list<array{passed:bool,provider_invoked?:bool,tokens_used?:int}>  $attempts
     */
    private function seedTarget(string $targetPath, array $attempts): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'per-target',
            'config' => [],
            'max_seconds' => 60,
        ]);
        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_DONE,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => $targetPath,
            'objective' => 'history',
            'payload' => [],
            'dedupe_key' => (string) Str::uuid(),
            'result' => [],
        ]);
        $metrics = [];
        foreach ($attempts as $i => $a) {
            $metrics[] = [
                'scenario' => 'scn-'.$i,
                'strategy_key' => 'baseline',
                'passed' => (bool) $a['passed'],
                'provider_invoked' => $a['provider_invoked'] ?? true,
                'tokens_used' => $a['tokens_used'] ?? 300,
            ];
        }
        AtlasLoopExploration::create([
            'campaign_id' => $campaign->id,
            'task_id' => $task->id,
            'schema_version' => 'atlas.loop.exploration.v1',
            'objective' => 'history',
            'provider' => 'fixture',
            'scenarios_explored' => count($metrics),
            'scenarios_accepted' => 0,
            'has_winner' => false,
            'rejected_reasons' => [],
            'attempt_metrics' => $metrics,
        ]);
    }

    private function service(): AtlasLoopExplorerStrategyBanditService
    {
        return app(AtlasLoopExplorerStrategyBanditService::class);
    }

    public function test_six_real_attempts_zero_certified_is_hopeless(): void
    {
        $this->seedTarget('app/Already/Clean.php', array_fill(0, 6, ['passed' => false]));
        $v = $this->service()->targetPathVerdict('app/Already/Clean.php');

        $this->assertSame('hopeless', $v['verdict']);
        $this->assertSame(6, $v['real_attempts']);
        $this->assertSame(0, $v['certified']);
    }

    public function test_any_certification_keeps_it_open(): void
    {
        $attempts = array_fill(0, 5, ['passed' => false]);
        $attempts[] = ['passed' => true]; // one win => not hopeless
        $this->seedTarget('app/Fixable.php', $attempts);

        $this->assertSame('open', $this->service()->targetPathVerdict('app/Fixable.php')['verdict']);
    }

    public function test_below_threshold_is_open(): void
    {
        $this->seedTarget('app/Fresh.php', array_fill(0, 3, ['passed' => false])); // 3 < 6
        $this->assertSame('open', $this->service()->targetPathVerdict('app/Fresh.php')['verdict']);
    }

    public function test_anti_gaming_non_real_attempts_do_not_count(): void
    {
        // 6 rows but none is a REAL attempt (no provider call / trivial tokens) => real_attempts 0 => open.
        $this->seedTarget('app/Injected.php', array_merge(
            array_fill(0, 3, ['passed' => false, 'provider_invoked' => false, 'tokens_used' => 1000]),
            array_fill(0, 3, ['passed' => false, 'provider_invoked' => true, 'tokens_used' => 1]),
        ));
        $v = $this->service()->targetPathVerdict('app/Injected.php');

        $this->assertSame('open', $v['verdict']);
        $this->assertSame(0, $v['real_attempts']);
    }
}
