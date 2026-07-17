<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiStreamEvent;
use App\Models\AiThread;
use App\Models\AiTrace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiInteractionSteerTest extends TestCase
{
    private const TOKEN = 'testing-atlas-token-with-enough-length';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', self::TOKEN);
        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_stream_events',
            'ai_jobs',
            'ai_traces',
            'ai_session_states',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_accepts_current_step_steering_and_publishes_public_event(): void
    {
        [$trace] = $this->makeRunningInteraction();

        $response = $this->withAtlasToken()->postJson("/ai/interactions/{$trace->id}/steer", [
            'instruction' => 'foque somente no teste novo',
            'scope' => 'current_step',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('schema_version', 'atlas.ai.interaction_steer.v1')
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('event', 'steering_accepted')
            ->assertJsonPath('trace_id', $trace->id)
            ->assertJsonPath('scope', 'current_step')
            ->assertJsonPath('delivery.status', 'queued_for_next_safe_checkpoint')
            ->assertJsonMissingPath('job_id')
            ->assertJsonMissingPath('prompt')
            ->assertJsonMissingPath('stdout')
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('internal_ids');

        $this->assertStringNotContainsString('prompt secreto', $response->getContent());
        $this->assertStringNotContainsString('/Users/vitorepf', $response->getContent());

        $this->assertDatabaseHas('ai_session_states', [
            'pending_steer' => 'foque somente no teste novo',
        ]);

        $event = AiStreamEvent::query()->sole();
        $this->assertSame('lifecycle', $event->event_type);
        $this->assertSame('system', $event->channel);
        $this->assertSame('', $event->content);
        $this->assertSame('steering_accepted', $event->metadata['name']);
        $this->assertSame('atlas.ai.interaction_steer_event.v1', $event->metadata['schema_version']);
        $this->assertSame('current_step', $event->metadata['scope']);
        $this->assertArrayNotHasKey('instruction', $event->metadata);
        $this->assertArrayNotHasKey('prompt', $event->metadata);
        $this->assertArrayNotHasKey('stdout', $event->metadata);
        $this->assertArrayNotHasKey('path', $event->metadata);
    }

    public function test_rejects_empty_instruction_with_public_rejection_event(): void
    {
        [$trace] = $this->makeRunningInteraction();

        $response = $this->withAtlasToken()->postJson("/ai/interactions/{$trace->id}/steer", [
            'instruction' => '   ',
            'scope' => 'current_step',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('schema_version', 'atlas.ai.interaction_steer.v1')
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('event', 'steering_rejected')
            ->assertJsonPath('trace_id', $trace->id)
            ->assertJsonPath('reason', 'instruction_required')
            ->assertJsonMissingPath('job_id')
            ->assertJsonMissingPath('prompt')
            ->assertJsonMissingPath('stdout')
            ->assertJsonMissingPath('path');

        $this->assertDatabaseHas('ai_session_states', [
            'pending_steer' => null,
        ]);

        $event = AiStreamEvent::query()->sole();
        $this->assertSame('steering_rejected', $event->metadata['name']);
        $this->assertSame('instruction_required', $event->metadata['reason']);
        $this->assertArrayNotHasKey('instruction', $event->metadata);
    }

    public function test_rejects_invalid_scope_with_public_rejection_event(): void
    {
        [$trace] = $this->makeRunningInteraction();

        $response = $this->withAtlasToken()->postJson("/ai/interactions/{$trace->id}/steer", [
            'instruction' => 'refaca o plano',
            'scope' => 'everything',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('schema_version', 'atlas.ai.interaction_steer.v1')
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('event', 'steering_rejected')
            ->assertJsonPath('trace_id', $trace->id)
            ->assertJsonPath('reason', 'invalid_scope')
            ->assertJsonPath('allowed_scopes.0', 'current_step')
            ->assertJsonPath('allowed_scopes.1', 'replan');

        $event = AiStreamEvent::query()->sole();
        $this->assertSame('steering_rejected', $event->metadata['name']);
        $this->assertSame('invalid_scope', $event->metadata['reason']);
        $this->assertArrayNotHasKey('instruction', $event->metadata);
    }

    private function withAtlasToken(): self
    {
        return $this->withHeader('X-Atlas-Token', self::TOKEN);
    }

    /**
     * @return array{0: AiTrace, 1: AiJob}
     */
    private function makeRunningInteraction(): array
    {
        $thread = AiThread::query()->create([
            'title' => 'M07 steering',
            'status' => 'active',
            'surface' => 'app',
            'workspace' => '/Users/vitorepf/develop/Atlas/atlas-server',
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'started_at' => now(),
            'metadata' => [],
        ]);
        AiSessionState::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'version' => 1,
            'active' => true,
            'objective' => 'M07 steering',
            'current_phase' => 'running',
            'current_topic' => 'steering',
            'decisions' => [],
            'open_loops' => [],
            'next_steps' => [],
            'relevant_artifacts' => [],
            'constraints' => [],
            'provider_context' => [],
            'quality_notes' => [],
            'metadata' => [],
        ]);
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_'.uniqid(),
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'source_type' => 'app',
            'status' => 'processing',
            'operator_input' => 'pedido original',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['prompt' => 'prompt secreto', 'path' => '/Users/vitorepf/private'],
        ]);
        $job = AiJob::query()->create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'processing',
            'priority' => 50,
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'input_text' => 'pedido original',
            'prompt' => 'prompt secreto',
            'context_refs' => [],
            'payload' => [],
            'available_at' => now(),
            'attempts' => 1,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        return [$trace, $job];
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

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('manual');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->text('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->string('worker_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_stream_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id');
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->integer('sequence');
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->text('content')->default('');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
