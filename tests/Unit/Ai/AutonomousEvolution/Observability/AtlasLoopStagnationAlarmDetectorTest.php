<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Observability;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopStagnationAlarmDetector;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopStagnationAlarmDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStoragePath = app()->storagePath();
        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->useStoragePath($this->originalStoragePath);
        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }
        parent::tearDown();
    }

    public function test_merging_dry_when_decision_and_certification_exist_without_merge_or_pipeline_progress(): void
    {
        $this->useStorage();
        $this->freshPipelineTable();
        $this->writeSignals([
            $this->signal('decision'),
            $this->signal('decision'),
            $this->signal('decision'),
            $this->signal('certification'),
            $this->signal('certification'),
        ]);

        $result = (new AtlasLoopStagnationAlarmDetector)->evaluate('camp-1', 3600);

        $this->assertSame(['schema_version', 'campaign_id', 'window_seconds', 'evaluated_at', 'stagnated', 'reason_code', 'fact_evidence'], array_keys($result));
        $this->assertSame('merging_dry', $result['reason_code']);
        $this->assertTrue($result['stagnated']);
        $this->assertSame([
            'decision_count' => 3,
            'merge_count' => 0,
            'certification_count' => 2,
            'pipeline_rows' => 0,
            'max_stage_age_seconds' => 0,
        ], $result['fact_evidence']);
    }

    public function test_healthy_when_merge_signal_exists_in_window(): void
    {
        $this->useStorage();
        $this->freshPipelineTable();
        $this->writeSignals([$this->signal('merge')]);

        $result = (new AtlasLoopStagnationAlarmDetector)->evaluate('camp-1', 3600);

        $this->assertSame('healthy', $result['reason_code']);
        $this->assertFalse($result['stagnated']);
        $this->assertSame(1, $result['fact_evidence']['merge_count']);
    }

    public function test_idle_when_signal_file_exists_but_no_decision_certification_or_merge_signals_exist(): void
    {
        $this->useStorage();
        $this->freshPipelineTable();
        $this->writeSignals([$this->signal('learning')]);

        $result = (new AtlasLoopStagnationAlarmDetector)->evaluate('camp-1', 3600);

        $this->assertSame('idle', $result['reason_code']);
        $this->assertFalse($result['stagnated']);
        $this->assertSame(0, $result['fact_evidence']['decision_count']);
        $this->assertSame(0, $result['fact_evidence']['certification_count']);
        $this->assertSame(0, $result['fact_evidence']['merge_count']);
    }

    public function test_indeterminate_when_signal_file_and_pipeline_table_are_absent(): void
    {
        $this->useStorage();
        Schema::dropIfExists(AtlasLoopDeliveryPipeline::TABLE);

        $result = (new AtlasLoopStagnationAlarmDetector)->evaluate('camp-1', 3600);

        $this->assertSame('indeterminate', $result['reason_code']);
        $this->assertFalse($result['stagnated']);
        $this->assertSame(0, $result['fact_evidence']['pipeline_rows']);
    }

    private function freshPipelineTable(): void
    {
        if (! Schema::hasTable(AtlasLoopDeliveryPipeline::TABLE)) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
        DB::table(AtlasLoopDeliveryPipeline::TABLE)->delete();
    }

    private function useStorage(): string
    {
        $path = sys_get_temp_dir().'/atlas-stagnation-alarm-'.bin2hex(random_bytes(4));
        mkdir($path, 0o755, true);
        $this->paths[] = $path;
        app()->useStoragePath($path);

        return $path;
    }

    /**
     * @param  list<array<string,mixed>>  $signals
     */
    private function writeSignals(array $signals): void
    {
        $dir = storage_path('app/atlas-loop/signals');
        File::ensureDirectoryExists($dir);
        $lines = array_map(static fn (array $signal): string => json_encode($signal, JSON_THROW_ON_ERROR), $signals);
        File::put($dir.'/2026-06-24.jsonl', implode("\n", $lines)."\n");
    }

    /**
     * @return array<string,mixed>
     */
    private function signal(string $stage, string $campaignId = 'camp-1'): array
    {
        return [
            'schema_version' => 'atlas.loop.cycle_signal.v1',
            'emitted_at' => Carbon::now('UTC')->subMinutes(10)->toIso8601String(),
            'stage' => $stage,
            'campaign_id' => $campaignId,
            'cycle_id' => 'cycle-1',
            'payload' => [],
        ];
    }
}
