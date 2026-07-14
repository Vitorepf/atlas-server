<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiSession;
use App\Models\AiThread;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiThreadSurfaceHandoffApiTest extends TestCase
{
    private array $headers = [
        'Accept' => 'application/json',
        'X-Atlas-Token' => 'test-token-with-enough-length-123',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('ai_surface_handoffs');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('ai_threads');

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
        Schema::create('ai_surface_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id');
            $table->string('from_surface', 80);
            $table->string('to_surface', 80);
            $table->string('status', 40);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_surface_handoffs');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('ai_threads');

        parent::tearDown();
    }

    public function test_surface_handoff_preserves_the_canonical_thread_and_session_without_returning_a_brief(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Provar continuidade',
            'status' => 'active',
            'surface' => 'app',
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'provider_primary' => 'claude_cli',
        ]);

        $response = $this->postJson("/api/ai/threads/{$thread->id}/handoff-surface", [
            'to_surface' => 'atlas_terminal',
        ], $this->headers);

        $response
            ->assertOk()
            ->assertJsonPath('handoff.schema_version', 'atlas.ai.surface_handoff.v1')
            ->assertJsonPath('handoff.thread_id', $thread->id)
            ->assertJsonPath('handoff.session_id', $session->id)
            ->assertJsonPath('handoff.from_surface', 'app')
            ->assertJsonPath('handoff.to_surface', 'atlas_terminal')
            ->assertJsonPath('handoff.status', 'ready')
            ->assertJsonMissingPath('handoff.brief_text')
            ->assertJsonMissingPath('handoff.brief_json')
            ->assertJsonMissingPath('handoff.metadata');

        $this->assertDatabaseHas('ai_surface_handoffs', [
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'from_surface' => 'app',
            'to_surface' => 'atlas_terminal',
            'status' => 'ready',
        ]);
    }

    public function test_surface_handoff_rejects_an_unknown_surface_without_persisting_a_receipt(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Provar contrato fechado',
            'status' => 'active',
            'surface' => 'app',
        ]);

        $this->postJson("/api/ai/threads/{$thread->id}/handoff-surface", [
            'to_surface' => 'untrusted_browser',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to_surface');

        $this->assertDatabaseCount('ai_surface_handoffs', 0);
    }
}
