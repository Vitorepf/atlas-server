<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Observability;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopQueueDryingAlarmDetector;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopQueueDryingAlarmDetectorTest extends TestCase
{
    private string $signalsDir;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-24T12:00:00Z'));
        $this->signalsDir = sys_get_temp_dir().'/atlas-queue-drying-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->signalsDir);
        $this->freshPipelineTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(AtlasLoopDeliveryPipeline::TABLE);
        File::deleteDirectory($this->signalsDir);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_empty_terminal_with_decision_signal_is_drying(): void
    {
        $this->seedBuckets([3, 2, 1, 0]);
        $this->writeDecisionSignal();

        $result = $this->evaluate();

        $this->assertSame(['schema_version', 'campaign_id', 'window_seconds', 'evaluated_at', 'drying', 'slope_signal', 'fact_evidence'], array_keys($result));
        $this->assertSame('atlas.loop.queue_drying_alarm.v1', $result['schema_version']);
        $this->assertSame('camp-1', $result['campaign_id']);
        $this->assertSame(400, $result['window_seconds']);
        $this->assertSame('2026-06-24T12:00:00+00:00', $result['evaluated_at']);
        $this->assertTrue($result['drying']);
        $this->assertSame('empty_terminal', $result['slope_signal']);
        $this->assertSame([3, 2, 1, 0], $result['fact_evidence']['buckets']);
        $this->assertSame(1, $result['fact_evidence']['decision_signals_in_window']);
    }

    public function test_decreasing_halved_with_decision_signal_is_drying(): void
    {
        $this->seedBuckets([8, 7, 5, 3]);
        $this->writeDecisionSignal();

        $result = $this->evaluate();

        $this->assertTrue($result['drying']);
        $this->assertSame('decreasing_halved', $result['slope_signal']);
        $this->assertSame([8, 7, 5, 3], $result['fact_evidence']['buckets']);
    }

    public function test_flat_empty_with_decision_signal_is_drying(): void
    {
        $this->seedBuckets([0, 0, 0, 0]);
        $this->writeDecisionSignal();

        $result = $this->evaluate();

        $this->assertTrue($result['drying']);
        $this->assertSame('flat_empty', $result['slope_signal']);
        $this->assertSame([0, 0, 0, 0], $result['fact_evidence']['buckets']);
    }

    public function test_stable_or_growing_is_not_drying(): void
    {
        $this->seedBuckets([1, 2, 2, 3]);
        $this->writeDecisionSignal();

        $result = $this->evaluate();

        $this->assertFalse($result['drying']);
        $this->assertSame('stable_or_growing', $result['slope_signal']);
        $this->assertSame([1, 2, 2, 3], $result['fact_evidence']['buckets']);
    }

    public function test_missing_table_is_indeterminate_fail_open(): void
    {
        Schema::dropIfExists(AtlasLoopDeliveryPipeline::TABLE);

        $result = $this->evaluate();

        $this->assertFalse($result['drying']);
        $this->assertSame('indeterminate', $result['slope_signal']);
        $this->assertSame([0, 0, 0, 0], $result['fact_evidence']['buckets']);
        $this->assertFalse($result['fact_evidence']['table_present']);
    }

    public function test_idle_without_decision_signal_is_not_drying(): void
    {
        $this->seedBuckets([0, 0, 0, 0]);

        $result = $this->evaluate();

        $this->assertFalse($result['drying']);
        $this->assertSame('flat_empty', $result['slope_signal']);
        $this->assertSame(0, $result['fact_evidence']['decision_signals_in_window']);
    }

    /**
     * @param  list<int>  $counts
     */
    private function seedBuckets(array $counts): void
    {
        $now = Carbon::now('UTC');
        $start = $now->copy()->subSeconds(400);

        foreach ($counts as $bucket => $count) {
            $updatedAt = $start->copy()->addSeconds(($bucket * 100) + 20);
            for ($row = 0; $row < $count; $row++) {
                DB::table(AtlasLoopDeliveryPipeline::TABLE)->insert([
                    'campaign_id' => 'camp-1',
                    'objective_id' => 'objective-'.$bucket.'-'.$row,
                    'stage' => 'projection',
                    'claim_owner' => 'worker-'.$bucket.'-'.$row,
                    'lease_expires_at' => $now->copy()->addMinutes(10),
                    'checkpoint' => json_encode([], JSON_THROW_ON_ERROR),
                    'accrued_ev' => 0.0,
                    'obligation_set' => json_encode([], JSON_THROW_ON_ERROR),
                    'created_at' => $updatedAt,
                    'updated_at' => $updatedAt,
                ]);
            }
        }
    }

    private function evaluate(): array
    {
        return (new AtlasLoopQueueDryingAlarmDetector($this->signalsDir))->evaluate('camp-1', 400);
    }

    private function freshPipelineTable(): void
    {
        Schema::dropIfExists(AtlasLoopDeliveryPipeline::TABLE);
        Schema::create(AtlasLoopDeliveryPipeline::TABLE, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('campaign_id')->index();
            $table->string('objective_id')->unique();
            $table->string('stage')->default('projection');
            $table->string('claim_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->json('checkpoint')->nullable();
            $table->double('accrued_ev')->default(0.0);
            $table->json('obligation_set')->nullable();
            $table->timestamps();
        });
    }

    private function writeDecisionSignal(): void
    {
        File::put(
            $this->signalsDir.'/2026-06-24.jsonl',
            json_encode([
                'schema_version' => 'atlas.loop.cycle_signal.v1',
                'emitted_at' => Carbon::now('UTC')->subMinute()->toIso8601String(),
                'stage' => 'DECISION',
                'campaign_id' => 'camp-1',
                'cycle_id' => 'cycle-1',
                'payload' => [],
            ], JSON_THROW_ON_ERROR)."\n",
        );
    }
}
