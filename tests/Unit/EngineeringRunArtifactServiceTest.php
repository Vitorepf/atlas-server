<?php

namespace Tests\Unit;

use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringRunArtifactService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EngineeringRunArtifactServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::dropIfExists('atlas_task_events');
        Schema::dropIfExists('atlas_tasks');

        parent::tearDown();
    }

    public function test_completion_artifact_preserves_evidence_and_marks_manual_review_gates(): void
    {
        $task = new AtlasTask(['title' => 'Implementar blueprint']);
        $task->forceFill(['id' => '44444444-4444-4444-8444-444444444444']);

        $artifact = app(EngineeringRunArtifactService::class)->completionArtifact(
            task: $task,
            contract: [
                'goal' => 'Implementar blueprint profissional.',
                'acceptance_criteria' => ['Plan-only mostra blueprint.'],
            ],
            blueprint: [
                'blueprint_id' => 'eng_abc',
                'acceptance_matrix' => [[
                    'id' => 'ac_1',
                    'criterion' => 'Plan-only mostra blueprint.',
                    'verification_method' => 'automated_or_smoke_test',
                    'status' => 'pending',
                    'evidence_required' => true,
                ]],
                'scenario_inventory' => [[
                    'id' => 'happy_path',
                    'name' => 'Fluxo principal',
                ]],
                'review_gates' => [
                    [
                        'id' => 'validation_evidence',
                        'title' => 'Validation evidence',
                        'required' => true,
                        'status' => 'required',
                    ],
                    [
                        'id' => 'deep_code_review',
                        'title' => 'Deep review',
                        'required' => true,
                        'status' => 'required',
                    ],
                ],
            ],
            devPlan: ['plan_id' => 'plan_1'],
            completion: [
                'status' => 'needs_review',
                'changed_files' => ['app/Foo.php'],
                'diff_hash' => 'hash',
                'completion_packet' => [
                    'tests' => [[
                        'command' => 'php artisan test',
                        'ok' => true,
                    ]],
                    'risks' => ['Review humano ainda necessario.'],
                ],
            ],
            runs: [[
                'trace_id' => 'trace-1',
                'exit_code' => 0,
            ]],
        );

        $this->assertSame('plan_1', $artifact['plan_id']);
        $this->assertSame('eng_abc', $artifact['blueprint_id']);
        $this->assertSame('evidence_recorded', data_get($artifact, 'acceptance_checklist.0.status'));
        $this->assertSame('needs_review', collect($artifact['review_gates'])->firstWhere('id', 'deep_code_review')['status']);
        $this->assertSame(['trace-1'], data_get($artifact, 'evidence.trace_ids'));
        $this->assertSame('needs_human_review', data_get($artifact, 'decision.status'));
    }

    public function test_recorded_engineering_evidence_can_satisfy_review_gate(): void
    {
        $task = new AtlasTask([
            'title' => 'Review profundo',
            'metadata' => [
                'engineering_evidence' => [[
                    'id' => 'evidence-1',
                    'evidence_type' => 'deep_code_review',
                    'target_id' => 'deep_code_review',
                    'status' => 'passed',
                    'confidence' => 0.9,
                    'summary' => 'Diff revisado contra o contrato.',
                ]],
            ],
        ]);
        $task->forceFill(['id' => '55555555-5555-4555-8555-555555555555']);

        $artifact = app(EngineeringRunArtifactService::class)->completionArtifact(
            task: $task,
            contract: ['goal' => 'Revisar diff.'],
            blueprint: [
                'blueprint_id' => 'eng_review',
                'acceptance_matrix' => [],
                'scenario_inventory' => [],
                'review_gates' => [[
                    'id' => 'deep_code_review',
                    'title' => 'Deep review',
                    'required' => true,
                    'status' => 'required',
                    'minimum_confidence' => 0.82,
                ]],
            ],
            devPlan: ['plan_id' => 'plan_2'],
            completion: [
                'status' => 'passed',
                'changed_files' => [],
                'completion_packet' => ['tests' => [], 'risks' => []],
            ],
            runs: [],
        );

        $this->assertSame('passed', data_get($artifact, 'review_gates.0.status'));
        $this->assertSame('ready', data_get($artifact, 'decision.status'));
        $this->assertTrue(data_get($artifact, 'decision.auto_complete_allowed'));
    }

    public function test_status_snapshot_recomputes_gates_from_recorded_evidence(): void
    {
        $task = new AtlasTask([
            'title' => 'Validar gates',
            'metadata' => [
                'engineering_evidence' => [
                    [
                        'id' => 'evidence-review',
                        'evidence_type' => 'deep_code_review',
                        'target_id' => 'deep_code_review',
                        'status' => 'passed',
                        'confidence' => 0.91,
                        'summary' => 'Review sem risco residual relevante.',
                    ],
                    [
                        'id' => 'evidence-validation',
                        'evidence_type' => 'validation_evidence',
                        'target_id' => 'validation_evidence',
                        'status' => 'passed',
                        'confidence' => 0.86,
                        'summary' => 'Teste focado executado.',
                    ],
                    [
                        'id' => 'evidence-acceptance',
                        'evidence_type' => 'acceptance',
                        'target_id' => 'ac_1',
                        'status' => 'passed',
                        'confidence' => 0.9,
                        'summary' => 'Criterio ac_1 coberto por teste.',
                    ],
                ],
            ],
        ]);
        $task->forceFill(['id' => '66666666-6666-4666-8666-666666666666']);

        $snapshot = app(EngineeringRunArtifactService::class)->statusSnapshot(
            task: $task,
            contract: [
                'goal' => 'Validar gates com evidencias.',
                'acceptance_criteria' => ['Criterio coberto.'],
            ],
            blueprint: [
                'blueprint_id' => 'eng_snapshot',
                'acceptance_matrix' => [[
                    'id' => 'ac_1',
                    'criterion' => 'Criterio coberto.',
                    'verification_method' => 'automated_or_smoke_test',
                    'status' => 'pending',
                    'evidence_required' => true,
                ]],
                'review_gates' => [
                    [
                        'id' => 'acceptance_criteria',
                        'title' => 'Acceptance criteria coverage',
                        'required' => true,
                        'status' => 'required',
                    ],
                    [
                        'id' => 'deep_code_review',
                        'title' => 'Deep review',
                        'required' => true,
                        'status' => 'required',
                        'minimum_confidence' => 0.82,
                    ],
                    [
                        'id' => 'validation_evidence',
                        'title' => 'Validation evidence',
                        'required' => true,
                        'status' => 'required',
                        'minimum_confidence' => 0.8,
                    ],
                ],
            ],
        );

        $this->assertSame('ready', data_get($snapshot, 'decision.status'));
        $this->assertSame('passed', data_get($snapshot, 'acceptance_checklist.0.status'));
        $this->assertSame('passed', collect($snapshot['review_gates'])->firstWhere('id', 'deep_code_review')['status']);
        $this->assertSame(3, data_get($snapshot, 'evidence_summary.total'));
    }

    public function test_record_evidence_persists_metadata_and_task_event(): void
    {
        $this->createTaskTables();
        $task = AtlasTask::query()->create([
            'title' => 'Registrar QA',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'metadata' => [],
        ]);

        $entry = app(EngineeringRunArtifactService::class)->recordEvidence($task, [
            'evidence_type' => 'manual_qa',
            'target_id' => 'manual_qa',
            'status' => 'passed',
            'confidence' => 0.88,
            'summary' => 'Fluxo visual validado no simulador.',
            'files' => ['app/index.tsx'],
        ]);

        $task->refresh();
        $this->assertSame($entry['id'], data_get($task->metadata, 'latest_engineering_evidence.id'));
        $this->assertSame('manual_qa', data_get($task->metadata, 'engineering_evidence.0.evidence_type'));
        $this->assertDatabaseHas('atlas_task_events', [
            'task_id' => $task->id,
            'event_type' => 'engineering_evidence_recorded',
        ]);
        $this->assertDatabaseHas('atlas_engineering_evidence', [
            'task_id' => $task->id,
            'evidence_type' => 'manual_qa',
            'target_id' => 'manual_qa',
        ]);
    }

    public function test_persist_task_run_records_validation_evidence_from_test_results(): void
    {
        $this->createTaskTables();
        $task = AtlasTask::query()->create([
            'title' => 'Persistir run',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'metadata' => [],
        ]);

        app(EngineeringRunArtifactService::class)->persistTaskRun($task, [
            'generated_at' => now()->toJSON(),
            'plan_id' => 'plan_auto',
            'blueprint_id' => 'eng_auto',
            'status' => 'passed',
            'decision' => ['status' => 'ready'],
            'acceptance_checklist' => [],
            'review_gates' => [],
            'evidence' => [
                'changed_files' => [],
                'tests' => [[
                    'command' => 'php artisan test tests/Feature/EngineeringTaskApiTest.php',
                    'ok' => true,
                ]],
                'trace_ids' => ['77777777-7777-4777-8777-777777777777'],
            ],
            'contract_snapshot' => [],
        ]);

        $task->refresh();
        $this->assertSame('validation_evidence', data_get($task->metadata, 'latest_engineering_evidence.evidence_type'));
        $this->assertSame('passed', data_get($task->metadata, 'latest_engineering_evidence.status'));
        $this->assertDatabaseHas('atlas_engineering_evidence', [
            'task_id' => $task->id,
            'evidence_type' => 'validation_evidence',
            'target_id' => 'validation_evidence',
            'status' => 'passed',
            'source' => 'atlas:cli:dev.validation',
        ]);
        $this->assertDatabaseHas('atlas_task_events', [
            'task_id' => $task->id,
            'event_type' => 'engineering_dev_run_completed',
        ]);
        $this->assertDatabaseHas('atlas_task_events', [
            'task_id' => $task->id,
            'event_type' => 'engineering_evidence_recorded',
        ]);
    }

    private function createTaskTables(): void
    {
        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::dropIfExists('atlas_task_events');
        Schema::dropIfExists('atlas_tasks');

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain')->default('atlas');
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
    }
}
