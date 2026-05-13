<?php

namespace Tests\Feature;

use App\Models\AtlasTask;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EngineeringTaskApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::dropIfExists('atlas_engineering_blueprints');
        Schema::dropIfExists('atlas_task_events');
        Schema::dropIfExists('atlas_tasks');

        parent::tearDown();
    }

    public function test_task_engineering_endpoint_returns_contract_blueprint_and_run_history(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Implementar blueprint API',
            'description' => 'Expor contrato tecnico para o app.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'estimated_minutes' => 45,
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Expor pacote de engenharia da tarefa.',
                    'acceptance_criteria' => ['Endpoint retorna blueprint e historico.'],
                ],
                'latest_engineering_run' => [
                    'status' => 'needs_review',
                    'plan_id' => 'plan_1',
                ],
                'engineering_run_history' => [
                    ['status' => 'needs_review', 'plan_id' => 'plan_1'],
                ],
            ],
        ]);

        DB::table('atlas_task_events')->insert([
            'id' => '55555555-5555-4555-8555-555555555555',
            'task_id' => $task->id,
            'event_type' => 'engineering_dev_run_completed',
            'source' => 'atlas:cli:dev',
            'payload' => json_encode(['plan_id' => 'plan_1', 'status' => 'needs_review']),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/tasks/{$task->id}/engineering", $this->headers)
            ->assertOk()
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('contract.goal', 'Expor pacote de engenharia da tarefa.')
            ->assertJsonPath('blueprint.acceptance_matrix.0.id', 'ac_1')
            ->assertJsonPath('status_snapshot.decision.status', 'needs_human_review')
            ->assertJsonPath('latest_run.plan_id', 'plan_1')
            ->assertJsonPath('events.0.event_type', 'engineering_dev_run_completed')
            ->assertJsonPath('events.0.safety.schema_version', 'atlas.task_orchestration.event_safety.v1')
            ->assertJsonPath('events.0.safety.provider_dispatch_allowed', false)
            ->assertJsonPath('events.0.safety.runtime_execution_allowed', false);
    }

    public function test_task_engineering_blueprint_can_be_frozen_and_returned(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Congelar blueprint tecnico',
            'description' => 'Blueprint precisa ter snapshot versionado.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'estimated_minutes' => 60,
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Congelar blueprint de engenharia.',
                    'acceptance_criteria' => ['Snapshot versionado fica disponivel na API.'],
                ],
            ],
        ]);

        $response = $this->postJson("/tasks/{$task->id}/engineering/blueprint/freeze", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('blueprint_snapshot.version', 1)
            ->assertJsonPath('blueprint_snapshot.status', 'frozen')
            ->assertJsonPath('blueprint_snapshot.matches_current_content', true);

        $contentHash = (string) data_get($response->json(), 'blueprint_snapshot.content_hash');
        $this->assertSame(64, strlen($contentHash));
        $this->assertDatabaseHas('atlas_engineering_blueprints', [
            'task_id' => $task->id,
            'version' => 1,
            'status' => 'frozen',
            'content_hash' => $contentHash,
        ]);

        $this->getJson("/tasks/{$task->id}/engineering", $this->headers)
            ->assertOk()
            ->assertJsonPath('blueprint_snapshot.version', 1)
            ->assertJsonPath('blueprint_snapshot.matches_current_content', true);
    }

    public function test_task_api_resource_exposes_orchestration_safety_contract(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Publicar safety de task',
            'description' => 'Task API deve deixar claro que read model nao executa runtime.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'estimated_minutes' => 45,
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Expor safety em task.',
                ],
                'latest_engineering_run' => [
                    'status' => 'needs_review',
                ],
            ],
        ]);

        $this->getJson("/tasks/{$task->id}", $this->headers)
            ->assertOk()
            ->assertJsonPath('safety.schema_version', 'atlas.task_orchestration.task_safety.v1')
            ->assertJsonPath('safety.read_model_only', true)
            ->assertJsonPath('safety.provider_dispatch_allowed', false)
            ->assertJsonPath('safety.runtime_execution_allowed', false)
            ->assertJsonPath('safety.policy_mutation_allowed', false)
            ->assertJsonPath('safety.auto_complete_allowed', false)
            ->assertJsonPath('safety.agent_control_plane_allowed', false)
            ->assertJsonPath('safety.operator_review_required_for_external_execution', true)
            ->assertJsonPath('safety.has_engineering_contract', true)
            ->assertJsonPath('safety.has_latest_engineering_run', true)
            ->assertJsonPath('safety.orchestration_contract.schema_version', 'atlas.task_orchestration.intent_contract.v1')
            ->assertJsonPath('safety.orchestration_contract.mode', 'local_task_coordination')
            ->assertJsonPath('safety.orchestration_contract.blocked_external_actions.0', 'provider_dispatch')
            ->assertJsonPath('safety.orchestration_contract.blocked_external_actions.2', 'agent_control_plane_dispatch')
            ->assertJsonPath('safety.orchestration_contract.allowed_local_actions.0', 'plan')
            ->assertJsonPath('safety.orchestration_contract.requires_operator_before.2', 'agent_handoff');
    }

    public function test_task_engineering_evidence_endpoint_records_review_evidence(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Registrar review profundo',
            'description' => 'Evidencia precisa entrar no pacote de engenharia.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'estimated_minutes' => 45,
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Registrar evidencias de engenharia.',
                    'acceptance_criteria' => ['Review profundo fica auditavel.'],
                ],
            ],
        ]);

        $this->postJson("/tasks/{$task->id}/engineering/evidence", [
            'evidence_type' => 'deep_code_review',
            'target_id' => 'deep_code_review',
            'status' => 'passed',
            'confidence' => 0.91,
            'summary' => 'Diff revisado contra contrato, sem escopo extra.',
            'files' => ['app/Services/Engineering/EngineeringRunArtifactService.php'],
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('evidence.evidence_type', 'deep_code_review')
            ->assertJsonPath('evidence.status', 'passed')
            ->assertJsonPath('status_snapshot.review_gates.1.status', 'passed')
            ->assertJsonPath('evidence_history.0.target_id', 'deep_code_review');

        $this->assertDatabaseHas('atlas_task_events', [
            'task_id' => $task->id,
            'event_type' => 'engineering_evidence_recorded',
        ]);
        $this->assertDatabaseHas('atlas_engineering_evidence', [
            'task_id' => $task->id,
            'evidence_type' => 'deep_code_review',
            'target_id' => 'deep_code_review',
            'status' => 'passed',
        ]);

        $this->getJson("/tasks/{$task->id}/engineering", $this->headers)
            ->assertOk()
            ->assertJsonPath('latest_evidence.target_id', 'deep_code_review')
            ->assertJsonPath('evidence_history.0.status', 'passed')
            ->assertJsonPath('events.0.event_type', 'engineering_evidence_recorded');
    }

    private function createTables(): void
    {
        Schema::dropIfExists('atlas_task_events');
        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::dropIfExists('atlas_engineering_blueprints');
        Schema::dropIfExists('atlas_tasks');

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain')->default('atlas');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->text('starter_step')->nullable();
            $table->text('minimum_viable_action')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_task_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->string('event_type');
            $table->string('source')->default('app');
            $table->json('payload')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->string('evidence_type', 80);
            $table->string('target_id', 120)->nullable();
            $table->string('status', 32);
            $table->decimal('confidence', 5, 3)->nullable();
            $table->text('summary');
            $table->string('command', 500)->nullable();
            $table->text('artifact_url')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->json('files')->default('[]');
            $table->json('metadata')->default('{}');
            $table->string('source', 160)->default('tasks.engineering.evidence');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->string('status', 32)->default('frozen');
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 120)->default('atlas_engineering_contract');
            $table->json('contract_json')->default('{}');
            $table->json('blueprint_json')->default('{}');
            $table->string('content_hash', 64);
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'content_hash']);
        });
    }
}
