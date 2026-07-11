<?php

namespace Tests\Feature\Ai\Compaction;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class ConversationCompactionReceiptTest extends TestCase
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

    public function test_compact_emits_real_conversation_receipt_covering_must_keep_in_visible_summary(): void
    {
        $this->assertTrue(
            defined(AtlasLongHorizonCanon::class.'::SCOPE_TYPE_CONVERSATION'),
            'CPT-02 requires a canonical conversation scope type.',
        );

        $thread = AiThread::query()->create([
            'title' => 'CPT-02 conversation',
            'status' => 'active',
            'surface' => 'cli',
            'workspace' => base_path(),
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'dev',
            'message_count' => 2,
            'token_estimate' => 1200,
            'metadata' => [],
        ]);
        AiSessionState::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'version' => 1,
            'active' => true,
            'objective' => 'Validate CPT-02 receipt path',
            'current_phase' => 'verification',
            'decisions' => [
                [
                    'id' => 'decision-preserve-mcp',
                    'text' => 'Preserve MCP contract in compacted summary',
                ],
            ],
            'open_loops' => [],
            'next_steps' => [],
            'relevant_artifacts' => [],
            'constraints' => [],
            'metadata' => [],
        ]);

        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => 'We must preserve the MCP contract.',
            'token_estimate' => 500,
            'metadata' => [],
        ]);
        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 2,
            'role' => 'assistant',
            'status' => 'final',
            'content' => 'Decision recorded: Preserve MCP contract in compacted summary.',
            'token_estimate' => 700,
            'metadata' => [],
        ]);

        $compaction = app(AiCompactionService::class)->compact($thread, $session, 'manual');

        $receipt = AtlasLongHorizonCompactionReceipt::query()->firstOrFail();
        $this->assertSame(constant(AtlasLongHorizonCanon::class.'::SCOPE_TYPE_CONVERSATION'), $receipt->scope_type);
        $this->assertSame((string) $thread->id, $receipt->scope_id);
        $this->assertSame(1.0, $receipt->must_keep_coverage);
        $this->assertSame(AtlasLongHorizonCanon::LOSS_RISK_LOW, $receipt->loss_risk);
        $this->assertSame([], $receipt->unresolved_loss);
        $this->assertSame('decision-preserve-mcp', $receipt->retained_items[0]['id'] ?? null);
        $this->assertSame(hash('sha256', $compaction->summary), $receipt->summary_hash);
        $this->assertSame($receipt->id, data_get($compaction->metadata, 'long_horizon_compaction_receipt_id'));
        $this->assertSame($receipt->receipt_hash, data_get($compaction->metadata, 'long_horizon_compaction_receipt_hash'));
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
    }
}
