<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compaction;

use App\Models\AiMessage;
use App\Models\AiProviderHandoff;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\Context\AiConversationContextBuilder;
use App\Services\Ai\AiProviderHandoffService;
use App\Services\Ai\Context\ConversationContextInput;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class ProviderHandoffReceiptTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
        $this->createAiRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiRuntimeTables();
        $this->dropLongHorizonPersistenceTables();
        parent::tearDown();
    }

    public function test_provider_switch_creates_handoff_row_and_canonical_handoff_receipt(): void
    {
        [$thread, $session] = $this->threadSessionWithState();

        $handoff = app(AiProviderHandoffService::class)->createIfSwitching(
            $thread,
            $session,
            'gpt',
            metadata: ['trigger' => 'test'],
        );

        $this->assertInstanceOf(AiProviderHandoff::class, $handoff);
        $this->assertDatabaseCount('ai_provider_handoffs', 1);

        $receipt = AtlasLongHorizonCompactionReceipt::query()->firstOrFail();
        $this->assertSame(AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF, $receipt->scope_type);
        $this->assertSame($handoff->id, $receipt->scope_id);
        $this->assertSame(1.0, $receipt->must_keep_coverage);
        $this->assertSame([], $receipt->unresolved_loss);
        $this->assertSame(AtlasLongHorizonCanon::LOSS_RISK_LOW, $receipt->loss_risk);
        $this->assertSame('persisted', data_get($handoff->metadata, 'long_horizon_compaction_receipt_status'));
        $this->assertSame($receipt->receipt_hash, data_get($handoff->metadata, 'long_horizon_compaction_receipt_hash'));
    }

    public function test_provider_switch_fails_open_when_receipts_table_is_missing(): void
    {
        [$thread, $session] = $this->threadSessionWithState();
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');

        $handoff = app(AiProviderHandoffService::class)->createIfSwitching(
            $thread,
            $session,
            'gpt',
            metadata: ['trigger' => 'missing_receipt_table_test'],
        );

        $this->assertInstanceOf(AiProviderHandoff::class, $handoff);
        $this->assertDatabaseCount('ai_provider_handoffs', 1);
        $this->assertFalse(Schema::hasTable('atlas_long_horizon_compaction_receipts'));
        $this->assertSame('failed_open', data_get($handoff->metadata, 'long_horizon_compaction_receipt_status'));
        $this->assertSame(
            'atlas_long_horizon_compaction_receipts_table_missing',
            data_get($handoff->metadata, 'long_horizon_compaction_receipt_reason'),
        );
        $this->assertNotEmpty(data_get($handoff->metadata, 'long_horizon_compaction_receipt_hash'));
    }

    public function test_skip_providers_come_from_config_not_hardcoded_list(): void
    {
        config(['atlas.ai.handoff_skip_providers' => ['gpt']]);
        [$thread, $session] = $this->threadSessionWithState();

        $handoff = app(AiProviderHandoffService::class)->createIfSwitching($thread, $session, 'gpt');

        $this->assertNull($handoff);
        $this->assertDatabaseCount('ai_provider_handoffs', 0);
        $this->assertDatabaseCount('atlas_long_horizon_compaction_receipts', 0);
    }

    public function test_fair_mode_records_loss_receipt_without_creating_or_injecting_handoff_row(): void
    {
        [$thread, $session] = $this->threadSessionWithState();

        $baseline = app(AiProviderHandoffService::class)->create($thread, $session, 'claude', 'gpt', 'baseline');
        $contextBefore = $this->conversationContext($thread->id);
        $rowCountBefore = AiProviderHandoff::query()->count();

        $receipt = app(AiProviderHandoffService::class)->recordFairModeLossReceipt($thread, $session, 'gpt', [
            'trigger' => 'fair_mode_test',
        ]);

        $contextAfter = $this->conversationContext($thread->id);

        $this->assertSame($rowCountBefore, AiProviderHandoff::query()->count());
        $this->assertSame($contextBefore['latest_provider_handoff'], $contextAfter['latest_provider_handoff']);
        $this->assertSame($baseline->id, data_get($contextAfter, 'latest_provider_handoff.id'));

        $this->assertSame(AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF, $receipt['scope_type']);
        $this->assertFalse($receipt['write_allowed']);
        $this->assertLessThan(1.0, $receipt['must_keep_coverage']);
        $this->assertNotEmpty($receipt['unresolved_loss']);
        $this->assertDatabaseCount('atlas_long_horizon_compaction_receipts', 2);
    }

    /**
     * @return array{0:AiThread,1:AiSession}
     */
    private function threadSessionWithState(): array
    {
        $thread = AiThread::query()->create([
            'title' => 'Provider handoff receipt',
            'summary' => 'Thread summary',
            'status' => 'active',
            'last_provider' => 'claude',
            'message_count' => 2,
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'provider_primary' => 'claude',
            'provider_last' => 'claude',
        ]);
        AiSessionState::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'active' => true,
            'objective' => 'Keep the provider handoff governed',
            'current_phase' => 'implementation',
            'decisions' => [['text' => 'Use long-horizon receipt for provider handoff']],
            'open_loops' => [['text' => 'Do not contaminate fair mode context']],
            'next_steps' => [['text' => 'Verify receipt row']],
            'constraints' => [],
        ]);
        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => 'Switch provider.',
            'provider' => 'claude',
        ]);

        return [$thread, $session];
    }

    private function conversationContext(string $threadId): array
    {
        return (new AiConversationContextBuilder(new ConversationContextInput))->build([
            'payload' => ['thread_id' => $threadId],
        ]);
    }

    private function createAiRuntimeTables(): void
    {
        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->string('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->string('status')->default('active');
            $table->text('purpose')->nullable();
            $table->string('provider_primary')->nullable();
            $table->string('provider_last')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('token_estimate')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('trace_id')->nullable();
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->text('objective')->nullable();
            $table->text('current_phase')->nullable();
            $table->text('current_topic')->nullable();
            $table->text('user_position')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('relevant_artifacts')->nullable();
            $table->json('constraints')->nullable();
            $table->json('provider_context')->nullable();
            $table->json('quality_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_compactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->string('reason');
            $table->integer('source_position_start')->nullable();
            $table->integer('source_position_end')->nullable();
            $table->integer('source_message_count')->default(0);
            $table->text('summary');
            $table->json('structured_state')->nullable();
            $table->integer('token_estimate_before')->nullable();
            $table->integer('token_estimate_after')->nullable();
            $table->string('quality_gate_status')->default('passed');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_provider_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->string('from_provider')->nullable();
            $table->string('to_provider');
            $table->string('reason')->default('provider_switch');
            $table->text('brief_text');
            $table->json('brief_json')->nullable();
            $table->uuid('compaction_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function dropAiRuntimeTables(): void
    {
        foreach (['ai_provider_handoffs', 'ai_compactions', 'ai_session_states', 'ai_messages', 'ai_sessions', 'ai_threads'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
