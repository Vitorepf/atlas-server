<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AtlasCodeForgeExecutionController;
use App\Jobs\AtlasCodeForgeLiveExecutionJob;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Programming\AtlasForgeLiveExecutionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesAiMessagesTable;
use Tests\Concerns\CreatesAiTracesTable;
use Tests\Concerns\CreatesAtlasEngineeringRunsTable;
use Tests\Concerns\CreatesAtlasToolRunsTable;
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
    use CreatesAiMessagesTable;
    use CreatesAiTracesTable;
    use CreatesAtlasEngineeringRunsTable;
    use CreatesAtlasToolRunsTable;

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

        $this->createAiMessagesTable();

        $this->createAiTracesTable();

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

        $this->createAtlasEngineeringRunsTable();

        if (! Schema::hasTable('atlas_engineering_evidence')) {
            Schema::create('atlas_engineering_evidence', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('task_id')->nullable()->index();
                $t->uuid('project_id')->nullable();
                $t->uuid('project_step_id')->nullable()->index();
                $t->uuid('trace_id')->nullable()->index();
                $t->string('evidence_type')->nullable();
                $t->string('target_id', 120)->nullable();
                $t->string('status')->nullable();
                $t->decimal('confidence', 5, 3)->nullable();
                $t->text('summary')->nullable();
                $t->string('command', 500)->nullable();
                $t->text('artifact_url')->nullable();
                $t->text('output_excerpt')->nullable();
                $t->json('files')->default('[]');
                $t->json('metadata')->default('{}');
                $t->string('source', 160)->nullable();
                $t->timestamp('recorded_at')->nullable();
                $t->timestamps();
            });
        }

        $this->createAtlasToolRunsTable();

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

        if (! Schema::hasTable('atlas_programming_work_items')) {
            Schema::create('atlas_programming_work_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('code', 64)->unique();
                $t->text('intent_text');
                $t->string('intent_type', 40)->index();
                $t->string('scope_mode', 24)->index();
                $t->string('risk_level', 16)->default('medium')->index();
                $t->string('owner', 80)->nullable()->index();
                $t->string('workspace', 255)->nullable();
                $t->string('status', 32)->index();
                $t->string('current_stage', 32)->index();
                $t->string('spec_hash', 64)->nullable()->index();
                $t->string('plan_hash', 64)->nullable()->index();
                $t->json('placement_json')->default('{}');
                $t->json('code_intelligence_json')->default('{}');
                $t->json('spec_json')->default('{}');
                $t->json('plan_json')->default('{}');
                $t->json('tasks_json')->default('[]');
                $t->json('evidence_refs_json')->default('[]');
                $t->json('gaps_json')->default('[]');
                $t->json('metadata_json')->default('{}');
                $t->timestamp('closed_at')->nullable()->index();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_programming_stage_receipts')) {
            Schema::create('atlas_programming_stage_receipts', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('receipt_id', 64)->unique();
                $t->string('plan_id', 160)->index();
                $t->string('parent_plan_id', 160)->nullable()->index();
                $t->string('stage', 40)->index();
                $t->unsignedSmallInteger('attempt')->default(1);
                $t->string('status', 32)->index();
                $t->string('input_hash', 64);
                $t->string('output_hash', 64);
                $t->json('evidence_refs_json')->default('[]');
                $t->json('payload_json')->default('{}');
                $t->json('validation_json')->default('{}');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_programming_gate_runs')) {
            Schema::create('atlas_programming_gate_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('work_item_id')->index();
                $t->string('gate_name', 64)->index();
                $t->string('status', 24)->index();
                $t->boolean('blocking')->default(true)->index();
                $t->string('input_hash', 64)->nullable();
                $t->string('output_hash', 64)->nullable();
                $t->string('reason', 255)->nullable();
                $t->string('waiver_reason', 255)->nullable();
                $t->string('decided_by', 80)->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->json('payload_json')->default('{}');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_programming_reviews')) {
            Schema::create('atlas_programming_reviews', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('work_item_id')->index();
                $t->string('result', 32)->index();
                $t->text('summary')->nullable();
                $t->text('risk_notes')->nullable();
                $t->string('decided_by', 80)->nullable();
                $t->json('payload_json')->default('{}');
                $t->timestamps();
            });
        }
    }

    public function test_diff_apply_requires_awis_workspace_before_queueing_run(): void
    {
        // Token required: /diffs/{patch}/apply mutates the workspace. It used to
        // sit outside the atlas.token group, so this call was written without
        // headers — the surrounding tests already send them.
        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/diffs/patch-missing/apply', [
            'confirm' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'awis_workspace_required_for_diff_apply')
            ->assertJsonPath('workspace_resolution.status', 'blocked')
            ->assertJsonPath('workspace_resolution.reason', 'missing_workspace');

        $this->assertDatabaseCount('atlas_engineering_runs', 0);
        $this->assertDatabaseCount('atlas_ledger_events', 0);
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

    public function test_atlas_code_can_run_forge_live_execution_for_work(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Live',
            'description' => 'intent',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'rodar Forge Live pela surface',
            'desired_outcome' => 'rodar Forge Live pela surface',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions");

        $response
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.code.forge_live_execution_response.v1')
            ->assertJsonPath('work_id', $projectId)
            ->assertJsonPath('report.forge_live_execution_status', 'passed')
            ->assertJsonPath('snapshot.status', 'passed')
            ->assertJsonPath('snapshot.obra_id', $projectId)
            ->assertJsonPath('snapshot.context_pack.schema_version', 'atlas.code.context_pack_artifact.v1')
            ->assertJsonPath('snapshot.context_pack.context_completeness', 'canonical_minimum')
            ->assertJsonPath('snapshot.context_pack.ranked_ref_count', 11)
            ->assertJsonPath('snapshot.context_pack.present_ref_count', 11)
            ->assertJsonPath('snapshot.context_pack.ranked_refs.0.path', 'docs/engineering-knowledge-base/atlas-programming-forge-flow.md')
            ->assertJsonPath('snapshot.context_pack.ranked_refs.0.evidence_marker', 'present')
            ->assertJsonPath('snapshot.repair_loop.status', 'skipped_not_needed')
            ->assertJsonPath('snapshot.task_contract.schema_version', 'atlas.code.task_contract_artifact.v1')
            ->assertJsonPath('snapshot.task_contract.status', 'verified')
            ->assertJsonPath('snapshot.task_contract.allowed_files.0', 'forge-live-execution-fixture.txt')
            ->assertJsonPath('snapshot.task_contract.expected_files.0', 'forge-live-execution-fixture.txt')
            ->assertJsonPath('snapshot.task_contract.rollback.available', true)
            ->assertJsonPath('snapshot.task_contract.evidence_required.0', 'stage_receipts')
            ->assertJsonPath('snapshot.task_contract.evidence_required.1', 'evidence_pack')
            ->assertJsonPath('snapshot.diff_scope.schema_version', 'atlas.code.diff_scope_artifact.v1')
            ->assertJsonPath('snapshot.diff_scope.status', 'passed')
            ->assertJsonPath('snapshot.diff_scope.scope_status', 'in_scope')
            ->assertJsonPath('snapshot.diff_scope.changed_file_count', 1)
            ->assertJsonPath('snapshot.diff_scope.files.0.path', 'forge-live-execution-fixture.txt')
            ->assertJsonPath('snapshot.diff_scope.files.0.status', 'in_scope')
            ->assertJsonPath('snapshot.diff_scope.completion_gate.completion_claim_allowed', true)
            ->assertJsonPath('snapshot.stage_timeline.schema_version', 'atlas.code.forge_live_execution.stage_timeline.v1')
            ->assertJsonPath('snapshot.stage_timeline.total', 11)
            ->assertJsonPath('snapshot.stage_timeline.passed', 10)
            ->assertJsonPath('snapshot.stage_timeline.skipped', 1)
            ->assertJsonPath('snapshot.stage_timeline.blocking', 0)
            ->assertJsonPath('snapshot.stage_timeline.entries.0.name', 'obra_binding')
            ->assertJsonPath('snapshot.stage_timeline.entries.0.phase', 'context')
            ->assertJsonPath('snapshot.stage_timeline.entries.2.name', 'context_pack')
            ->assertJsonPath('snapshot.stage_timeline.entries.2.summary', 'contexto canonical_minimum | refs 11/11')
            ->assertJsonPath('snapshot.stage_timeline.entries.3.name', 'patch_apply')
            ->assertJsonPath('snapshot.stage_timeline.entries.3.phase', 'execute')
            ->assertJsonPath('snapshot.stage_timeline.entries.6.name', 'test_run')
            ->assertJsonPath('snapshot.stage_timeline.entries.6.phase', 'verify')
            ->assertJsonPath('snapshot.stage_timeline.entries.8.name', 'repair_loop')
            ->assertJsonPath('snapshot.stage_timeline.entries.8.status', 'skipped_not_needed')
            ->assertJsonPath('snapshot.stage_timeline.entries.9.name', 'evidence_ledger')
            ->assertJsonPath('snapshot.stage_timeline.entries.9.phase', 'evidence')
            ->assertJsonPath('snapshot.evidence_pack.schema_version', 'atlas.code.forge_live_execution.evidence_pack.v1')
            ->assertJsonPath('snapshot.evidence_pack.status', 'passed')
            ->assertJsonPath('snapshot.evidence_pack.replay.external_provider_call', false)
            ->assertJsonPath('snapshot.evidence_pack.stage_receipt_count', 2)
            ->assertJsonPath('snapshot.evidence_pack.stage_receipts.0.stage', 'patch')
            ->assertJsonPath('snapshot.evidence_pack.stage_receipts.1.stage', 'test')
            ->assertJsonPath('snapshot.evidence_pack.ledger_event_count', 4)
            ->assertJsonPath('snapshot.evidence_pack.changed_files.0', 'forge-live-execution-fixture.txt')
            ->assertJsonPath('snapshot.evidence_ref_count', 2)
            ->assertJsonPath('snapshot.ledger_event_count', 4)
            ->assertJsonPath('persistence.engineering_run_persisted', true)
            ->assertJsonPath('persistence.engineering_evidence_persisted', true);

        $pack = $response->json('snapshot.evidence_pack');
        $this->assertIsArray($pack);
        $this->assertNotEmpty(data_get($pack, 'integrity.report_hash'));
        $this->assertNotEmpty(data_get($pack, 'integrity.stage_timeline_hash'));
        $this->assertNotEmpty(data_get($pack, 'integrity.evidence_pack_hash'));
        $this->assertNotEmpty(data_get($pack, 'persistence.engineering_run_id'));
        $this->assertNotEmpty(data_get($pack, 'persistence.engineering_evidence_id'));
        $this->assertCount(2, data_get($pack, 'stage_receipts'));
        $this->assertCount(4, data_get($pack, 'ledger_events'));

        $state = $this->withHeaders($this->headers())->getJson("/atlas-code/works/{$projectId}/state");
        $state
            ->assertOk()
            ->assertJsonPath('forge_live_execution.status', 'passed')
            ->assertJsonPath('forge_live_execution.obra_id', $projectId)
            ->assertJsonPath('forge_live_execution.context_pack.context_completeness', 'canonical_minimum')
            ->assertJsonPath('forge_live_execution.context_pack.ranked_refs.0.kind', 'canonical_doc')
            ->assertJsonPath('forge_live_execution.task_contract.status', 'verified')
            ->assertJsonPath('forge_live_execution.diff_scope.status', 'passed')
            ->assertJsonPath('forge_live_execution.diff_scope.completion_gate.status', 'passed')
            ->assertJsonPath('forge_live_execution.stage_timeline.entries.6.name', 'test_run')
            ->assertJsonPath('forge_live_execution.stage_timeline.entries.6.status', 'passed')
            ->assertJsonPath('forge_live_execution.evidence_pack.stage_receipt_count', 2)
            ->assertJsonPath('forge_live_execution.evidence_pack.ledger_event_count', 4)
            ->assertJsonPath('forge_live_execution_history.schema_version', 'atlas.code.forge_live_execution_history.v1')
            ->assertJsonPath('forge_live_execution_history.total', 1)
            ->assertJsonPath('forge_live_execution_history.entries.0.schema_version', 'atlas.code.forge_live_execution.history_entry.v1')
            ->assertJsonPath('forge_live_execution_history.entries.0.status', 'passed')
            ->assertJsonPath('forge_live_execution_history.entries.0.task_contract_status', 'verified')
            ->assertJsonPath('forge_live_execution_history.entries.0.evidence_pack_digest.schema_version', 'atlas.code.forge_live_execution.evidence_pack_digest.v1')
            ->assertJsonPath('forge_live_execution_history.entries.0.evidence_pack_digest.stage_receipt_count', 2)
            ->assertJsonPath('forge_live_execution_history.entries.0.evidence_pack_digest.ledger_event_count', 4)
            ->assertJsonPath('forge_live_execution_history.entries.0.evidence_pack_digest.changed_files.0', 'forge-live-execution-fixture.txt')
            ->assertJsonPath('forge_live_execution_history.entries.0.completion_claim_allowed', true)
            ->assertJsonPath('forge_task_queue.schema_version', 'atlas.code.forge_task_queue.v1')
            ->assertJsonPath('forge_task_queue.source_authority', 'latest_forge_live_execution.task_contract')
            ->assertJsonPath('forge_task_queue.total', 1)
            ->assertJsonPath('forge_task_queue.verified_count', 1)
            ->assertJsonPath('forge_task_queue.blocked_count', 0)
            ->assertJsonPath('forge_task_queue.entries.0.schema_version', 'atlas.code.forge_task_queue_entry.v1')
            ->assertJsonPath('forge_task_queue.entries.0.status', 'verified')
            ->assertJsonPath('forge_task_queue.entries.0.source', 'latest_forge_live_execution.task_contract')
            ->assertJsonPath('forge_task_queue.entries.0.allowed_files.0', 'forge-live-execution-fixture.txt')
            ->assertJsonPath('forge_task_queue.entries.0.completion_claim_allowed', true);

        $historyEntry = $state->json('forge_live_execution_history.entries.0');
        $this->assertNotEmpty(data_get($historyEntry, 'evidence_pack_digest.stage_receipt_ids.0'));
        $this->assertNotEmpty(data_get($historyEntry, 'evidence_pack_digest.ledger_event_ids.0'));
        $this->assertNotEmpty(data_get($historyEntry, 'evidence_pack_digest.evidence_pack_hash'));
        $queueEntry = $state->json('forge_task_queue.entries.0');
        $this->assertNotEmpty(data_get($queueEntry, 'evidence_pack_hash'));
        $this->assertNotEmpty(data_get($queueEntry, 'stage_timeline_hash'));
        $this->assertSame(data_get($historyEntry, 'history_id'), data_get($queueEntry, 'run_history_id'));
    }

    public function test_atlas_code_keeps_forge_live_execution_history_for_work(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge History',
            'description' => 'history',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'manter historico Forge Live',
            'desired_outcome' => 'manter historico Forge Live',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-history']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions")
            ->assertCreated()
            ->assertJsonPath('snapshot.simulate_failure', false);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions", ['simulate_failure' => true])
            ->assertCreated()
            ->assertJsonPath('snapshot.simulate_failure', true)
            ->assertJsonPath('snapshot.repair_loop.triggered', true)
            ->assertJsonPath('snapshot.stage_timeline.degraded', 1)
            ->assertJsonPath('snapshot.stage_timeline.blocking', 1)
            ->assertJsonPath('snapshot.stage_timeline.entries.6.name', 'test_run')
            ->assertJsonPath('snapshot.stage_timeline.entries.6.status', 'degraded')
            ->assertJsonPath('snapshot.stage_timeline.entries.6.blocking', true)
            ->assertJsonPath('snapshot.stage_timeline.entries.8.name', 'repair_loop')
            ->assertJsonPath('snapshot.stage_timeline.entries.8.status', 'passed');

        $state = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state")
            ->assertOk();

        $history = $state->json('forge_live_execution_history');
        $this->assertIsArray($history);
        $this->assertSame('atlas.code.forge_live_execution_history.v1', $history['schema_version']);
        $this->assertSame($projectId, $history['obra_id']);
        $this->assertSame(2, $history['total']);
        $this->assertSame('AtlasProject.metadata.atlas_code_forge_live_execution_history', $history['source_authority']);
        $this->assertCount(2, $history['entries']);

        $latest = $history['entries'][0];
        $this->assertSame('atlas.code.forge_live_execution.history_entry.v1', $latest['schema_version']);
        $this->assertSame('degraded', $latest['status']);
        $this->assertTrue($latest['simulate_failure']);
        $this->assertTrue($latest['repair_triggered']);
        $this->assertFalse($latest['completion_claim_allowed']);
        $this->assertSame('needs_review', $latest['task_contract_status']);
        $this->assertSame('blocked', $latest['diff_scope_status']);
        $this->assertSame('atlas.code.forge_live_execution.evidence_pack_digest.v1', $latest['evidence_pack_digest']['schema_version']);
        $this->assertSame(2, $latest['evidence_pack_digest']['stage_receipt_count']);
        $this->assertSame(4, $latest['evidence_pack_digest']['ledger_event_count']);
        $this->assertNotEmpty($latest['evidence_pack_digest']['stage_receipt_ids'][0]);
        $this->assertNotEmpty($latest['evidence_pack_digest']['ledger_event_ids'][0]);
        $this->assertNotEmpty($latest['evidence_pack_digest']['evidence_pack_hash']);
        $this->assertStringContainsString('--simulate-failure', $latest['command']);
        $this->assertNotEmpty($latest['history_id']);
        $this->assertNotEmpty($latest['run_id']);
        $this->assertNotEmpty($latest['evidence_id']);

        $state
            ->assertJsonPath('forge_task_queue.schema_version', 'atlas.code.forge_task_queue.v1')
            ->assertJsonPath('forge_task_queue.total', 1)
            ->assertJsonPath('forge_task_queue.blocked_count', 1)
            ->assertJsonPath('forge_task_queue.entries.0.status', 'blocked')
            ->assertJsonPath('forge_task_queue.entries.0.completion_claim_allowed', false)
            ->assertJsonPath('forge_task_queue.entries.0.run_history_id', $latest['history_id']);
        $this->assertNotEmpty($state->json('forge_task_queue.entries.0.blockers'));
    }

    public function test_atlas_code_exposes_programming_governance_as_forge_task_queue(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();
        $workItemId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Governance Queue',
            'description' => 'governance',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'renderizar fila Forge por WorkItem',
            'desired_outcome' => 'renderizar fila Forge por WorkItem',
            'priority' => 'medium',
            'metadata' => json_encode([
                'workspace_path' => '/tmp/atlas-code-governance-queue',
                'programming_work_item_id' => $workItemId,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_programming_work_items')->insert([
            'id' => $workItemId,
            'code' => 'FORGE-QUEUE-1',
            'intent_text' => 'implementar a fila visual do Forge',
            'intent_type' => 'feature',
            'scope_mode' => 'structural',
            'risk_level' => 'high',
            'owner' => 'atlas-code',
            'workspace' => '/tmp/atlas-code-governance-queue',
            'status' => 'executing',
            'current_stage' => 'execution',
            'spec_hash' => str_repeat('a', 64),
            'plan_hash' => str_repeat('b', 64),
            'placement_json' => json_encode([]),
            'code_intelligence_json' => json_encode([]),
            'spec_json' => json_encode(['objective' => 'fila real']),
            'plan_json' => json_encode(['task_count' => 1]),
            'tasks_json' => json_encode([[
                'task_id' => 'task-forge-queue',
                'title' => 'Forge Task Queue',
                'objective' => 'mostrar tarefas reais da Obra',
                'owner' => 'atlas-code',
                'risk_level' => 'high',
                'allowed_files' => ['apps/desktop/src/surfaces/code/panels/ForgeTaskQueuePanel.tsx'],
                'forbidden_files' => ['apps/desktop/src/surfaces/cartografia'],
                'expected_files' => ['apps/desktop/src/surfaces/code/panels/ForgeTaskQueuePanel.tsx'],
                'validation_commands' => ['npm run build --workspace=@atlas/desktop'],
                'acceptance_criteria' => ['fila usa dados reais'],
                'evidence_required' => ['test', 'build'],
                'docs_required' => ['docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md'],
            ]]),
            'evidence_refs_json' => json_encode([]),
            'gaps_json' => json_encode([]),
            'metadata_json' => json_encode(['obra_id' => $projectId]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_programming_gate_runs')->insert([
            'id' => (string) Str::uuid(),
            'work_item_id' => $workItemId,
            'gate_name' => 'scope-guard',
            'status' => 'passed',
            'blocking' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state");

        $response
            ->assertOk()
            ->assertJsonPath('programming_governance.schema_version', 'atlas.code.programming_governance_snapshot.v1')
            ->assertJsonPath('programming_governance.work_item.code', 'FORGE-QUEUE-1')
            ->assertJsonPath('programming_governance.tasks.0.task_id', 'task-forge-queue')
            ->assertJsonPath('programming_governance.gate_runs.0.gate_name', 'scope-guard')
            ->assertJsonPath('programming_governance.adaptive_control_plane.schema_version', 'atlas.programming.adaptive_hierarchical_control_plane.v1')
            ->assertJsonPath('programming_governance.adaptive_control_plane.live_session_control_v2.schema_version', 'atlas.programming.ahcl.live_session_control.v2')
            ->assertJsonPath('programming_governance.adaptive_control_plane.forge_multi_agent_control_v3.schema_version', 'atlas.programming.ahcl.forge_multi_agent_control.v3')
            ->assertJsonPath('programming_governance.adaptive_control_plane.predictive_replay_learning_v4.schema_version', 'atlas.programming.ahcl.predictive_replay_learning.v4')
            ->assertJsonPath('programming_governance.adaptive_control_plane.optimization_control_twin_v5.schema_version', 'atlas.programming.ahcl.optimization_control_twin.v5')
            ->assertJsonPath('forge_task_queue.schema_version', 'atlas.code.forge_task_queue.v1')
            ->assertJsonPath('forge_task_queue.source_authority', 'programming_governance.tasks_json')
            ->assertJsonPath('forge_task_queue.total', 1)
            ->assertJsonPath('forge_task_queue.ready_count', 1)
            ->assertJsonPath('forge_task_queue.entries.0.task_id', 'task-forge-queue')
            ->assertJsonPath('forge_task_queue.entries.0.title', 'Forge Task Queue')
            ->assertJsonPath('forge_task_queue.entries.0.status', 'ready')
            ->assertJsonPath('forge_task_queue.entries.0.allowed_files.0', 'apps/desktop/src/surfaces/code/panels/ForgeTaskQueuePanel.tsx')
            ->assertJsonPath('forge_task_queue.entries.0.source', 'programming_governance.tasks_json');
    }

    public function test_atlas_code_can_create_and_bind_programming_work_item_for_obra(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Intake',
            'description' => 'programar intake governado pela Obra',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'implementar intake governado do Forge',
            'desired_outcome' => 'implementar intake governado do Forge',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-forge-intake']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/programming/work-items", [
                'intent' => 'implementar feature estrutural do Forge com fila e evidence',
                'mode' => 'structural',
                'risk' => 'high',
                'owner' => 'atlas-code',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.code.programming_work_item_binding_response.v1')
            ->assertJsonPath('work_id', $projectId)
            ->assertJsonPath('created', true)
            ->assertJsonPath('status', 'bound')
            ->assertJsonPath('binding.surface_id', 'atlas_code')
            ->assertJsonPath('binding.flow_id', 'programming.forge')
            ->assertJsonPath('work_item.scope_mode', 'structural')
            ->assertJsonPath('work_item.risk_level', 'high')
            ->assertJsonPath('work_item.workspace', '/tmp/atlas-code-forge-intake')
            ->assertJsonPath('work_item.metadata.obra_id', $projectId)
            ->assertJsonPath('programming_governance.work_item.metadata.obra_id', $projectId);

        $workItemId = (string) $response->json('work_item.id');
        $workItemCode = (string) $response->json('work_item.code');
        $this->assertNotEmpty($workItemId);
        $this->assertNotEmpty($workItemCode);

        $this->assertDatabaseHas('atlas_projects', [
            'id' => $projectId,
        ]);
        $this->assertDatabaseHas('atlas_programming_work_items', [
            'id' => $workItemId,
            'workspace' => '/tmp/atlas-code-forge-intake',
            'status' => 'spec_required',
        ]);

        $state = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state");

        $state
            ->assertOk()
            ->assertJsonPath('programming_governance.schema_version', 'atlas.code.programming_governance_snapshot.v1')
            ->assertJsonPath('programming_governance.work_item.id', $workItemId)
            ->assertJsonPath('programming_governance.work_item.code', $workItemCode)
            ->assertJsonPath('forge_task_queue.schema_version', 'atlas.code.forge_task_queue.v1')
            ->assertJsonPath('forge_task_queue.work_item_id', $workItemId)
            ->assertJsonPath('forge_task_queue.work_item_code', $workItemCode)
            ->assertJsonPath('forge_task_queue.requires_plan', true)
            ->assertJsonPath('forge_task_queue.total', 0);

        $again = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/programming/work-items", [
                'intent' => 'nao duplicar work item',
            ]);

        $again
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('work_item.id', $workItemId)
            ->assertJsonPath('binding.work_item_code', $workItemCode);

        $boundCount = DB::table('atlas_programming_work_items')
            ->get()
            ->filter(fn (object $row): bool => data_get(json_decode((string) $row->metadata_json, true), 'obra_id') === $projectId)
            ->count();
        $this->assertSame(1, $boundCount);
    }

    public function test_atlas_code_can_compile_spec_plan_and_queue_from_bound_work_item(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();
        $workspace = sys_get_temp_dir().'/atlas-code-forge-spec-plan-'.Str::lower(Str::random(8));
        $workspaceFile = $workspace.'/app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php';
        mkdir(dirname($workspaceFile), 0777, true);
        file_put_contents($workspaceFile, "<?php\n\nfinal class AtlasCodeProgrammingWorkItemControllerFixture {}\n");

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Spec Plan',
            'description' => 'programar spec plan governado',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'transformar Obra em spec plan tasks do Forge',
            'desired_outcome' => 'transformar Obra em spec plan tasks do Forge',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => $workspace]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $binding = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/programming/work-items", [
                'intent' => 'implementar spec plan governado do Forge para Atlas Code',
                'mode' => 'structural',
                'risk' => 'high',
                'owner' => 'atlas-code',
            ])
            ->assertCreated();

        $workItemId = (string) $binding->json('work_item.id');
        $workItemCode = (string) $binding->json('work_item.code');

        $compiled = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/programming/work-items/{$workItemId}/spec", [
                'likely_files' => [
                    'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php',
                    'tests/Feature/AtlasCodeContractTest.php',
                    'docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md',
                ],
                'validation_commands' => ['php -r "echo \'atlas-governed-ok\';"'],
                'acceptance_criteria' => ['Atlas Code exposes Obra WorkItem Spec Plan Tasks queue'],
                'evidence_required' => ['phpunit_green', 'docs_health_ok'],
            ]);

        $compiled
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.code.programming_work_item_spec_binding_response.v1')
            ->assertJsonPath('work_id', $projectId)
            ->assertJsonPath('status', 'planned')
            ->assertJsonPath('compiled', true)
            ->assertJsonPath('work_item_id', $workItemId)
            ->assertJsonPath('work_item_code', $workItemCode)
            ->assertJsonPath('blockers', [])
            ->assertJsonPath('next_action', 'run_forge_live_execution')
            ->assertJsonPath('context_pack.schema_version', 'atlas.sdd_context_pack.v1')
            ->assertJsonPath('programming_governance.work_item.id', $workItemId)
            ->assertJsonPath('programming_governance.work_item.status', 'executing');

        $specHash = (string) $compiled->json('spec_hash');
        $planHash = (string) $compiled->json('plan_hash');
        $tasksCount = (int) $compiled->json('tasks_count');
        $this->assertSame(64, strlen($specHash));
        $this->assertSame(64, strlen($planHash));
        $this->assertGreaterThanOrEqual(2, $tasksCount);

        $state = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state");

        $state
            ->assertOk()
            ->assertJsonPath('programming_governance.work_item.id', $workItemId)
            ->assertJsonPath('programming_governance.work_item.spec_hash', $specHash)
            ->assertJsonPath('programming_governance.work_item.plan_hash', $planHash)
            ->assertJsonPath('forge_task_queue.source_authority', 'programming_governance.tasks_json')
            ->assertJsonPath('forge_task_queue.requires_plan', false)
            ->assertJsonPath('forge_task_queue.work_item_id', $workItemId)
            ->assertJsonPath('forge_task_queue.work_item_code', $workItemCode)
            ->assertJsonPath('forge_task_queue.entries.0.source', 'programming_governance.tasks_json')
            ->assertJsonPath('forge_task_queue.entries.0.allowed_files.0', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php');

        $run = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions");

        $run
            ->assertCreated()
            ->assertJsonPath('snapshot.execution_source', 'programming_governance.tasks_json')
            ->assertJsonPath('snapshot.status', 'passed')
            ->assertJsonPath('snapshot.governed_execution.schema_version', 'atlas.forge_governed_execution.v1')
            ->assertJsonPath('snapshot.governed_execution.status', 'passed')
            ->assertJsonPath('snapshot.governed_execution.execution_mode', 'governed_shadow_patch')
            ->assertJsonPath('snapshot.governed_execution.changed_files.0', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php')
            ->assertJsonPath('snapshot.governed_execution.live_workspace_mutated', false)
            ->assertJsonPath('snapshot.governed_execution.promotion_status', 'requires_human_approval')
            ->assertJsonPath('snapshot.governed_execution.promotion_artifact.schema_version', 'atlas.forge_governed_execution.patch_artifact.v1')
            ->assertJsonPath('snapshot.governed_execution.promotion_artifact.operation', 'append_line')
            ->assertJsonPath('snapshot.governed_execution.promotion_artifact.target_file', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php')
            ->assertJsonPath('snapshot.governed_execution.validation_result.passed', true)
            ->assertJsonPath('snapshot.task_contract.source_authority', 'programming_governance.tasks_json')
            ->assertJsonPath('snapshot.task_contract.work_item_id', $workItemId)
            ->assertJsonPath('snapshot.task_contract.plan_hash', $planHash)
            ->assertJsonPath('snapshot.task_contract.allowed_files.0', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php')
            ->assertJsonPath('snapshot.diff_scope.source_authority', 'atlas.forge_governed_execution.v1')
            ->assertJsonPath('snapshot.diff_scope.status', 'passed')
            ->assertJsonPath('snapshot.diff_scope.files.0.path', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php')
            ->assertJsonPath('snapshot.diff_scope.files.0.status', 'in_scope')
            ->assertJsonPath('snapshot.diff_scope.completion_gate.completion_claim_allowed', true)
            ->assertJsonPath('snapshot.programming_governance_feedback.status', 'synced')
            ->assertJsonPath('snapshot.programming_governance_feedback.evidence_appended', true)
            ->assertJsonPath('snapshot.programming_governance_feedback.gate_summary.gates_evaluated.0', 'evidence-required')
            ->assertJsonPath('snapshot.programming_governance_feedback.gate_summary.gates_evaluated.1', 'scope-guard')
            ->assertJsonPath('snapshot.programming_governance_feedback.gate_summary.all_green', true);

        $afterRun = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state");

        $afterRun
            ->assertOk()
            ->assertJsonPath('programming_governance.evidence_refs.0.evidence_type', 'forge_live_execution')
            ->assertJsonPath('programming_governance.evidence_refs.0.storage.persisted', true)
            ->assertJsonPath('programming_governance.evidence_refs.0.files.0', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php')
            ->assertJsonPath('programming_governance.evidence_refs.0.execution_mode', 'governed_shadow_patch')
            ->assertJsonPath('programming_governance.gate_runs.0.gate_name', 'evidence-required')
            ->assertJsonPath('programming_governance.gate_runs.0.status', 'passed')
            ->assertJsonPath('programming_governance.gate_runs.1.gate_name', 'scope-guard')
            ->assertJsonPath('programming_governance.gate_runs.1.status', 'passed');

        $this->assertStringNotContainsString(
            'atlas-forge-governed-execution:',
            (string) file_get_contents($workspaceFile),
            'Governed execution must not mutate the live workspace before human promotion.',
        );

        $historyId = (string) $afterRun->json('forge_live_execution_history.entries.0.history_id');
        $promotionReview = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/reviews", [
                'decision' => 'approved',
                'history_id' => $historyId,
                'comment' => 'promover patch governado',
            ]);

        $promotionReview
            ->assertCreated()
            ->assertJsonPath('review.status', 'approved')
            ->assertJsonPath('review.approval_effective', true)
            ->assertJsonPath('review.review_gate.final_completion_allowed', true)
            ->assertJsonPath('review.promotion.schema_version', 'atlas.forge_governed_promotion.v1')
            ->assertJsonPath('review.promotion.status', 'promoted')
            ->assertJsonPath('review.promotion.promotion_status', 'promoted_to_workspace')
            ->assertJsonPath('review.promotion.live_workspace_mutated', true)
            ->assertJsonPath('review.promotion.changed_files.0', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php')
            ->assertJsonPath('review.promotion.rollback.available', true)
            ->assertJsonPath('review.promotion.evidence.persisted', true);

        $promotionId = (string) $promotionReview->json('review.promotion.promotion_id');
        $this->assertNotEmpty($promotionId);

        $this->assertStringContainsString(
            'atlas-forge-governed-execution:',
            (string) file_get_contents($workspaceFile),
        );

        $afterPromotion = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state");

        $afterPromotion
            ->assertOk()
            ->assertJsonPath('forge_review.status', 'approved')
            ->assertJsonPath('forge_review.promotion.promotion_status', 'promoted_to_workspace')
            ->assertJsonPath('forge_live_execution.governed_execution.promotion_status', 'promoted_to_workspace')
            ->assertJsonPath('forge_live_execution.governed_execution.live_workspace_mutated', true)
            ->assertJsonPath('forge_live_execution_history.entries.0.promotion_status', 'promoted_to_workspace')
            ->assertJsonPath('forge_live_execution_history.entries.0.live_workspace_mutated', true);

        $promotionEvidence = collect((array) $afterPromotion->json('programming_governance.evidence_refs'))
            ->first(fn (array $receipt): bool => ($receipt['evidence_type'] ?? null) === 'forge_workspace_promotion');
        $this->assertIsArray($promotionEvidence);
        $this->assertSame('governed_workspace_promotion', data_get($promotionEvidence, 'execution_mode'));
        $this->assertSame('app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php', data_get($promotionEvidence, 'files.0'));

        $rollback = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/promotions/{$promotionId}/rollback", [
                'comment' => 'rollback auditavel',
            ]);

        $rollback
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.code.forge_promotion_rollback_response.v1')
            ->assertJsonPath('status', 'rolled_back')
            ->assertJsonPath('rollback.schema_version', 'atlas.forge_governed_rollback.v1')
            ->assertJsonPath('rollback.status', 'rolled_back')
            ->assertJsonPath('rollback.promotion_status', 'rolled_back')
            ->assertJsonPath('rollback.live_workspace_mutated', true)
            ->assertJsonPath('rollback.changed_files.0', 'app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php')
            ->assertJsonPath('rollback.evidence.persisted', true)
            ->assertJsonPath('review.status', 'rolled_back')
            ->assertJsonPath('review.review_gate.final_completion_allowed', false)
            ->assertJsonPath('review.review_gate.blockers.0', 'promotion_rolled_back');

        $this->assertStringNotContainsString(
            'atlas-forge-governed-execution:',
            (string) file_get_contents($workspaceFile),
        );

        $afterRollback = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state");

        $afterRollback
            ->assertOk()
            ->assertJsonPath('forge_review.status', 'rolled_back')
            ->assertJsonPath('forge_review.promotion.promotion_status', 'rolled_back')
            ->assertJsonPath('forge_live_execution.governed_execution.promotion_status', 'rolled_back')
            ->assertJsonPath('forge_live_execution.governed_execution.live_workspace_mutated', false)
            ->assertJsonPath('forge_live_execution_history.entries.0.promotion_status', 'rolled_back')
            ->assertJsonPath('forge_live_execution_history.entries.0.live_workspace_mutated', false);

        $rollbackEvidence = collect((array) $afterRollback->json('programming_governance.evidence_refs'))
            ->first(fn (array $receipt): bool => ($receipt['evidence_type'] ?? null) === 'forge_workspace_rollback');
        $this->assertIsArray($rollbackEvidence);
        $this->assertSame('governed_workspace_rollback', data_get($rollbackEvidence, 'execution_mode'));
        $this->assertSame('app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php', data_get($rollbackEvidence, 'files.0'));

        $again = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/programming/work-items/{$workItemId}/spec", [
                'likely_files' => ['app/ShouldNotDuplicate.php'],
                'validation_commands' => ['phpunit'],
            ]);

        $again
            ->assertOk()
            ->assertJsonPath('status', 'already_planned')
            ->assertJsonPath('compiled', false)
            ->assertJsonPath('spec_hash', $specHash)
            ->assertJsonPath('plan_hash', $planHash)
            ->assertJsonPath('tasks_count', $tasksCount);

        $this->assertDatabaseHas('atlas_programming_work_items', [
            'id' => $workItemId,
            'status' => 'verifying',
            'current_stage' => 'evidence',
            'spec_hash' => $specHash,
            'plan_hash' => $planHash,
        ]);
    }

    public function test_atlas_code_enterprise_certification_proves_full_product_loop(): void
    {
        $exitCode = Artisan::call('atlas:code:enterprise-certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report);
        $this->assertSame(0, $exitCode, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('atlas.code.enterprise_certification.v1', $report['schema_version'] ?? null);
        $this->assertSame('passed', $report['atlas_code_enterprise_status'] ?? null);
        $this->assertSame([], $report['remaining_blockers'] ?? null);
        $this->assertFalse((bool) ($report['external_provider_call'] ?? true));
        $this->assertSame(12, data_get($report, 'stage_summary.total'));
        $this->assertSame(12, data_get($report, 'stage_summary.passed'));
        $this->assertSame(0, data_get($report, 'stage_summary.blocked'));

        $stages = collect((array) ($report['stages'] ?? []))->keyBy('name');
        $this->assertSame('programming.forge', data_get($stages->get('work_item_binding'), 'binding_flow_id'));
        $this->assertSame('governed_shadow_patch', data_get($stages->get('forge_live_execution_governed'), 'execution_mode'));
        $this->assertFalse((bool) data_get($stages->get('forge_live_execution_governed'), 'live_workspace_mutated', true));
        $this->assertTrue((bool) data_get($stages->get('state_history_after_run'), 'shadow_did_not_mutate_live_workspace'));
        $this->assertTrue((bool) data_get($stages->get('history_replay_read_only'), 'read_only'));
        $this->assertFalse((bool) data_get($stages->get('history_replay_read_only'), 'external_provider_call', true));
        $this->assertSame('promoted_to_workspace', data_get($stages->get('human_review_promotion'), 'promotion_status'));
        $this->assertTrue((bool) data_get($stages->get('governed_rollback'), 'restored_initial_hash'));
        $this->assertTrue((bool) data_get($stages->get('final_state_read_model'), 'rollback_evidence_present'));
        $this->assertSame('passed', data_get($stages->get('workspace_cleanup'), 'status'));
    }

    public function test_atlas_code_enterprise_certification_api_exposes_product_proof_packet(): void
    {
        $response = $this->postJson('/atlas-code/certification', [
            'keep_workspace' => false,
        ], $this->headers());

        $response
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.code.enterprise_certification.v1')
            ->assertJsonPath('atlas_code_enterprise_status', 'passed')
            ->assertJsonPath('stage_summary.blocked', 0)
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('inputs.requires_obra', true)
            ->assertJsonPath('commands.self', 'php artisan atlas:code:enterprise-certify --json --strict');

        $payload = $response->json();
        $stages = collect((array) ($payload['stages'] ?? []))->keyBy('name');
        $obraId = (string) data_get($payload, 'inputs.obra_id');

        $this->assertSame([], $payload['remaining_blockers'] ?? null);
        $this->assertGreaterThanOrEqual(7, count((array) ($payload['prompt_to_artifact_checklist'] ?? [])));
        $this->assertSame('passed', data_get($stages->get('workspace_cleanup'), 'status'));

        $this->getJson("/atlas-code/works/{$obraId}/state", $this->headers())
            ->assertOk()
            ->assertJsonPath('atlas_code_enterprise_certification.schema_version', 'atlas.code.enterprise_certification.v1')
            ->assertJsonPath('atlas_code_enterprise_certification.atlas_code_enterprise_status', 'passed')
            ->assertJsonPath('atlas_code_enterprise_certification.stage_summary.blocked', 0);

        $this->getJson('/atlas-code/certification', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.code.enterprise_certification.v1')
            ->assertJsonPath('certification_id', (string) data_get($payload, 'certification_id'))
            ->assertJsonPath('atlas_code_enterprise_status', 'passed');

        $index = $this->getJson('/atlas-code/works', $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.system_certification_hidden', true)
            ->json('data');

        $this->assertNotContains($obraId, collect($index)->pluck('id')->all());
    }

    public function test_atlas_code_blocks_spec_plan_for_unbound_work_item(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();
        $otherProjectId = (string) Str::uuid();
        $workItemId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Spec Plan Blocked',
            'description' => 'blocked',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'nao aceitar work item de outra obra',
            'desired_outcome' => 'nao aceitar work item de outra obra',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-forge-spec-plan-blocked']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_programming_work_items')->insert([
            'id' => $workItemId,
            'code' => 'FORGE-OTHER-1',
            'intent_text' => 'work item de outra Obra',
            'intent_type' => 'feature',
            'scope_mode' => 'structural',
            'risk_level' => 'high',
            'owner' => 'atlas-code',
            'workspace' => '/tmp/another-workspace',
            'status' => 'spec_required',
            'current_stage' => 'intake',
            'placement_json' => json_encode([]),
            'code_intelligence_json' => json_encode([]),
            'spec_json' => json_encode([]),
            'plan_json' => json_encode([]),
            'tasks_json' => json_encode([]),
            'evidence_refs_json' => json_encode([]),
            'gaps_json' => json_encode([]),
            'metadata_json' => json_encode(['obra_id' => $otherProjectId]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/programming/work-items/{$workItemId}/spec", [
                'likely_files' => ['app/Foo.php'],
                'validation_commands' => ['phpunit'],
            ])
            ->assertForbidden()
            ->assertJsonPath('schema_version', 'atlas.code.programming_work_item_spec_binding_response.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('blockers.0', 'work_item_not_bound_to_obra')
            ->assertJsonPath('next_action', 'fix_spec_context_then_retry');
    }

    public function test_atlas_code_can_approve_forge_run_with_human_review(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Review',
            'description' => 'review',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'aprovar run Forge',
            'desired_outcome' => 'aprovar run Forge',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-review']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions")
            ->assertCreated()
            ->assertJsonPath('snapshot.status', 'passed');

        $history = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state")
            ->assertOk()
            ->json('forge_live_execution_history.entries.0');

        $review = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/reviews", [
                'decision' => 'approved',
                'history_id' => $history['history_id'],
                'comment' => 'aceito para produto',
            ]);

        $review
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.code.forge_review_response.v1')
            ->assertJsonPath('work_id', $projectId)
            ->assertJsonPath('review.schema_version', 'atlas.code.forge_review_artifact.v1')
            ->assertJsonPath('review.status', 'approved')
            ->assertJsonPath('review.approval_effective', true)
            ->assertJsonPath('review.review_gate.final_completion_allowed', true)
            ->assertJsonPath('review.history_id', $history['history_id'])
            ->assertJsonPath('persistence.engineering_evidence_persisted', true);

        $state = $this->withHeaders($this->headers())->getJson("/atlas-code/works/{$projectId}/state");
        $state
            ->assertOk()
            ->assertJsonPath('forge_review.status', 'approved')
            ->assertJsonPath('forge_review.review_gate.final_completion_allowed', true)
            ->assertJsonPath('forge_review_history.schema_version', 'atlas.code.forge_review_history.v1')
            ->assertJsonPath('forge_review_history.total', 1)
            ->assertJsonPath('forge_review_history.entries.0.schema_version', 'atlas.code.forge_review_history_entry.v1')
            ->assertJsonPath('forge_review_history.entries.0.status', 'approved')
            ->assertJsonPath('forge_review_history.entries.0.decision', 'approved')
            ->assertJsonPath('forge_review_history.entries.0.history_id', $history['history_id'])
            ->assertJsonPath('forge_review_history.entries.0.final_completion_allowed', true)
            ->assertJsonPath('forge_review_history.entries.0.live_execution_status', 'passed')
            ->assertJsonPath('forge_review_history.entries.0.stage_receipt_count', 2)
            ->assertJsonPath('forge_review_history.entries.0.ledger_event_count', 4);

        $reviewHistoryEntry = $state->json('forge_review_history.entries.0');
        $this->assertNotEmpty($reviewHistoryEntry['review_id']);
        $this->assertNotEmpty($reviewHistoryEntry['evidence_pack_hash']);
        $this->assertNotEmpty($reviewHistoryEntry['stage_timeline_hash']);
        $this->assertNotEmpty($reviewHistoryEntry['report_hash']);
    }

    public function test_atlas_code_can_reject_forge_run_with_human_review(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Review Rejected',
            'description' => 'review rejected',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'rejeitar run Forge',
            'desired_outcome' => 'rejeitar run Forge',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-review-rejected']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions")
            ->assertCreated()
            ->assertJsonPath('snapshot.status', 'passed');

        $history = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state")
            ->assertOk()
            ->json('forge_live_execution_history.entries.0');

        $review = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/reviews", [
                'decision' => 'rejected',
                'history_id' => $history['history_id'],
                'comment' => 'escopo precisa de ajuste humano',
            ]);

        $review
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.code.forge_review_response.v1')
            ->assertJsonPath('review.status', 'rejected')
            ->assertJsonPath('review.decision', 'rejected')
            ->assertJsonPath('review.comment', 'escopo precisa de ajuste humano')
            ->assertJsonPath('review.approval_effective', false)
            ->assertJsonPath('review.review_gate.completion_claim_allowed', true)
            ->assertJsonPath('review.review_gate.human_approved', false)
            ->assertJsonPath('review.review_gate.final_completion_allowed', false)
            ->assertJsonPath('review.review_gate.blockers', [])
            ->assertJsonPath('persistence.engineering_evidence_persisted', true);

        $state = $this->withHeaders($this->headers())->getJson("/atlas-code/works/{$projectId}/state");
        $state
            ->assertOk()
            ->assertJsonPath('forge_review.status', 'rejected')
            ->assertJsonPath('forge_review.comment', 'escopo precisa de ajuste humano')
            ->assertJsonPath('forge_review.review_gate.final_completion_allowed', false)
            ->assertJsonPath('forge_review_history.schema_version', 'atlas.code.forge_review_history.v1')
            ->assertJsonPath('forge_review_history.total', 1)
            ->assertJsonPath('forge_review_history.entries.0.status', 'rejected')
            ->assertJsonPath('forge_review_history.entries.0.decision', 'rejected')
            ->assertJsonPath('forge_review_history.entries.0.comment', 'escopo precisa de ajuste humano')
            ->assertJsonPath('forge_review_history.entries.0.final_completion_allowed', false)
            ->assertJsonPath('forge_review_history.entries.0.completion_claim_allowed', true)
            ->assertJsonPath('forge_review_history.entries.0.live_execution_status', 'passed');
    }

    public function test_atlas_code_replays_forge_run_history_read_only_with_review_context(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Replay',
            'description' => 'replay',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'replay read model',
            'desired_outcome' => 'replay read model',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-replay']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions")
            ->assertCreated()
            ->assertJsonPath('snapshot.status', 'passed');

        $history = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state")
            ->assertOk()
            ->json('forge_live_execution_history.entries.0');

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/reviews", [
                'decision' => 'approved',
                'history_id' => $history['history_id'],
                'comment' => 'replay aprovado',
            ])
            ->assertCreated();

        $replay = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/forge/live-executions/history/{$history['history_id']}");

        $replay
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.code.forge_live_execution_history_replay.v1')
            ->assertJsonPath('work_id', $projectId)
            ->assertJsonPath('history_id', $history['history_id'])
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('history_entry.history_id', $history['history_id'])
            ->assertJsonPath('history_entry.completion_claim_allowed', true)
            ->assertJsonPath('evidence_pack_digest.schema_version', 'atlas.code.forge_live_execution.evidence_pack_digest.v1')
            ->assertJsonPath('evidence_pack_digest.stage_receipt_count', 2)
            ->assertJsonPath('evidence_pack_digest.ledger_event_count', 4)
            ->assertJsonPath('stage_timeline_digest.schema_version', 'atlas.code.forge_live_execution.stage_timeline_digest.v1')
            ->assertJsonPath('stage_timeline_digest.total', 11)
            ->assertJsonPath('replay.read_only', true)
            ->assertJsonPath('replay.external_provider_call', false)
            ->assertJsonPath('snapshot_available', true)
            ->assertJsonPath('review.status', 'approved')
            ->assertJsonPath('review.review_gate.final_completion_allowed', true);

        $this->assertNotEmpty($replay->json('evidence_pack_digest.evidence_pack_hash'));
        $this->assertNotEmpty($replay->json('stage_timeline_digest.stage_timeline_hash'));

        $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/forge/live-executions/history/missing-history")
            ->assertNotFound()
            ->assertJsonPath('error', 'forge_history_entry_not_found');
    }

    public function test_atlas_code_blocks_forge_run_history_replay_on_obra_mismatch(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Replay Mismatch',
            'description' => 'replay mismatch',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'bloquear replay mismatch',
            'desired_outcome' => 'bloquear replay mismatch',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-replay-mismatch']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions")
            ->assertCreated();

        $project = DB::table('atlas_projects')->where('id', $projectId)->first();
        $metadata = json_decode((string) $project->metadata, true, flags: JSON_THROW_ON_ERROR);
        $historyId = (string) data_get($metadata, 'atlas_code_forge_live_execution_history.0.history_id');
        $metadata['atlas_code_forge_live_execution_history'][0]['obra_id'] = (string) Str::uuid();
        DB::table('atlas_projects')->where('id', $projectId)->update([
            'metadata' => json_encode($metadata),
            'updated_at' => now(),
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/forge/live-executions/history/{$historyId}")
            ->assertStatus(403)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('blocker', 'obra_binding_mismatch');
    }

    public function test_atlas_code_can_start_and_complete_forge_live_execution_async(): void
    {
        Queue::fake();

        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Async',
            'description' => 'async',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'rodar Forge Live async',
            'desired_outcome' => 'rodar Forge Live async',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-async']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions/async");

        $response
            ->assertAccepted()
            ->assertJsonPath('schema_version', 'atlas.code.forge_live_execution_async_response.v1')
            ->assertJsonPath('work_id', $projectId)
            ->assertJsonPath('execution.schema_version', 'atlas.code.forge_live_execution.async.v1')
            ->assertJsonPath('execution.status', 'queued')
            ->assertJsonPath('execution.job_dispatched', true);

        $executionId = (string) $response->json('execution.execution_id');
        $this->assertNotEmpty($executionId);

        Queue::assertPushed(AtlasCodeForgeLiveExecutionJob::class, fn (AtlasCodeForgeLiveExecutionJob $job): bool => $job->projectId === $projectId
            && $job->executionId === $executionId
            && $job->simulateFailure === false);

        $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/forge/live-executions/{$executionId}")
            ->assertOk()
            ->assertJsonPath('execution.status', 'queued')
            ->assertJsonPath('execution.execution_id', $executionId);

        $job = new AtlasCodeForgeLiveExecutionJob($projectId, false, $executionId);
        $job->handle(app(AtlasForgeLiveExecutionService::class), app(AtlasCodeForgeExecutionController::class));

        $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/forge/live-executions/{$executionId}")
            ->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.snapshot_status', 'passed')
            ->assertJsonPath('execution.completion_claim_allowed', true)
            ->assertJsonPath('snapshot.status', 'passed')
            ->assertJsonPath('snapshot.stage_timeline.total', 11);

        $state = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$projectId}/state")
            ->assertOk();

        $state
            ->assertJsonPath('forge_live_execution_async.execution_id', $executionId)
            ->assertJsonPath('forge_live_execution_async.status', 'completed')
            ->assertJsonPath('forge_live_execution_async.snapshot_status', 'passed')
            ->assertJsonPath('forge_live_execution_async.completion_claim_allowed', true)
            ->assertJsonPath('forge_live_execution.status', 'passed')
            ->assertJsonPath('forge_live_execution_history.total', 1);
    }

    public function test_atlas_code_blocks_human_approval_for_degraded_forge_run(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Forge Review Blocked',
            'description' => 'review blocked',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'bloquear approve degradado',
            'desired_outcome' => 'bloquear approve degradado',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-code-review-blocked']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions", ['simulate_failure' => true])
            ->assertCreated()
            ->assertJsonPath('snapshot.status', 'degraded');

        $review = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/reviews", [
                'decision' => 'approved',
                'comment' => 'tentativa indevida',
            ]);

        $review
            ->assertStatus(409)
            ->assertJsonPath('review.status', 'blocked')
            ->assertJsonPath('review.approval_effective', false)
            ->assertJsonPath('review.review_gate.final_completion_allowed', false)
            ->assertJsonPath('review.review_gate.blockers.0', 'forge_run_not_passed');
    }

    public function test_atlas_code_can_create_checkpoint_for_work_resume(): void
    {
        $now = now();
        $projectId = (string) Str::uuid();

        DB::table('atlas_projects')->insert([
            'id' => $projectId,
            'title' => 'OBRA Checkpoint',
            'description' => 'long session',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'retomar trabalho pesado',
            'desired_outcome' => 'retomar trabalho pesado',
            'priority' => 'medium',
            'metadata' => json_encode(['workspace_path' => '/tmp/atlas-checkpoint']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/forge/live-executions")
            ->assertCreated()
            ->assertJsonPath('snapshot.status', 'passed');

        $checkpoint = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$projectId}/checkpoints", ['reason' => 'handoff']);

        $checkpoint
            ->assertCreated()
            ->assertJsonPath('schema_version', 'atlas.code.checkpoint_response.v1')
            ->assertJsonPath('work_id', $projectId)
            ->assertJsonPath('checkpoint.schema_version', 'atlas.code.checkpoint_artifact.v1')
            ->assertJsonPath('checkpoint.obra_id', $projectId)
            ->assertJsonPath('checkpoint.reason', 'handoff')
            ->assertJsonPath('checkpoint.status', 'ready')
            ->assertJsonPath('checkpoint.resume.resume_ready', true)
            ->assertJsonPath('checkpoint.resume.next_safe_action', 'resume_from_verified_forge_live_execution')
            ->assertJsonPath('checkpoint.forge_live_execution.status', 'passed')
            ->assertJsonPath('checkpoint.forge_live_execution.context_completeness', 'canonical_minimum')
            ->assertJsonPath('checkpoint.forge_live_execution.completion_claim_allowed', true)
            ->assertJsonPath('checkpoint.risk.residual_risk', 'low')
            ->assertJsonPath('persistence.project_metadata_persisted', true)
            ->assertJsonPath('persistence.engineering_evidence_persisted', true);

        $state = $this->withHeaders($this->headers())->getJson("/atlas-code/works/{$projectId}/state");
        $state
            ->assertOk()
            ->assertJsonPath('checkpoint.schema_version', 'atlas.code.checkpoint_artifact.v1')
            ->assertJsonPath('checkpoint.resume.next_safe_action', 'resume_from_verified_forge_live_execution')
            ->assertJsonPath('checkpoint.forge_live_execution.diff_scope_status', 'passed');
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
