<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\Memory\AtlasMemoryActorTagger;
use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationV2Reader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ASI-12 — additive `actor` column on `atlas_memory_entry_usages` plus a
 * per-actor volume-normalized concentration reader (v2). The v1 aggregate
 * (`AtlasMemoryRecallConcentrationDemotion`) is intentionally NOT touched;
 * this test proves the plan's negative case: two actors with 10:1 volume
 * cannot mask concentration on the low-volume actor.
 */
final class Asi12MultiActorConcentrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-12T09:00:00+00:00');
        Schema::dropIfExists('atlas_memory_entry_usages');
        Schema::create('atlas_memory_entry_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('memory_entry_id')->index();
            $table->uuid('trace_id')->nullable();
            $table->uuid('context_snapshot_id')->nullable();
            $table->string('source_type', 80);
            $table->string('actor', 120)->nullable()->index();
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_memory_entry_usages');
        parent::tearDown();
    }

    public function test_tagger_returns_stable_labels_for_named_writers(): void
    {
        $tagger = new AtlasMemoryActorTagger;
        $this->assertSame('brain', $tagger->derive(['created_by' => 'atlas_brain']));
        $this->assertSame('watchdog', $tagger->derive(['created_by' => 'atlas_watchdog']));
        $this->assertSame('autonomos:worker-3', $tagger->derive(['worker' => 'worker-3']));
        $this->assertSame(
            'autonomos:atlas_task_serving',
            $tagger->derive(['created_by' => 'atlas_task_serving']),
        );
        $this->assertSame('interactive:sess-abc', $tagger->derive(['session_id' => 'sess-abc']));
        $this->assertSame('unknown', $tagger->derive([]));
        $this->assertSame('explicit:v', $tagger->derive(['actor' => 'explicit:v']));
    }

    public function test_below_min_recalls_returns_insufficient_signal(): void
    {
        $reader = new AtlasMemoryRecallConcentrationV2Reader;
        $result = $reader->read(['window_days' => 7, 'min_recalls' => 100, 'min_actor_recalls' => 5]);

        $this->assertSame('insufficient_signal', $result['status']);
        $this->assertSame('below_min_recalls', $result['reason']);
    }

    public function test_loud_actor_with_10_to_1_volume_does_not_mask_concentration_on_quiet_actor(): void
    {
        $memoryHot = (string) Str::uuid();
        $memoryOther = (string) Str::uuid();

        // Loud actor (100 usages, split 50/50 across two memories — nothing looks concentrated globally).
        for ($i = 0; $i < 50; $i++) {
            $this->insertUsage($memoryHot, 'interactive:loud-session');
            $this->insertUsage($memoryOther, 'interactive:loud-session');
        }

        // Quiet actor (10 usages, ALL on memoryHot — 100% concentration for THAT actor).
        for ($i = 0; $i < 10; $i++) {
            $this->insertUsage($memoryHot, 'autonomos:worker-1');
        }

        $reader = new AtlasMemoryRecallConcentrationV2Reader;
        $result = $reader->read(['window_days' => 7, 'min_recalls' => 100, 'min_actor_recalls' => 10]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(110, $result['total_usages']);
        $this->assertContains('interactive:loud-session', $result['actors']);
        $this->assertContains('autonomos:worker-1', $result['actors']);

        $top = $result['per_memory'][0];
        $this->assertSame($memoryHot, $top['memory_id']);
        // v1-style global share: memoryHot has 60/110 = 0.545… — the loud actor DILUTES it.
        $this->assertLessThan(0.7, $top['global_share']);
        // v2 per-actor: worker-1 is 100% concentrated on memoryHot.
        $this->assertEqualsWithDelta(1.0, $top['max_per_actor_share'], 0.001);
        $this->assertSame('autonomos:worker-1', $top['max_per_actor']);
    }

    public function test_missing_actor_column_returns_actor_column_missing_reason(): void
    {
        Schema::dropIfExists('atlas_memory_entry_usages');
        Schema::create('atlas_memory_entry_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('memory_entry_id')->index();
            $table->string('source_type', 80);
            $table->timestamps();
        });

        $reader = new AtlasMemoryRecallConcentrationV2Reader;
        $result = $reader->read();

        $this->assertSame('insufficient_signal', $result['status']);
        $this->assertSame('actor_column_missing', $result['reason']);
    }

    private function insertUsage(string $memoryId, string $actor): void
    {
        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => $memoryId,
            'source_type' => AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER,
            'actor' => $actor,
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
