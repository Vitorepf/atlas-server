<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogAutoFeederService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopBacklogAutoFeederServiceTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/atlas-backlog-auto-feeder-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);

        $this->ensureLoopTables();
        DB::table('atlas_loop_proposals')->delete();
        DB::table('atlas_loop_tasks')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('atlas_loop_proposals')->delete();
        DB::table('atlas_loop_tasks')->delete();

        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }

        parent::tearDown();
    }

    public function test_feed_returns_clear_when_campaign_residuals_do_not_reach_the_signal_threshold(): void
    {
        $service = app(AtlasLoopBacklogAutoFeederService::class);

        $result = $service->feed((string) Str::uuid(), [
            'write' => false,
            'window_hours' => 1,
            'min_signal_count' => 2,
            'manifest_path' => $this->manifestPath(),
            'include_scorecard_weak_receipts' => false,
            'include_sweep_findings' => false,
        ]);

        $this->assertSame('clear', $result['status']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(0, $result['actions_count']);
        $this->assertSame(0, $result['enqueued_count']);
        $this->assertSame(0, $result['dry_run_count']);
        $this->assertSame(0, $result['source_counts']['campaign_residuals']);
    }

    public function test_feed_uses_the_decoded_result_reason_for_campaign_residual_candidates(): void
    {
        $campaignId = (string) Str::uuid();
        $this->insertTaskResidual($campaignId, json_encode(['reason' => 'decoded_reason'], JSON_THROW_ON_ERROR), 'done');
        $this->insertTaskResidual($campaignId, json_encode(['reason' => 'decoded_reason'], JSON_THROW_ON_ERROR), 'done');

        $service = app(AtlasLoopBacklogAutoFeederService::class);
        $result = $service->feed($campaignId, [
            'write' => false,
            'window_hours' => 1,
            'min_signal_count' => 2,
            'manifest_path' => $this->manifestPath(),
            'include_scorecard_weak_receipts' => false,
            'include_sweep_findings' => false,
        ]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame(1, $result['actions_count']);
        $this->assertSame(1, $result['dry_run_count']);
        $this->assertSame(1, $result['source_counts']['campaign_residuals']);
        $this->assertSame('decoded_reason', $result['actions'][0]['item']['reason']);
        $this->assertStringContainsString('decoded_reason', $result['actions'][0]['item']['objective']);
    }

    public function test_feed_falls_back_to_status_based_reason_when_task_result_is_not_valid_json(): void
    {
        $campaignId = (string) Str::uuid();
        $this->insertTaskResidual($campaignId, '{not-valid-json', 'failed');
        $this->insertTaskResidual($campaignId, '{not-valid-json', 'failed');

        $service = app(AtlasLoopBacklogAutoFeederService::class);
        $result = $service->feed($campaignId, [
            'write' => false,
            'window_hours' => 1,
            'min_signal_count' => 2,
            'manifest_path' => $this->manifestPath(),
            'include_scorecard_weak_receipts' => false,
            'include_sweep_findings' => false,
        ]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame(1, $result['actions_count']);
        $this->assertSame(1, $result['dry_run_count']);
        $this->assertSame(1, $result['source_counts']['campaign_residuals']);
        $this->assertSame('task_failed_without_proposal', $result['actions'][0]['item']['reason']);
        $this->assertStringContainsString('task_failed_without_proposal', $result['actions'][0]['item']['objective']);
    }

    public function test_feed_treats_an_empty_campaign_id_as_unfiltered_when_collecting_campaign_residuals(): void
    {
        $firstCampaignId = (string) Str::uuid();
        $secondCampaignId = (string) Str::uuid();

        $this->insertTaskResidual($firstCampaignId, json_encode(['reason' => 'shared_reason'], JSON_THROW_ON_ERROR), 'done');
        $this->insertTaskResidual($secondCampaignId, json_encode(['reason' => 'shared_reason'], JSON_THROW_ON_ERROR), 'done');

        $service = app(AtlasLoopBacklogAutoFeederService::class);
        $result = $service->feed('', [
            'write' => false,
            'window_hours' => 1,
            'min_signal_count' => 2,
            'manifest_path' => $this->manifestPath(),
            'include_scorecard_weak_receipts' => false,
            'include_sweep_findings' => false,
        ]);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame('', $result['campaign_id']);
        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame(1, $result['actions_count']);
        $this->assertSame(1, $result['dry_run_count']);
        $this->assertSame(1, $result['source_counts']['campaign_residuals']);
        $this->assertSame('shared_reason', $result['actions'][0]['item']['reason']);
        $this->assertStringContainsString('shared_reason', $result['actions'][0]['item']['objective']);
    }

    private function ensureLoopTables(): void
    {
        if (Schema::hasTable('atlas_loop_tasks') && Schema::hasTable('atlas_loop_proposals')) {
            return;
        }

        foreach ([
            '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
            '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
        ] as $file) {
            (require base_path('database/migrations/'.$file))->up();
        }
    }

    private function manifestPath(): string
    {
        return $this->dir.'/backlog-intents.json';
    }

    private function insertTaskResidual(string $campaignId, string $result, string $status): void
    {
        DB::table('atlas_loop_tasks')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => $status,
            'source' => 'manual',
            'self_contained' => true,
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopBacklogAutoFeederService.php',
            'objective' => 'Characterize backlog auto feeder residual handling',
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'priority' => 0,
            'attempts' => 0,
            'max_attempts' => 1,
            'dedupe_key' => hash('sha256', $campaignId.'|'.$status.'|'.$result.'|'.Str::uuid()),
            'acceptance_hash' => null,
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
            'result' => $result,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
