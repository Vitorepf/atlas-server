<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiring proof for the PARK-LEDGER ESCALATION at the hopeless-target seam in
 * {@see AtlasLoopTaskGrinder::grind()}.
 *
 * When a target is parked as hopeless (per-target verdict: >= threshold REAL attempts, ZERO certs) the
 * grinder still skips it (honest terminal refusal), but BEFORE finalizing it escalates the task priority
 * and re-enqueues an escalated SHADOW follow-up (source='park_escalation') with a STRICTLY higher integer
 * priority — so a high-EV item cannot be starved forever behind cheaper, freshly-discovered work.
 *
 * The hopeless verdict here is GENUINE: real exploration rows with provider-invoked, non-trivial-token
 * attempts and zero passes are seeded so the production strategy-bandit returns 'hopeless' on its own.
 * The hopeless block fires BEFORE materialization, so no provider runs and no workspace is built.
 */
final class AtlasLoopTaskGrinderParkEscalationWiringTest extends TestCase
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

        // Arm the per-target skip (so the verdict can be 'hopeless') and the park escalation under test.
        config([
            'atlas.loop.per_target_skip_enabled' => true,
            'atlas.loop.per_target_skip_min_attempts' => 6,
            'atlas.loop.park_escalation_enabled' => true,
        ]);
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'park-escalation',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    /**
     * Seed >= threshold REAL (provider-invoked, non-trivial-token) attempts with ZERO passes against
     * $targetPath under $campaign, so the production verdict computes to 'hopeless'.
     */
    private function seedHopelessHistory(AtlasLoopCampaign $campaign, string $targetPath): void
    {
        $historyTask = AtlasLoopTask::create([
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
        for ($i = 0; $i < 6; $i++) {
            $metrics[] = [
                'scenario' => 'scn-'.$i,
                'strategy_key' => 'baseline',
                'passed' => false,
                'provider_invoked' => true,
                'tokens_used' => 300,
            ];
        }
        AtlasLoopExploration::create([
            'campaign_id' => $campaign->id,
            'task_id' => $historyTask->id,
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

    /** The live (claimed/running) task the grinder will park. */
    private function liveTask(AtlasLoopCampaign $campaign, string $targetPath, string $workerId, int $priority): AtlasLoopTask
    {
        return AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_RUNNING,
            'source' => AtlasLoopTask::SOURCE_DISCOVERY,
            'self_contained' => true,
            'target_path' => $targetPath,
            'objective' => 'evolve '.$targetPath,
            'payload' => ['allowed_files' => [$targetPath]],
            'priority' => $priority,
            'attempts' => 1,
            'max_attempts' => 2,
            'dedupe_key' => (string) Str::uuid(),
            'claimed_by' => $workerId,
            'claimed_at' => Carbon::now(),
            'lease_expires_at' => Carbon::now()->addMinutes(5),
        ]);
    }

    public function test_hopeless_park_escalates_priority_and_enqueues_shadow_followup(): void
    {
        $campaign = $this->campaign();
        $target = 'app/Already/Clean.php';
        $workerId = 'worker-park-1';
        $basePriority = 100;

        $this->seedHopelessHistory($campaign, $target);
        $task = $this->liveTask($campaign, $target, $workerId, $basePriority);

        $grinder = app(AtlasLoopTaskGrinder::class);
        $out = $grinder->grind($task, $workerId, 1);

        // The honest skip is preserved.
        $this->assertSame('skipped', $out['status']);
        $this->assertSame('hopeless_target', $out['reason']);

        // A NEW escalated shadow follow-up landed with source='park_escalation'.
        $followUp = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('source', 'park_escalation')
            ->first();

        $this->assertNotNull($followUp, 'a park-escalation shadow follow-up must be enqueued');
        // LOAD-BEARING: the follow-up's priority is STRICTLY higher than the parked task's base priority.
        $this->assertGreaterThan($basePriority, (int) $followUp->priority);
        // Provenance carried onto the shadow payload.
        $this->assertTrue((bool) ($followUp->payload['park_escalated'] ?? false));
        $this->assertSame($basePriority, (int) ($followUp->payload['park_escalated_from_priority'] ?? -1));
    }

    public function test_flag_off_parks_without_enqueuing_a_followup(): void
    {
        config(['atlas.loop.park_escalation_enabled' => false]);

        $campaign = $this->campaign();
        $target = 'app/Already/Clean.php';
        $workerId = 'worker-park-2';

        $this->seedHopelessHistory($campaign, $target);
        $task = $this->liveTask($campaign, $target, $workerId, 100);

        $out = app(AtlasLoopTaskGrinder::class)->grind($task, $workerId, 1);

        $this->assertSame('skipped', $out['status']);
        $this->assertSame(0, AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('source', 'park_escalation')
            ->count(), 'flag OFF must not enqueue a shadow follow-up (byte-identical park)');
    }
}
