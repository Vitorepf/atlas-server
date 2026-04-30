<?php

namespace Tests\Unit;

use App\Models\AiSession;
use App\Models\AiThread;
use App\Services\Ai\AiSessionStateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiSessionStateServicePendingSteerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_session_states',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_sets_and_consumes_pending_steer_once(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Implementar P1',
            'status' => 'active',
            'surface' => 'atlas_cli',
            'workspace' => base_path(),
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'started_at' => now(),
            'metadata' => [],
        ]);

        $service = app(AiSessionStateService::class);
        $state = $service->setPendingSteer($thread->id, 'foque apenas nos testes', $session->id);

        $this->assertSame('foque apenas nos testes', $state->pending_steer);
        $this->assertSame(2, $state->version);

        $steer = $service->consumePendingSteer($thread, $session);
        $this->assertSame('foque apenas nos testes', $steer);

        $state->refresh();
        $this->assertNull($state->pending_steer);
        $this->assertSame(3, $state->version);

        $this->assertNull($service->consumePendingSteer($thread, $session));
    }

    private function createTables(): void
    {
        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
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

        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
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
            $table->text('pending_steer')->nullable();
            $table->json('provider_context')->nullable();
            $table->json('quality_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
