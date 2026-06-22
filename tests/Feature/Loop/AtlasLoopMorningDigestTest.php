<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopMorningDigestTest extends TestCase
{
    use ArmsAtlasLoopMaster;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        // §0 master switch defaults OFF (fail-closed) — arm ON to exercise the live keepalive event path.
        $this->armLoopMasterOn();
        $this->beforeApplicationDestroyed(fn () => $this->disarmLoopMaster());

        Carbon::setTestNow(Carbon::parse('2026-06-12 08:00:00'));
        $this->tmp = sys_get_temp_dir().'/atlas-loop-digest-test-'.bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0775, true);

        config([
            'atlas.loop.morning_digest.enabled' => true,
            'atlas.loop.morning_digest.window_hours' => 24,
            'atlas.loop.morning_digest.keepalive_event_log_path' => $this->tmp.'/keepalive-events.jsonl',
            'atlas.loop.morning_digest.keepalive_event_log_enabled' => true,
            'atlas.loop.operator_review.limit' => 10,
        ]);

        $this->createTables();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        Schema::dropIfExists('atlas_loop_proposals');
        Schema::dropIfExists('atlas_loop_tasks');
        Schema::dropIfExists('atlas_loop_campaigns');
        $this->rmDir($this->tmp);

        parent::tearDown();
    }

    public function test_morning_digest_answers_24h_loop_activity_from_resolved_sources(): void
    {
        DB::table('atlas_loop_campaigns')->insert([
            'id' => 'campaign-1',
            'status' => 'running',
            'spend_usd_cents' => 12,
            'max_usd_cents' => 100,
            'elapsed_seconds' => 60,
            'max_seconds' => 86400,
            'kill_switch' => false,
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHour(),
        ]);

        DB::table('atlas_loop_tasks')->insert([
            ['id' => 'task-1', 'campaign_id' => 'campaign-1', 'status' => 'done', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'task-2', 'campaign_id' => 'campaign-1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'task-3', 'campaign_id' => 'campaign-1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('atlas_loop_proposals')->insert([
            [
                'id' => 'proposal-merged',
                'campaign_id' => 'campaign-1',
                'task_id' => 'task-1',
                'schema_version' => 'test',
                'status' => 'certified_for_review',
                'objective' => 'merge useful digest target',
                'provider' => 'codex_cli',
                'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'diff_text' => "+digest\n",
                'proposal_hash' => 'hash-merged',
                'metric' => json_encode([]),
                'quality' => json_encode([
                    '_canary' => ['ran' => true, 'passed' => true, 'target' => 'tests/Feature/Loop/AtlasLoopMorningDigestTest.php'],
                    '_impact_receipt' => [
                        'schema_version' => 'atlas.loop.impact_receipt.v1',
                        'category' => 'feature',
                        'target_kind' => 'real',
                        'real_vs_generated' => 'real',
                        'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                        'impact_score' => 0.82,
                        'size' => ['files_changed' => 1, 'added_lines' => 10, 'deleted_lines' => 0],
                    ],
                ]),
                'acceptance_hash' => 'acceptance',
                'scenarios_explored' => 1,
                'scenarios_accepted' => 1,
                'winning_scenario' => json_encode([]),
                'merged_to_main' => true,
                'reviewed_at' => now()->subHours(2),
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(2),
            ],
            [
                'id' => 'proposal-parked',
                'campaign_id' => 'campaign-1',
                'task_id' => 'task-2',
                'schema_version' => 'test',
                'status' => 'certified_for_review',
                'objective' => 'parked self-target',
                'provider' => 'codex_cli',
                'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
                'diff_text' => "+parked\n",
                'proposal_hash' => 'hash-parked',
                'metric' => json_encode([]),
                'quality' => json_encode([
                    '_operator_review' => [
                        'schema_version' => 'atlas.loop.operator_review.v1',
                        'status' => 'parked_for_operator_review',
                        'reason' => 'forbidden_self_target',
                    ],
                ]),
                'acceptance_hash' => 'acceptance',
                'scenarios_explored' => 1,
                'scenarios_accepted' => 1,
                'winning_scenario' => json_encode([]),
                'merged_to_main' => false,
                'reviewed_at' => now()->subHour(),
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHour(),
            ],
            [
                'id' => 'proposal-parked-policy-fallback',
                'campaign_id' => 'campaign-1',
                'task_id' => 'task-3',
                'schema_version' => 'test',
                'status' => 'certified_for_review',
                'objective' => 'parked self-target before operator review payload existed',
                'provider' => 'codex_cli',
                'target_path' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
                'diff_text' => "+parked-fallback\n",
                'proposal_hash' => 'hash-parked-fallback',
                'metric' => json_encode([]),
                'quality' => json_encode([]),
                'acceptance_hash' => 'acceptance',
                'scenarios_explored' => 1,
                'scenarios_accepted' => 1,
                'winning_scenario' => json_encode([]),
                'merged_to_main' => false,
                'reviewed_at' => now()->subMinutes(30),
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subMinutes(30),
            ],
        ]);

        DB::table('ai_programming_runtime_telemetry_events')->insert([
            ['id' => 'telemetry-1', 'flow' => 'loop_auto_merge', 'cost_estimate_usd' => 0.12, 'occurred_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'telemetry-2', 'flow' => 'loop_auto_merge', 'cost_estimate_usd' => 0.0, 'occurred_at' => now()->subHours(3), 'created_at' => now(), 'updated_at' => now()],
        ]);

        file_put_contents($this->tmp.'/keepalive-events.jsonl', json_encode([
            'recorded_at' => now()->subMinutes(5)->toIso8601String(),
            'respawned' => [['campaign_id' => 'campaign-1']],
            'revived_starved' => [['campaign_id' => 'campaign-2']],
        ])."\n");

        $payload = app(AtlasLoopMorningDigestService::class)->digest(24);

        $this->assertSame('atlas.loop.morning_digest.v1', $payload['schema_version']);
        $this->assertSame(1, data_get($payload, 'sections.merges.merged_24h'));
        $this->assertSame(1, data_get($payload, 'sections.merges.impact_receipts_24h'));
        $this->assertSame(1, data_get($payload, 'sections.canaries.ran_24h'));
        $this->assertSame(0, data_get($payload, 'sections.canaries.failed_24h'));
        $this->assertSame(2, data_get($payload, 'sections.cost.events_24h'));
        $this->assertSame(50.0, data_get($payload, 'sections.cost.coverage_pct_24h'));
        $this->assertSame(0.12, data_get($payload, 'sections.cost.cost_per_merge_usd_24h'));
        $this->assertTrue((bool) data_get($payload, 'sections.cost.cost_per_merge_available'));
        $this->assertSame(1, data_get($payload, 'sections.cost.campaign_budget.running_campaigns'));
        $this->assertSame(0.12, data_get($payload, 'sections.cost.campaign_budget.total_spend_usd'));
        $this->assertSame(1.0, data_get($payload, 'sections.cost.campaign_budget.total_cap_usd'));
        $this->assertSame(0.88, data_get($payload, 'sections.cost.campaign_budget.remaining_cap_usd'));
        $this->assertSame(12.0, data_get($payload, 'sections.cost.campaign_budget.nearest_spend_pct'));
        $this->assertSame(1, data_get($payload, 'sections.keepalive.respawned_24h'));
        $this->assertSame(1, data_get($payload, 'sections.keepalive.revived_starved_24h'));
        $this->assertSame(2, data_get($payload, 'sections.operator_review.pending_parked_for_review'));
        $this->assertSame('forbidden_self_target', data_get($payload, 'sections.operator_review.items.0.reason'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));

        Artisan::call('atlas:loop:morning-digest', ['--json' => true]);
        $commandPayload = json_decode(Artisan::output(), true);
        $this->assertSame('ok', $commandPayload['status']);
        $this->assertSame('php artisan atlas:loop:morning-digest --json', data_get($commandPayload, 'claim_policy.one_command_answer'));
    }

    public function test_keepalive_appends_event_log_for_morning_digest(): void
    {
        Artisan::call('atlas:loop:keepalive', ['--json' => true]);

        $this->assertFileExists($this->tmp.'/keepalive-events.jsonl');
        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($this->tmp.'/keepalive-events.jsonl')))));
        $this->assertCount(1, $lines);

        $event = json_decode($lines[0], true);
        $this->assertSame('atlas.loop.keepalive.digest_event.v1', $event['schema_version']);
        $this->assertSame('atlas.loop.keepalive.v1', $event['source_schema_version']);
        $this->assertArrayHasKey('recorded_at', $event);
        $this->assertSame(0, $event['checked']);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        Schema::dropIfExists('atlas_loop_proposals');
        Schema::dropIfExists('atlas_loop_tasks');
        Schema::dropIfExists('atlas_loop_campaigns');

        Schema::create('atlas_loop_campaigns', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('status')->default('running');
            $table->boolean('kill_switch')->default(false);
            $table->integer('elapsed_seconds')->default(0);
            $table->integer('max_seconds')->default(86400);
            $table->integer('spend_usd_cents')->default(0);
            $table->integer('max_usd_cents')->default(0);
            $table->string('stop_reason')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_loop_tasks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('campaign_id')->nullable();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('atlas_loop_proposals', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('campaign_id')->nullable();
            $table->string('task_id')->nullable();
            $table->string('schema_version')->nullable();
            $table->string('status')->index();
            $table->text('objective')->nullable();
            $table->string('provider')->nullable();
            $table->string('target_path')->nullable();
            $table->text('diff_text')->nullable();
            $table->string('proposal_hash')->nullable();
            $table->json('metric')->nullable();
            $table->json('quality')->nullable();
            $table->string('acceptance_hash')->nullable();
            $table->integer('scenarios_explored')->default(0);
            $table->integer('scenarios_accepted')->default(0);
            $table->json('winning_scenario')->nullable();
            $table->boolean('merged_to_main')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_programming_runtime_telemetry_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('flow')->nullable();
            $table->decimal('cost_estimate_usd', 12, 6)->default(0);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    private function rmDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (glob($path.'/*') ?: [] as $file) {
            is_dir($file) ? $this->rmDir($file) : @unlink($file);
        }
        @rmdir($path);
    }
}
