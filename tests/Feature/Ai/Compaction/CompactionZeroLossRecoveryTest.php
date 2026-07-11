<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compaction;

use App\Models\AiSessionState;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\CompactionRecoveryExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class CompactionZeroLossRecoveryTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLongHorizonPersistenceTables();
        $this->createSessionStateTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        Schema::dropIfExists('atlas_memory_entries');
        Schema::dropIfExists('ai_session_states');
        $this->dropLongHorizonPersistenceTables();

        parent::tearDown();
    }

    public function test_loss_recovery_queries_rehydrate_each_critical_item_without_writes(): void
    {
        AiSessionState::query()->create([
            'thread_id' => '00000000-0000-0000-0000-000000000123',
            'session_id' => '00000000-0000-0000-0000-000000000456',
            'active' => true,
            'objective' => 'CPT-06 zero-loss recovery',
            'decisions' => [
                ['id' => 'decision-keep', 'text' => 'Decision: preserve the measured-only context policy.'],
            ],
            'open_loops' => [
                ['id' => 'blocker-keep', 'text' => 'Blocker: recovery must stay read-only.'],
            ],
            'next_steps' => [
                ['id' => 'dod-keep', 'text' => 'DoD: recovered item content is byte-intact.'],
            ],
            'constraints' => [],
        ]);

        $receiptPayload = app(AiCompactionService::class)->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => '00000000-0000-0000-0000-000000000123',
            'forced_discards' => [
                ['id' => 'decision-keep', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
                ['id' => 'blocker-keep', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
                ['id' => 'dod-keep', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
            ],
        ]);
        $receipt = AtlasLongHorizonCompactionReceipt::query()
            ->where('uuid', $receiptPayload['receipt_uuid'])
            ->firstOrFail();

        $this->assertFalse($receiptPayload['write_allowed']);
        $this->assertContains('rehydrate decision:decision-keep from canonical sources', $receipt->recovery_queries);
        $this->assertContains('rehydrate blocker:blocker-keep from canonical sources', $receipt->recovery_queries);
        $this->assertContains('rehydrate dod:dod-keep from canonical sources', $receipt->recovery_queries);

        $memoryDeltaExistsBefore = Schema::hasTable('ai_memory_deltas') ? \DB::table('ai_memory_deltas')->count() : 0;
        $memoryEntryExistsBefore = Schema::hasTable('atlas_memory_entries') ? \DB::table('atlas_memory_entries')->count() : 0;

        $recovery = app(CompactionRecoveryExecutor::class)->recover($receipt);

        $this->assertSame('atlas.long_horizon.compaction_recovery.v1', $recovery['schema_version']);
        $this->assertSame('recovered', $recovery['status']);
        $this->assertSame(3, $recovery['recovered_count']);
        $this->assertSame([], $recovery['missing']);
        $this->assertSame('Decision: preserve the measured-only context policy.', data_get($recovery, 'items.decision:decision-keep.payload.text'));
        $this->assertSame('Blocker: recovery must stay read-only.', data_get($recovery, 'items.blocker:blocker-keep.payload.text'));
        $this->assertSame('DoD: recovered item content is byte-intact.', data_get($recovery, 'items.dod:dod-keep.payload.text'));
        $this->assertTrue(data_get($recovery, 'policy.read_only'));
        $this->assertSame('LongHorizonMemoryPromotionGuard', data_get($recovery, 'policy.write_gate'));

        $this->assertSame($memoryDeltaExistsBefore, Schema::hasTable('ai_memory_deltas') ? \DB::table('ai_memory_deltas')->count() : 0);
        $this->assertSame($memoryEntryExistsBefore, Schema::hasTable('atlas_memory_entries') ? \DB::table('atlas_memory_entries')->count() : 0);
    }

    private function createSessionStateTable(): void
    {
        Schema::dropIfExists('ai_session_states');
        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->text('objective')->nullable();
            $table->string('current_phase')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('constraints')->nullable();
            $table->timestamps();
        });
    }
}
