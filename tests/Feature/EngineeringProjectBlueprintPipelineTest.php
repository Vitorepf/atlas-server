<?php

namespace Tests\Feature;

use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EngineeringProjectBlueprintPipelineTest extends TestCase
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
        foreach ([
            'atlas_engineering_review_findings',
            'atlas_engineering_runs',
            'atlas_engineering_evidence',
            'atlas_engineering_project_blueprints',
            'atlas_tasks',
            'atlas_project_steps',
            'atlas_projects',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_project_blueprint_freeze_versions_and_generates_tasks_with_contracts(): void
    {
        $project = $this->project();
        AtlasProjectStep::query()->create([
            'project_id' => $project->id,
            'step_order' => 1,
            'title' => 'API e app',
            'status' => 'open',
            'expected_output' => 'Expor API e tela visual com QA.',
            'acceptance_criteria' => "API retorna blueprint\nTela mostra estados loading ready error",
            'metadata' => [
                'likely_files' => ['app/Http/Controllers/EngineeringProjectBlueprintController.php'],
                'allowed_paths' => ['app/', 'tests/'],
            ],
        ]);

        $create = $this->postJson("/projects/{$project->id}/engineering/blueprint/create", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('record.status', 'draft')
            ->assertJsonPath('validation.status', 'passed')
            ->json();

        $hash = (string) data_get($create, 'record.content_hash');
        $this->assertSame(64, strlen($hash));

        $this->postJson("/projects/{$project->id}/engineering/blueprint/freeze", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('record.status', 'frozen')
            ->assertJsonPath('content_hash', $hash);

        $this->postJson("/projects/{$project->id}/engineering/tasks/generate", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('created.0.engineering_contract.refs.project_blueprint_hash', $hash);

        $task = AtlasTask::query()->firstOrFail();
        $this->assertSame('project_blueprint', data_get($task->metadata, 'engineering_contract.source'));
        $this->assertSame($hash, data_get($task->metadata, 'engineering_contract.refs.project_blueprint_hash'));

        $exitCode = Artisan::call('atlas:project:blueprint:validate', [
            '--project-id' => $project->id,
            '--blueprint-version' => 1,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('passed', data_get(json_decode(Artisan::output(), true), 'validation.status'));
    }

    public function test_project_blueprint_freeze_blocks_incomplete_coverage_without_exception(): void
    {
        $project = $this->project([
            'metadata' => [
                'engineering_inventory' => [
                    'screens' => [[
                        'id' => 'screen_bad',
                        'route' => '/bad',
                        'states' => ['ready'],
                        'visual_requirements' => ['responsive'],
                    ]],
                    'api_surfaces' => [[
                        'id' => 'api_bad',
                        'route' => 'GET /bad',
                    ]],
                ],
            ],
        ]);

        $this->postJson("/projects/{$project->id}/engineering/blueprint/create", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('validation.status', 'failed');

        $this->postJson("/projects/{$project->id}/engineering/blueprint/freeze", [], $this->headers)
            ->assertUnprocessable();

        $this->postJson("/projects/{$project->id}/engineering/blueprint/freeze", [
            'exception_reason' => 'Cobertura sera completada manualmente antes do run.',
            'approved_by' => 'test',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('record.status', 'frozen')
            ->assertJsonPath('human_exception.approved_by', 'test');
    }

    public function test_manual_qa_and_postgres_review_record_blocking_evidence(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Revisar migration',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Revisar migration destrutiva.',
                    'likely_files' => ['database/migrations/2026_05_03_drop_bad.php'],
                ],
            ],
        ]);

        $this->postJson("/tasks/{$task->id}/engineering/qa", [
            'status' => 'failed',
            'confidence' => 0.8,
            'steps' => ['abrir tela', 'executar fluxo'],
            'expected_result' => 'Fluxo passa.',
            'actual_result' => 'Fluxo quebra.',
            'risk_notes' => 'Erro visual bloqueante.',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('blocking', true)
            ->assertJsonPath('qa.evidence_type', 'manual_qa');

        $this->postJson("/tasks/{$task->id}/engineering/review/deep", [
            'findings' => [[
                'severity' => 'p1',
                'confidence' => 0.91,
                'category' => 'correctness',
                'title' => 'Bug bloqueante',
            ]],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('blocking', true)
            ->assertJsonPath('summary.blocking_count', 1);
    }

    private function project(array $overrides = []): AtlasProject
    {
        return AtlasProject::query()->create([
            'title' => 'Atlas Engineering Blueprint',
            'description' => 'Implementar pipeline project-level com API, app e gates.',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Blueprint project-level completo.',
            'desired_outcome' => 'Operador congela blueprint e gera tasks.',
            'definition_of_done' => "Blueprint congelado\nTasks geradas\nGates visiveis",
            'priority' => 'high',
            'metadata' => [],
            ...$overrides,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('atlas_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('domain')->default('atlas');
            $table->text('goal')->nullable();
            $table->text('desired_outcome')->nullable();
            $table->text('minimum_viable_outcome')->nullable();
            $table->text('definition_of_done')->nullable();
            $table->string('priority')->default('normal');
            $table->uuid('active_next_task_id')->nullable();
            $table->uuid('current_step_id')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_project_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('active_task_id')->nullable();
            $table->unsignedInteger('step_order')->default(1);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->text('expected_output')->nullable();
            $table->text('acceptance_criteria')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

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
            $table->string('energy_required')->nullable();
            $table->string('execution_mode')->nullable();
            $table->text('starter_step')->nullable();
            $table->text('minimum_viable_action')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_engineering_project_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->string('source')->default('atlas_project_blueprint');
            $table->string('created_by')->default('atlas_ai');
            $table->json('blueprint_json')->default('{}');
            $table->json('validation_json')->default('{}');
            $table->json('human_exception_json')->default('{}');
            $table->string('content_hash', 64);
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'version']);
            $table->unique(['project_id', 'content_hash']);
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

        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->string('workspace_path_hash')->nullable();
            $table->string('workspace_label')->nullable();
            $table->json('provider_strategy_json')->default('{}');
            $table->string('status')->default('passed');
            $table->string('decision')->nullable();
            $table->integer('score')->nullable();
            $table->integer('max_attempts')->default(0);
            $table->integer('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_review_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->uuid('attempt_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->string('source')->default('manual_review');
            $table->string('severity')->default('p2');
            $table->string('status')->default('open');
            $table->decimal('confidence', 5, 3)->nullable();
            $table->string('category')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->json('evidence_json')->default('{}');
            $table->text('recommendation')->nullable();
            $table->json('resolution_json')->default('{}');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
