<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopMetaHarnessAbLiftTest extends TestCase
{
    private string $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        DB::table('atlas_loop_proposals')->delete();
        DB::table('atlas_loop_tasks')->delete();
        DB::table('atlas_loop_campaigns')->delete();
        $this->campaignId = (string) Str::uuid();
        DB::table('atlas_loop_campaigns')->insert([
            'id' => $this->campaignId,
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'l6-1-meta-harness-ab-lift-test',
            'base_workspace' => base_path(),
            'config' => json_encode([]),
            'max_seconds' => 3600,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_positive_meta_harness_lift_passes_strict_with_real_cases_in_both_arms(): void
    {
        config([
            'atlas.loop.meta_harness_ab_lift.enabled' => true,
            'atlas.loop.meta_harness_ab_lift.min_cases_per_arm' => 2,
            'atlas.loop.meta_harness_ab_lift.min_lift' => 0.01,
        ]);

        $this->task('ordinary-a', 'app/Support/TerminalMarkdownRenderer.php', certified: true);
        $this->task('ordinary-b', 'app/Support/TerminalMarkdownRenderer.php', certified: false);
        $this->task('meta-a', 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php', certified: true);
        $this->task('meta-b', 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php', certified: true);

        $exit = Artisan::call('atlas:loop:meta-harness-ab-lift', [
            '--min-cases' => '2',
            '--min-lift' => '0.01',
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('positive_lift', $payload['status']);
        $this->assertSame(2, data_get($payload, 'arms.meta_harness.case_count'));
        $this->assertSame(2, data_get($payload, 'arms.ordinary.case_count'));
        $this->assertSame(1.0, (float) data_get($payload, 'arms.meta_harness.certification_rate'));
        $this->assertSame(0.5, (float) data_get($payload, 'arms.ordinary.certification_rate'));
        $this->assertSame(0.5, (float) data_get($payload, 'lift.certification_rate_delta'));
        $this->assertTrue((bool) $payload['completion_claim_allowed']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.workspace_mutated'));
    }

    public function test_forbidden_self_targets_block_completion_even_when_lift_is_positive(): void
    {
        $this->task('ordinary-a', 'app/Support/TerminalMarkdownRenderer.php', certified: true);
        $this->task('ordinary-b', 'app/Support/TerminalMarkdownRenderer.php', certified: false);
        $this->task('meta-a', 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php', certified: true);
        $this->task('meta-b', 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php', certified: true);
        $this->task('forbidden', 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', certified: true);

        $exit = Artisan::call('atlas:loop:meta-harness-ab-lift', [
            '--min-cases' => '2',
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('insufficient_live_ab_evidence', $payload['status']);
        $this->assertContains('forbidden_self_targets_seen_in_window', $payload['blockers']);
        $this->assertSame(1, data_get($payload, 'forbidden_self_targets.count'));
        $this->assertFalse((bool) $payload['completion_claim_allowed']);
    }

    public function test_schedule_lists_meta_harness_lift_measurement(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:loop:meta-harness-ab-lift --json', $output);
    }

    private function task(string $key, string $path, bool $certified): void
    {
        $id = (string) Str::uuid();
        DB::table('atlas_loop_tasks')->insert([
            'id' => $id,
            'campaign_id' => $this->campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'done',
            'source' => 'manual',
            'self_contained' => true,
            'target_path' => $path,
            'objective' => 'fixture '.$key,
            'payload' => json_encode(['allowed_files' => [$path]]),
            'priority' => 0,
            'attempts' => 1,
            'max_attempts' => 1,
            'dedupe_key' => hash('sha256', $key),
            'result' => json_encode(['proposals_certified_for_review' => $certified ? 1 : 0]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $certified) {
            return;
        }
        DB::table('atlas_loop_proposals')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $this->campaignId,
            'task_id' => $id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'certified_for_review',
            'objective' => 'proposal '.$key,
            'provider' => 'fixture',
            'target_path' => $path,
            'diff_text' => 'diff --git a/'.$path.' b/'.$path."\n",
            'proposal_hash' => hash('sha256', 'proposal '.$key),
            'metric' => json_encode(['ok' => true]),
            'acceptance_hash' => hash('sha256', 'accept '.$key),
            'scenarios_explored' => 1,
            'scenarios_accepted' => 1,
            'winning_scenario' => 'fixture',
            'merged_to_main' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
