<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compaction;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Services\Ai\AiCompactionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CompactionQualityGateBlocksOverwriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        parent::tearDown();
    }

    public function test_quality_gate_needs_review_persists_candidate_but_does_not_overwrite_thread_summary(): void
    {
        [$needsReviewThread, $needsReviewSession] = $this->threadFixture(
            previousSummary: 'previous good summary',
            objective: null,
        );

        $needsReviewCompaction = app(AiCompactionService::class)->compact($needsReviewThread, $needsReviewSession, 'manual');
        $needsReviewThread->refresh();

        $this->assertSame('needs_review', $needsReviewCompaction->quality_gate_status);
        $this->assertNotSame('previous good summary', $needsReviewCompaction->summary);
        $this->assertSame('previous good summary', $needsReviewThread->summary);
        $this->assertTrue(data_get($needsReviewCompaction->metadata, 'thread_summary_overwrite_skipped'));
        $this->assertSame($needsReviewCompaction->id, data_get($needsReviewThread->metadata, 'last_compaction_id'));

        [$passedThread, $passedSession] = $this->threadFixture(
            previousSummary: 'older passed summary',
            objective: 'Keep the passed compaction candidate',
        );

        $passedCompaction = app(AiCompactionService::class)->compact($passedThread, $passedSession, 'manual');
        $passedThread->refresh();

        $this->assertSame('passed', $passedCompaction->quality_gate_status);
        $this->assertSame($passedCompaction->summary, $passedThread->summary);
        $this->assertNotSame('older passed summary', $passedThread->summary);
        $this->assertFalse((bool) data_get($passedCompaction->metadata, 'thread_summary_overwrite_skipped', false));
    }

    /**
     * @return array{0:AiThread,1:AiSession}
     */
    private function threadFixture(string $previousSummary, ?string $objective): array
    {
        $thread = AiThread::query()->create([
            'title' => 'CPT-04 quality gate fixture',
            'summary' => $previousSummary,
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
            'token_estimate' => 800,
            'metadata' => [],
        ]);
        AiSessionState::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'version' => 1,
            'active' => true,
            'objective' => $objective,
            'current_phase' => $objective === null ? null : 'implementation',
            'current_topic' => null,
            'decisions' => [],
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
            'content' => 'Please compact this conversation.',
            'token_estimate' => 300,
            'metadata' => [],
        ]);
        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 2,
            'role' => 'assistant',
            'status' => 'final',
            'content' => 'Candidate summary should be persisted for audit.',
            'token_estimate' => 500,
            'metadata' => [],
        ]);

        return [$thread, $session];
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
