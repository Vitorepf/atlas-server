<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Characterizes the current loop-back classification between terminal quarantine
 * reasons and retryable metric misses.
 */
final class AtlasLoopBackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    public function test_quarantine_reason_quarantines_target(): void
    {
        $campaign = $this->campaign();
        $target = $this->target($campaign);
        $service = new AtlasLoopBackService(new AtlasLoopTargetRepository);

        $out = $service->reflect($campaign->id, [
            'target_id' => $target->id,
            'status' => 'no_winner',
            'reason' => 'not_red',
        ]);

        $this->assertSame(['spawned' => 0, 'quarantined' => 1, 'requeued' => 0], $out);

        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target->status);
        $this->assertSame('not_red', $target->reason);
    }

    public function test_non_quarantine_reason_requeues_target(): void
    {
        $campaign = $this->campaign();
        $target = $this->target($campaign);
        $service = new AtlasLoopBackService(new AtlasLoopTargetRepository);

        $out = $service->reflect($campaign->id, [
            'target_id' => $target->id,
            'status' => 'no_winner',
            'reason' => 'metric_miss_refund_drift',
        ]);

        $this->assertSame(['spawned' => 0, 'quarantined' => 0, 'requeued' => 1], $out);

        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $target->status);
        $this->assertSame('requeued_metric_miss', $target->reason);
        $this->assertNull($target->claimed_by);
        $this->assertNull($target->lease_expires_at);
        $this->assertSame(0.66, round((float) $target->novelty_score, 2));
        $this->assertSame(0.8, round((float) $target->score, 2));
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'atlas loop back service characterization',
            'status' => 'running',
            'config' => [],
        ]);
    }

    private function target(AtlasLoopCampaign $campaign): AtlasLoopTarget
    {
        return AtlasLoopTarget::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBackService.php',
            'target_key' => hash('sha256', $campaign->id.'|atlas-loop-back-service'),
            'content_hash' => 'hash',
            'status' => AtlasLoopTarget::STATUS_CANDIDATE,
            'score' => 0.9,
            'novelty_score' => 1.0,
            'signals' => [],
            'attempts' => 0,
            'max_attempts' => 3,
        ]);
    }
}
