<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Services\Ai\Memory\AtlasMemoryQualityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

final class AtlasMemoryQualityWatchdogTest extends TestCase
{
    public function test_memory_quality_check_reports_four_pinned_checks_and_fails_on_stale_snapshot(): void
    {
        $quality = Mockery::mock(AtlasMemoryQualityService::class);
        $quality->shouldReceive('scorecard')->once()->andReturn([
            'status' => 'ok',
            'score' => 92,
            'components' => ['freshness' => 100],
            'counts' => [
                'retrieval_eval' => [
                    'recall_usage_total' => 40,
                    'top_entry_recall_count' => 10,
                ],
            ],
            'ratios' => ['recall_concentration_ratio' => 0.25],
            'trend' => ['status' => 'stable'],
            'latest_snapshot' => [
                'snapshot_at' => now()->subHours(72)->toJSON(),
                'score' => 92,
            ],
        ]);
        $this->instance(AtlasMemoryQualityService::class, $quality);

        $exit = Artisan::call('atlas:memory:quality', ['--check' => true, '--json' => true]);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.memory.quality_check.v1', $payload['memory_quality_check']['schema_version']);
        $this->assertGreaterThanOrEqual(4, count($payload['memory_quality_check']['checks']));
        $this->assertSame('alert', $payload['memory_quality_check']['status']);
        $this->assertContains('snapshot_fresh', array_column($payload['memory_quality_check']['checks'], 'id'));
        $this->assertSame('snapshot_fresh', $payload['memory_quality_check']['alert_detail']['check_id']);
        $this->assertGreaterThan(48, $payload['memory_quality_check']['raw']['snapshot_age_hours']);
    }

    public function test_memory_quality_watchdog_plugin_surfaces_the_same_alert(): void
    {
        $quality = Mockery::mock(AtlasMemoryQualityService::class);
        $quality->shouldReceive('scorecard')->twice()->andReturn([
            'status' => 'ok',
            'score' => 100,
            'components' => ['freshness' => 80],
            'counts' => ['retrieval_eval' => ['recall_usage_total' => 0, 'top_entry_recall_count' => 0]],
            'ratios' => ['recall_concentration_ratio' => 0.0],
            'trend' => ['status' => 'stable'],
            'latest_snapshot' => ['snapshot_at' => now()->toJSON(), 'score' => 100],
        ]);
        $this->instance(AtlasMemoryQualityService::class, $quality);

        $exit = Artisan::call('atlas:watchdog:run', ['--json' => true]);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertContains('mem-09.memory_quality', array_column($payload['checks'], 'id'));
        $row = collect($payload['checks'])->firstWhere('id', 'mem-09.memory_quality');
        $this->assertSame('alert', $row['status']);
        $this->assertSame('memory_quality_check_failed', $row['alert']['code']);
    }
}
