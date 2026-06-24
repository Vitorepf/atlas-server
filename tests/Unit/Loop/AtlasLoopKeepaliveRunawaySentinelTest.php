<?php

declare(strict_types=1);

namespace Tests\Unit\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopKeepaliveRunawaySentinelTest extends TestCase
{
    private string $tmpEnv;

    private string $eventsPath;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-24T12:00:00Z'));
        $dir = sys_get_temp_dir().'/atlas-keepalive-runaway-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);
        $this->eventsPath = $dir.'/keepalive-events.jsonl';
        $this->tmpEnv = $dir.'/.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->tmpEnv;

        config()->set('atlas.loop.morning_digest.keepalive_event_log_enabled', true);
        config()->set('atlas.loop.morning_digest.keepalive_event_log_path', $this->eventsPath);
        config()->set('atlas.loop.morning_digest.keepalive_event_log_max_lines', 2000);
        config()->set('atlas.loop.keepalive_runaway_threshold', 30);
        config()->set('atlas.loop.keepalive_runaway_window_seconds', 3600);
        config()->set('atlas.loop.keepalive_self_deadline_seconds', 60);

        $this->freshCampaignTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_loop_campaigns');
        AtlasLoopMasterSwitch::$envPathOverride = null;
        File::deleteDirectory(dirname($this->eventsPath));
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_above_threshold_flips_master_off_and_next_keepalive_short_circuits(): void
    {
        $this->writeEnv(true);
        $this->writeRespawnEvents(31);

        $out = $this->runKeepalive();

        $this->assertFalse(AtlasLoopMasterSwitch::enabled());
        $this->assertSame([
            'threshold' => 30,
            'window_seconds' => 3600,
            'observed_count' => 31,
            'reason' => 'keepalive_respawn_events_exceeded_threshold',
        ], $out['runaway_auto_off'] ?? null);

        $next = $this->runKeepalive();
        $this->assertSame('off', $next['master'] ?? null);
        $this->assertSame(0, $next['checked'] ?? -1);
        $this->assertArrayNotHasKey('runaway_auto_off', $next);
    }

    public function test_below_threshold_leaves_master_on_without_runaway_output(): void
    {
        $this->writeEnv(true);
        $this->writeRespawnEvents(29);

        $out = $this->runKeepalive();

        $this->assertTrue(AtlasLoopMasterSwitch::enabled());
        $this->assertArrayNotHasKey('runaway_auto_off', $out);
    }

    public function test_empty_window_leaves_master_on(): void
    {
        $this->writeEnv(true);
        $this->writeRespawnEvents(31, Carbon::now('UTC')->subHours(2));

        $out = $this->runKeepalive();

        $this->assertTrue(AtlasLoopMasterSwitch::enabled());
        $this->assertArrayNotHasKey('runaway_auto_off', $out);
    }

    public function test_master_off_on_entry_returns_before_sentinel_runs(): void
    {
        $this->writeEnv(false);
        $this->writeRespawnEvents(31);

        $out = $this->runKeepalive();

        $this->assertSame('off', $out['master'] ?? null);
        $this->assertSame(0, $out['checked'] ?? -1);
        $this->assertArrayNotHasKey('runaway_auto_off', $out);
    }

    /**
     * @return array<string,mixed>
     */
    private function runKeepalive(): array
    {
        $code = Artisan::call('atlas:loop:keepalive', ['--json' => true]);
        $this->assertSame(0, $code);

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function writeEnv(bool $on): void
    {
        File::put($this->tmpEnv, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
    }

    private function writeRespawnEvents(int $count, ?Carbon $recordedAt = null): void
    {
        $recordedAt ??= Carbon::now('UTC')->subMinute();
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = json_encode([
                'schema_version' => 'atlas.loop.keepalive.digest_event.v1',
                'source_schema_version' => 'atlas.loop.keepalive.v1',
                'recorded_at' => $recordedAt->toIso8601String(),
                'respawned' => [['campaign_id' => 'camp-'.$i]],
            ], JSON_THROW_ON_ERROR);
        }

        File::put($this->eventsPath, implode("\n", $lines).($lines === [] ? '' : "\n"));
    }

    private function freshCampaignTable(): void
    {
        Schema::dropIfExists('atlas_loop_campaigns');
        Schema::create('atlas_loop_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->index();
            $table->text('goal')->nullable();
            $table->string('base_workspace', 1024)->nullable();
            $table->unsignedInteger('max_seconds')->default(0);
            $table->unsignedInteger('elapsed_seconds')->default(0);
            $table->boolean('kill_switch')->default(false);
            $table->string('stop_reason')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
    }
}
