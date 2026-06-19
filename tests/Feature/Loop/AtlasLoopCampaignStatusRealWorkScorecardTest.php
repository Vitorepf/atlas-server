<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * C0 (wiring) — the live `atlas:loop:campaign:status --json` must embed the Real-Work Scorecard section so
 * the operator sees the claim policy straight from status. This proves the seam is load-bearing (the real
 * counts + claim flow through the command's json_encode boundary) and that the flag-off / error paths
 * degrade to a well-formed `disabled` / `unavailable` section whose claim is structurally refused.
 */
final class AtlasLoopCampaignStatusRealWorkScorecardTest extends TestCase
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
        if (! Schema::hasTable('atlas_loop_pipeline_state')) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
    }

    private function makeCampaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'c0-status-wire',
            'config' => [],
            'max_seconds' => 60,
            'objective' => 'c0-status-wire',
            'status' => 'running',
        ]);
    }

    private function makeBugFixTask(string $campaignId): void
    {
        AtlasLoopTask::query()->create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'pending',
            'source' => 'discovery',
            'self_contained' => false,
            'target_path' => 'app/Services/X/Foo.php',
            'objective' => 'fix a real failing test',
            'payload' => [
                'objective_kind' => 'bug_fix',
                'revert_recheck' => true,
                'acceptance' => ['red_required' => true, 'commands' => ['./vendor/bin/phpunit --filter X']],
            ],
            'priority' => 0,
            'max_attempts' => 1,
            'dedupe_key' => 'dk-'.bin2hex(random_bytes(5)),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeStatusJson(string $id): array
    {
        $exit = Artisan::call('atlas:loop:campaign:status', [
            '--campaign-id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit, 'status command must exit SUCCESS');

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded, 'status must emit a JSON object');

        return $decoded;
    }

    public function test_status_json_embeds_the_real_work_scorecard_section(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeBugFixTask($campaign->id);

        $payload = $this->decodeStatusJson($campaign->id);

        $this->assertArrayHasKey('real_work_scorecard', $payload, 'the wired scorecard section must be present');
        $sc = $payload['real_work_scorecard'];

        $this->assertSame(AtlasLoopRealWorkScorecardService::SCHEMA_VERSION, $sc['schema_version']);
        $this->assertSame(1, $sc['tasks_total']);
        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['bug_fix_tasks']);
        $this->assertArrayHasKey('claim_policy', $sc);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertSame([], $sc['claim_policy']['blockers']);
    }

    public function test_status_scorecard_refuses_claim_for_cosmetic_only_campaign(): void
    {
        $campaign = $this->makeCampaign();
        AtlasLoopTask::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'pending',
            'source' => 'discovery',
            'self_contained' => false,
            'target_path' => 'app/X.php',
            'objective' => 'reformat whitespace',
            'payload' => ['objective_kind' => 'cosmetic'],
            'priority' => 0,
            'max_attempts' => 1,
            'dedupe_key' => 'dk-'.bin2hex(random_bytes(5)),
        ]);

        $sc = $this->decodeStatusJson($campaign->id)['real_work_scorecard'];

        $this->assertSame(1, $sc['cosmetic_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('cosmetic_work_observed', $sc['claim_policy']['blockers']);
    }

    public function test_flag_off_short_circuits_to_a_disabled_section(): void
    {
        config(['atlas.loop.real_work_scorecard_enabled' => false]);

        $campaign = $this->makeCampaign();
        $this->makeBugFixTask($campaign->id);

        $sc = $this->decodeStatusJson($campaign->id)['real_work_scorecard'];

        $this->assertSame('disabled', $sc['status']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
    }
}
