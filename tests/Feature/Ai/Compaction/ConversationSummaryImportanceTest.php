<?php

namespace Tests\Feature\Ai\Compaction;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AiCompactionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class ConversationSummaryImportanceTest extends TestCase
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
        foreach ([
            'ai_compactions',
            'ai_session_states',
            'ai_messages',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        $this->dropLongHorizonPersistenceTables();

        parent::tearDown();
    }

    public function test_summary_ranking_keeps_high_importance_late_decision_and_accounts_for_cut_decisions(): void
    {
        [$thread, $session] = $this->threadAndSession();

        AiSessionState::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'version' => 1,
            'active' => true,
            'objective' => 'CPT-03 summary ranking',
            'current_phase' => 'verification',
            'decisions' => [
                ['id' => 'decision-1', 'text' => 'Decision one low optional note'],
                ['id' => 'decision-2', 'text' => 'Decision two low optional note'],
                ['id' => 'decision-3', 'text' => 'Decision three low optional note'],
                ['id' => 'decision-4', 'text' => 'Decision four low optional note'],
                ['id' => 'decision-5', 'text' => 'Decision five low optional note'],
                ['id' => 'decision-6', 'text' => 'Decision six low optional note'],
                ['id' => 'decision-7', 'text' => 'CRITICAL-DECISION-SEVEN must survive because importance is high', 'importance' => 100],
                ['id' => 'decision-8', 'text' => 'Decision eight low optional note'],
            ],
            'open_loops' => [],
            'next_steps' => [],
            'constraints' => [],
            'metadata' => [],
        ]);
        $this->seedMessages($thread, 12);

        $compaction = app(AiCompactionService::class)->compact($thread, $session, 'manual', [
            'summary_token_budget' => 100,
        ]);

        $this->assertStringContainsString('CRITICAL-DECISION-SEVEN', $compaction->summary);
        $this->assertStringNotContainsString('Decision one low optional note', $compaction->summary);

        $receipt = AtlasLongHorizonCompactionReceipt::query()->firstOrFail();
        $droppedIds = collect($receipt->unresolved_loss)->pluck('id')->all();

        $this->assertContains('decision-1', $droppedIds);
        $this->assertContains('rehydrate decision:decision-1 from canonical sources', $receipt->recovery_queries);
        $this->assertNotContains('decision-7', $droppedIds);
    }

    public function test_changing_importance_changes_which_decision_survives(): void
    {
        [$thread, $session] = $this->threadAndSession();

        AiSessionState::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'version' => 1,
            'active' => true,
            'objective' => 'CPT-03 sensitivity',
            'current_phase' => 'verification',
            'decisions' => [
                ['id' => 'decision-a', 'text' => 'ALPHA-SHOULD-DROP low signal', 'importance' => 1],
                ['id' => 'decision-b', 'text' => 'BETA-SHOULD-SURVIVE high signal', 'importance' => 100],
                ['id' => 'decision-c', 'text' => 'CHARLIE-SHOULD-DROP low signal', 'importance' => 1],
            ],
            'open_loops' => [],
            'next_steps' => [],
            'constraints' => [],
            'metadata' => [],
        ]);
        $this->seedMessages($thread, 6);

        $first = app(AiCompactionService::class)->compact($thread, $session, 'manual', [
            'summary_token_budget' => 55,
        ]);
        $this->assertStringContainsString('BETA-SHOULD-SURVIVE', $first->summary);

        AiSessionState::query()->where('thread_id', $thread->id)->update([
            'decisions' => json_encode([
                ['id' => 'decision-a', 'text' => 'ALPHA-SHOULD-SURVIVE high signal', 'importance' => 100],
                ['id' => 'decision-b', 'text' => 'BETA-SHOULD-DROP low signal', 'importance' => 1],
                ['id' => 'decision-c', 'text' => 'CHARLIE-SHOULD-DROP low signal', 'importance' => 1],
            ], JSON_THROW_ON_ERROR),
        ]);

        $second = app(AiCompactionService::class)->compact($thread->refresh(), $session->refresh(), 'manual', [
            'summary_token_budget' => 55,
        ]);

        $this->assertStringContainsString('ALPHA-SHOULD-SURVIVE', $second->summary);
        $this->assertStringNotContainsString('BETA-SHOULD-DROP', $second->summary);
    }

    /**
     * @return array{0:AiThread,1:AiSession}
     */
    private function threadAndSession(): array
    {
        $thread = AiThread::query()->create([
            'title' => 'CPT-03 conversation',
            'status' => 'active',
            'surface' => 'cli',
            'workspace' => base_path(),
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'dev',
            'message_count' => 1,
            'token_estimate' => 1000,
            'metadata' => [],
        ]);

        return [$thread, $session];
    }

    private function seedMessages(AiThread $thread, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => $i,
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'status' => 'final',
                'content' => 'conversation turn '.$i.' with ordinary filler content',
                'token_estimate' => 80,
                'metadata' => [],
            ]);
        }
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
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->string('status')->default('active');
            $table->text('purpose')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('token_estimate')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->integer('token_estimate')->nullable();
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
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('constraints')->nullable();
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
    }
}
