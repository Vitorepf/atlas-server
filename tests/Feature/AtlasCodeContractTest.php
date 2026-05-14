<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature contract tests for the Atlas Desktop production-readiness surface
 * (ADR-0002). Each test asserts the exact response shape the desktop bridge
 * relies on so a backend regression breaks loud at CI time.
 *
 * Database is bootstrapped ad-hoc (no RefreshDatabase) because the full
 * Postgres migration set isn't portable to SQLite :memory:.
 */
class AtlasCodeContractTest extends TestCase
{
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('medium');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->string('status')->default('active');
                $t->string('surface')->nullable();
                $t->string('workspace')->nullable();
                $t->string('source_type')->nullable();
                $t->uuid('source_id')->nullable();
                $t->integer('message_count')->default(0);
                $t->timestamp('last_message_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->uuid('trace_id')->nullable();
                $t->integer('position')->default(1);
                $t->string('role');
                $t->string('status')->default('completed');
                $t->text('content')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id')->nullable();
                $t->string('source_type')->default('app');
                $t->uuid('source_id')->nullable();
                $t->string('status')->default('completed');
                $t->text('operator_input')->nullable();
                $t->text('response_text')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_stream_events')) {
            Schema::create('ai_stream_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('trace_id')->nullable();
                $t->uuid('ai_job_id')->nullable();
                $t->uuid('ai_job_attempt_id')->nullable();
                $t->integer('sequence')->default(1);
                $t->string('event_type')->default('message');
                $t->string('channel')->nullable();
                $t->text('content')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamp('occurred_at')->nullable();
            });
        }

        if (! Schema::hasTable('ai_jobs')) {
            Schema::create('ai_jobs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('trace_id')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_jobs')) {
            Schema::create('ai_jobs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('trace_id')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_decisions')) {
            Schema::create('ai_decisions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('trace_id')->nullable();
                $t->string('route_mode')->nullable();
                $t->string('task_type')->nullable();
                $t->string('risk_level')->nullable();
                $t->string('selected_provider')->nullable();
                $t->string('selected_model')->nullable();
                $t->integer('confidence_score')->nullable();
                $t->json('candidates')->nullable();
                $t->json('constraints')->nullable();
                $t->json('metrics_snapshot')->nullable();
                $t->text('reason')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->string('status')->nullable();
                $t->string('decision')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_evidence')) {
            Schema::create('atlas_engineering_evidence', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->string('evidence_type')->nullable();
                $t->string('status')->nullable();
                $t->text('summary')->nullable();
                $t->text('output_excerpt')->nullable();
                $t->timestamp('recorded_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_tool_runs')) {
            Schema::create('atlas_tool_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('tool_slug')->nullable();
                $t->text('workspace')->nullable();
                $t->string('run_context_type')->nullable();
                $t->string('run_context_id')->nullable();
                $t->string('status')->nullable();
                $t->json('summary_json')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_ledger_events')) {
            Schema::create('atlas_ledger_events', function (Blueprint $t) {
                $t->string('event_id')->primary();
                $t->string('schema_version')->nullable();
                $t->string('tenant_id')->nullable();
                $t->string('operator_id')->nullable();
                $t->string('envelope_id')->nullable();
                $t->string('receipt_id')->nullable();
                $t->string('trace_id')->nullable();
                $t->string('correlation_id')->nullable();
                $t->string('causation_id')->nullable();
                $t->string('event_type')->nullable();
                $t->string('emitter_stage')->nullable();
                $t->string('emitter_version')->nullable();
                $t->json('payload')->nullable();
                $t->string('payload_hash')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function test_boot_endpoint_returns_canonical_shape(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/boot');
        $response->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'generated_at',
                'status',
                'kernel' => [
                    'service',
                    'version',
                    'env',
                    'php_version',
                    'db_connected',
                    'storage_path',
                    'storage_writable',
                    'ts',
                ],
                'providers' => ['available', 'degraded', 'source'],
                'mcp' => ['server', 'protocol_version', 'http_enabled', 'status', 'transport'],
                'cartography' => ['repo_root', 'repo_readable', 'vault_root', 'vault_readable'],
                'workspace' => ['cwd', 'is_git'],
                'queue' => ['connection', 'pending', 'failed'],
            ]);
    }

    public function test_mcp_status_endpoint_returns_pill_shape(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/mcp/status');
        $response->assertOk()
            ->assertJsonStructure([
                'server',
                'status',
                'protocol_version',
                'transport',
                'http_enabled',
                'tools_count',
                'tools',
                'docs_indexed',
                'symbols_indexed',
                'freshness' => ['indexed_at', 'drift'],
            ]);
        $this->assertContains($response->json('status'), ['active', 'degraded', 'disabled']);
    }

    public function test_cartography_graph_endpoint_returns_canonical_shape(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-cartography/graph');
        $response->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'generated_at',
                'sources' => ['repo_docs_path', 'obsidian_vault_path', 'repo_indexed_count', 'vault_indexed_count'],
                'audit' => ['pieces_found', 'pieces_missing'],
                'universe',
                'views' => [
                    'atlas-ai-kernel' => ['ribbon', 'pipeline', 'lanes', 'connections'],
                ],
            ]);
    }

    public function test_cartography_recent_changes_endpoint_returns_envelope(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-cartography/recent-changes');
        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'sources' => ['git_commits', 'repo_mtime', 'vault_mtime'],
                'changes',
            ]);
    }

    public function test_works_index_endpoint_returns_data_meta_envelope(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/works');
        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['total'],
            ]);
    }

    public function test_work_store_creates_real_obra_for_atlas_code(): void
    {
        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', [
            'intent' => 'programar uma melhoria no cockpit',
            'objective' => 'entregar Atlas Code utilizável',
            'domain' => 'programming',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('work.title', 'entregar Atlas Code utilizável')
            ->assertJsonPath('work.objective', 'entregar Atlas Code utilizável')
            ->assertJsonPath('work.status', 'active')
            ->assertJsonPath('work.domain', 'programming')
            ->assertJsonPath('work.metadata.origin', 'atlas-code');

        $this->assertDatabaseHas('atlas_projects', [
            'title' => 'entregar Atlas Code utilizável',
            'description' => 'programar uma melhoria no cockpit',
            'domain' => 'programming',
        ]);
    }

    public function test_work_state_returns_real_thread_receipt_gates_and_evidence(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();
        $threadId = (string) Str::uuid();
        $traceId = (string) Str::uuid();
        $decisionId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA test',
            'description' => 'intent',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'ship code cockpit',
            'desired_outcome' => 'ship code cockpit',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-work']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_threads')->insert([
            'id' => $threadId,
            'title' => 'Sessao real',
            'status' => 'active',
            'source_type' => 'atlas_project',
            'source_id' => $projectId,
            'workspace' => $projectId,
            'message_count' => 1,
            'last_message_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_messages')->insert([
            'id' => (string) Str::uuid(),
            'thread_id' => $threadId,
            'position' => 1,
            'role' => 'user',
            'content' => 'executa a obra',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_traces')->insert([
            'id' => $traceId,
            'thread_id' => $threadId,
            'source_type' => 'app',
            'source_id' => $projectId,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_decisions')->insert([
            'id' => $decisionId,
            'trace_id' => $traceId,
            'selected_provider' => 'claude',
            'selected_model' => 'sonnet',
            'confidence_score' => 87,
            'candidates' => json_encode([['provider' => 'codex']]),
            'constraints' => json_encode(['budget_est_usd' => 0.012]),
            'metrics_snapshot' => json_encode(['budget_used_usd' => 0.01]),
            'reason' => 'routing test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_tool_runs')->insert([
            'id' => (string) Str::uuid(),
            'tool_slug' => 'tests',
            'workspace' => '/tmp/atlas-work',
            'run_context_type' => 'atlas_project',
            'run_context_id' => $projectId,
            'status' => 'passed',
            'summary_json' => json_encode(['summary' => 'tests passed']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_engineering_runs')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'status' => 'passed',
            'decision' => 'resolved',
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_engineering_evidence')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'evidence_type' => 'test',
            'status' => 'passed',
            'summary' => 'unit evidence',
            'recorded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders($this->headers())->getJson("/atlas-code/works/{$projectId}/state");

        $response->assertOk()
            ->assertJsonPath('active_thread', $threadId)
            ->assertJsonPath('receipt.id', $decisionId)
            ->assertJsonPath('receipt.primary', 'claude')
            ->assertJsonPath('gates.0.tool_slug', 'tests')
            ->assertJsonPath('evidence.0.kind', 'engineering_run')
            ->assertJsonStructure([
                'work',
                'sessions',
                'messages',
                'sdd' => ['stage', 'steps'],
                'receipt' => ['id', 'primary', 'confidence', 'fallbackChain'],
                'gates',
                'evidence',
            ]);
    }

    public function test_atlas_code_can_send_intent_for_work_through_ai_interactions(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();
        $threadId = (string) Str::uuid();
        $traceId = (string) Str::uuid();
        $capturedOptions = null;

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA intent',
            'description' => 'intent',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'programar com Atlas Code',
            'desired_outcome' => 'programar com Atlas Code',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($projectId, $threadId, $traceId, &$capturedOptions): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Refatorar Decide com contrato', \Mockery::on(function (array $options) use ($projectId, &$capturedOptions): bool {
                    $capturedOptions = $options;

                    return ($options['source_type'] ?? null) === 'app'
                        && ($options['source_id'] ?? null) === $projectId
                        && ($options['new_thread'] ?? null) === true
                        && ($options['kind'] ?? null) === 'interaction'
                        && data_get($options, 'payload.surface_id') === 'atlas_code'
                        && data_get($options, 'payload.flow_id') === 'programming.forge'
                        && data_get($options, 'payload.routing_task') === 'forge'
                        && data_get($options, 'payload.requires_obra') === true
                        && data_get($options, 'payload.obra_id') === $projectId
                        && data_get($options, 'payload.forge_workspace.obra_id') === $projectId
                        && data_get($options, 'payload.forge_workspace.workspace_kind') === 'obras_shared_workspace';
                }))
                ->andReturn(tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
                    'id' => $traceId,
                    'trace_key' => 'trace_atlas_code_test',
                    'thread_id' => $threadId,
                    'source_type' => 'app',
                    'source_id' => $projectId,
                    'status' => 'queued',
                    'operator_input' => 'Refatorar Decide com contrato',
                    'agent_slug' => 'orquestrador',
                    'provider' => 'claude_cli',
                    'skill_versions' => [],
                    'context_refs' => [],
                    'metadata' => ['atlas_code' => true],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])));
        });

        $response = $this->withHeaders($this->headers())->postJson('/ai/interactions', [
            'input_text' => 'Refatorar Decide com contrato',
            'source_type' => 'app',
            'source_id' => $projectId,
            'new_thread' => true,
            'kind' => 'interaction',
            'payload' => [
                'app_surface' => 'atlas_code',
                'surface_id' => 'atlas_code',
                'atlas_mode' => 'forge',
                'current_mode' => 'forge',
                'atlas_workflow_mode' => 'forge',
                'domain_id' => 'programming',
                'flow_id' => 'programming.forge',
                'routing_domain' => 'programming',
                'routing_task' => 'forge',
                'programming_profile' => 'forge',
                'programming_flow' => 'programming.forge',
                'requires_obra' => true,
                'obra_id' => $projectId,
                'work_id' => $projectId,
                'project_id' => $projectId,
                'forge_workspace' => [
                    'schema_version' => 'atlas.forge_workspace_binding.v1',
                    'workspace_kind' => 'obras_shared_workspace',
                    'specialization' => 'forge_workspace',
                    'obra_id' => $projectId,
                    'source' => 'atlas_code',
                ],
                'dev_execution_plan' => [
                    'programming_profile' => 'forge',
                    'programming_flow' => 'programming.forge',
                    'operator_options' => [
                        'complete' => true,
                        'auto_test' => true,
                    ],
                ],
            ],
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('trace.id', $traceId)
            ->assertJsonPath('trace.thread_id', $threadId)
            ->assertJsonPath('trace.source_id', $projectId);

        $this->assertSame($projectId, $capturedOptions['source_id'] ?? null);
        $this->assertSame('atlas_code', data_get($capturedOptions, 'payload.domain_catalog_selection.surface_id'));
        $this->assertSame('programming.forge', data_get($capturedOptions, 'payload.domain_catalog_selection.flow.id'));
    }

    public function test_atlas_code_fails_closed_without_obra(): void
    {
        $capturedOptions = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use (&$capturedOptions): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Refatorar Decide sem Obra', \Mockery::on(function (array $options) use (&$capturedOptions): bool {
                    $capturedOptions = $options;

                    return data_get($options, 'payload.surface_id') === 'atlas_code'
                        && data_get($options, 'payload.requires_obra') === true
                        && data_get($options, 'payload.forge_workspace_blocker.reason') === 'missing_obra_binding'
                        && data_get($options, 'payload.forge_workspace_blocker.surface_id') === 'atlas_code'
                        && data_get($options, 'payload.forge_workspace_blocker.flow_id') === 'programming.forge'
                        && data_get($options, 'payload.forge_workspace') === null;
                }))
                ->andReturn(tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
                    'id' => (string) Str::uuid(),
                    'trace_key' => 'trace_atlas_code_blocked',
                    'thread_id' => null,
                    'source_type' => 'app',
                    'source_id' => null,
                    'status' => 'queued',
                    'operator_input' => 'Refatorar Decide sem Obra',
                    'agent_slug' => 'orquestrador',
                    'provider' => 'claude_cli',
                    'skill_versions' => [],
                    'context_refs' => [],
                    'metadata' => ['atlas_code' => true, 'forge_blocked' => true],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])));
        });

        $response = $this->withHeaders($this->headers())->postJson('/ai/interactions', [
            'input_text' => 'Refatorar Decide sem Obra',
            'source_type' => 'app',
            'new_thread' => true,
            'kind' => 'interaction',
            'payload' => [
                'app_surface' => 'atlas_code',
                'surface_id' => 'atlas_code',
                'atlas_mode' => 'forge',
                'current_mode' => 'forge',
                'atlas_workflow_mode' => 'forge',
                'domain_id' => 'programming',
                'flow_id' => 'programming.forge',
                'routing_domain' => 'programming',
                'routing_task' => 'forge',
                'programming_profile' => 'forge',
                'programming_flow' => 'programming.forge',
            ],
        ]);

        $response->assertAccepted();

        $this->assertSame('missing_obra_binding', data_get($capturedOptions, 'payload.forge_workspace_blocker.reason'));
        $this->assertTrue(data_get($capturedOptions, 'payload.requires_obra'));
        $this->assertNull(data_get($capturedOptions, 'payload.forge_workspace'));
    }

    public function test_ai_interaction_stream_returns_real_sse_frames(): void
    {
        $now = now();
        $traceId = (string) Str::uuid();
        $eventId = (string) Str::uuid();

        DB::table('ai_traces')->insert([
            'id' => $traceId,
            'thread_id' => null,
            'source_type' => 'app',
            'source_id' => null,
            'status' => 'succeeded',
            'operator_input' => 'stream test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_stream_events')->insert([
            'id' => $eventId,
            'trace_id' => $traceId,
            'sequence' => 1,
            'event_type' => 'assistant_message',
            'channel' => 'assistant',
            'content' => 'resposta real do Kernel',
            'metadata' => json_encode(['source' => 'test']),
            'occurred_at' => $now,
        ]);

        $response = $this->withHeaders($this->headers())->get("/ai/interactions/{$traceId}/stream?timeout=5");

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('event: assistant_message', $content);
        $this->assertStringContainsString('resposta real do Kernel', $content);
        $this->assertStringContainsString('event: done', $content);
    }
}
