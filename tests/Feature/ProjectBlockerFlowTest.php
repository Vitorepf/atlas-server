<?php

namespace Tests\Feature;

use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectBlockerFlowTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->createTables();
    }

    public function test_blocked_task_creates_single_open_blocker_and_leaves_original_task_out_of_agenda(): void
    {
        [$project, $step, $task] = $this->projectWithTaskAndStep();

        $this->postJson("/tasks/{$task->id}/complete", [
            'actual_minutes' => 8,
            'completion_quality' => 'blocked',
            'energy_after' => 1,
            'outcome' => 'Grande demais para continuar agora.',
            'blocker' => 'Não sei quebrar a próxima etapa.',
            'blocker_reason_code' => 'too_large',
            'blocker_severity' => 'high',
            'next_hint' => 'Quebrar em uma ação de 10 minutos.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('planning_status', 'deferred')
            ->assertJsonPath('metadata.blocker.reason_code', 'too_large');

        $this->postJson("/tasks/{$task->id}/complete", [
            'completion_quality' => 'blocked',
            'blocker' => 'Continua grande demais.',
            'blocker_reason_code' => 'too_large',
            'next_hint' => 'Quebrar em 10 minutos antes de tentar de novo.',
        ], $this->headers)->assertOk();

        $this->assertDatabaseCount('atlas_project_blockers', 1);
        $this->assertDatabaseHas('atlas_project_blockers', [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'project_step_id' => $step->id,
            'status' => 'open',
            'severity' => 'high',
            'reason_code' => 'too_large',
        ]);
        $this->assertDatabaseHas('atlas_projects', [
            'id' => $project->id,
            'status' => 'blocked',
            'active_next_task_id' => null,
        ]);
        $this->assertSame(2, (int) data_get($this->firstBlockerMetadata(), 'open_count'));

        $this->getJson("/projects/{$project->id}/blockers", $this->headers)
            ->assertOk()
            ->assertJsonPath('summary.open_count', 1)
            ->assertJsonPath('blockers.0.reason_code', 'too_large')
            ->assertJsonPath('blockers.0.task.id', $task->id);

        $this->getJson("/projects/{$project->id}", $this->headers)
            ->assertOk()
            ->assertJsonPath('execution_health.has_open_blockers', true)
            ->assertJsonPath('execution_health.open_blockers_count', 1);

        $agenda = $this->getJson('/tasks/agenda?domain=atlas&energy_level=4&capacity_minutes=90&limit=10', $this->headers)
            ->assertOk()
            ->json('tasks');
        $this->assertNotContains($task->id, array_column($agenda, 'id'));
    }

    public function test_blocker_becomes_unblock_task_and_completion_reopens_original_action_without_completing_step(): void
    {
        [$project, $step, $task] = $this->projectWithTaskAndStep();

        $this->postJson("/tasks/{$task->id}/complete", [
            'actual_minutes' => 5,
            'completion_quality' => 'blocked',
            'outcome' => 'Falta decidir a stack.',
            'blocker_reason_code' => 'decision_needed',
            'next_hint' => 'Listar duas stacks e escolher um teste reversível.',
        ], $this->headers)->assertOk();

        $blockerId = (string) DB::table('atlas_project_blockers')->value('id');
        $unblock = $this->postJson("/projects/{$project->id}/blockers/{$blockerId}/task", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('blocker.unblock_task.status', 'open')
            ->assertJsonPath('unblock_task.metadata.role', 'unblock_action')
            ->assertJsonPath('project.status', 'blocked')
            ->json('unblock_task');

        $unblockTaskId = $unblock['id'];
        $agenda = $this->getJson('/tasks/agenda?domain=atlas&energy_level=2&capacity_minutes=90&limit=10', $this->headers)
            ->assertOk()
            ->json('tasks');
        $agendaUnblockTask = collect($agenda)->firstWhere('id', $unblockTaskId);
        $this->assertNotNull($agendaUnblockTask);
        $this->assertSame('unblock', $agendaUnblockTask['agenda_intent']);
        $this->assertSame('destravamento', $agendaUnblockTask['bucket']);

        $this->postJson("/tasks/{$unblockTaskId}/complete", [
            'completion_quality' => 'complete',
            'actual_minutes' => 9,
            'outcome' => 'Escolhi fazer um teste mínimo com SwiftUI.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $this->assertDatabaseHas('atlas_project_blockers', [
            'id' => $blockerId,
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $task->id,
            'status' => 'open',
            'planning_status' => 'suggested',
            'failure_reason_last' => null,
        ]);
        $this->assertDatabaseHas('atlas_project_steps', [
            'id' => $step->id,
            'status' => 'active',
            'active_task_id' => $task->id,
        ]);
        $this->assertDatabaseHas('atlas_projects', [
            'id' => $project->id,
            'status' => 'active',
            'active_next_task_id' => $task->id,
            'current_step_id' => $step->id,
        ]);
    }

    /**
     * @return array{0: AtlasProject, 1: AtlasProjectStep, 2: AtlasTask}
     */
    private function projectWithTaskAndStep(): array
    {
        $project = AtlasProject::query()->create([
            'title' => 'Criar app Mac para Atlas',
            'description' => 'Projeto técnico com risco de travar.',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Criar um app Mac simples.',
            'next_action' => 'Definir MVP do app',
            'project_type' => 'technical_build',
            'desired_outcome' => 'MVP funcional.',
            'minimum_viable_outcome' => 'Captura textual local.',
            'definition_of_done' => 'Fluxo principal testado.',
            'priority' => 'high',
            'energy_profile' => 'mixed',
            'avoidance_reason' => 'too_large',
            'last_touched_at' => now(),
            'next_review_at' => now()->addDays(3),
            'metadata' => [],
        ]);
        $step = AtlasProjectStep::query()->create([
            'project_id' => $project->id,
            'step_order' => 1,
            'title' => 'Definir MVP',
            'description' => 'Reduzir o escopo inicial.',
            'status' => 'active',
            'step_type' => 'phase',
            'expected_output' => 'MVP escrito em uma frase.',
            'acceptance_criteria' => 'Escopo cabe em uma semana.',
            'estimated_minutes' => 25,
            'energy_required' => 'medium',
            'friction_level' => 55,
            'metadata' => [],
            'started_at' => now(),
        ]);
        $task = AtlasTask::query()->create([
            'title' => 'Definir MVP do app',
            'description' => 'Escrever escopo mínimo.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'project_id' => $project->id,
            'project_step_id' => $step->id,
            'estimated_minutes' => 25,
            'energy_required' => 'medium',
            'urgency_score' => 80,
            'impact_score' => 85,
            'effort_score' => 35,
            'priority_score' => 82,
            'planning_status' => 'suggested',
            'execution_mode' => 'deep_work',
            'friction_level' => 55,
            'emotional_resistance' => 50,
            'clarity_level' => 65,
            'metadata' => ['role' => 'active_next_action'],
        ]);
        $step->forceFill(['active_task_id' => $task->id])->save();
        $project->forceFill([
            'active_next_task_id' => $task->id,
            'current_step_id' => $step->id,
        ])->save();

        return [$project->refresh(), $step->refresh(), $task->refresh()];
    }

    private function firstBlockerMetadata(): array
    {
        $metadata = DB::table('atlas_project_blockers')->value('metadata');

        return is_string($metadata) ? (json_decode($metadata, true) ?: []) : (array) $metadata;
    }

    private function createTables(): void
    {
        foreach ([
            'atlas_project_blockers',
            'atlas_task_events',
            'atlas_project_events',
            'atlas_project_steps',
            'atlas_tasks',
            'atlas_projects',
            'atlas_domains',
            'checkins',
            'health_snapshots',
            'digital_activity_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('atlas_domains', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('color_light')->default('#1B3A57');
            $table->string('color_dark')->default('#6892B5');
            $table->string('default_sensitivity')->default('normal');
            $table->string('external_ai_policy')->default('allow');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(100);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
        DB::table('atlas_domains')->insert([
            'slug' => 'atlas',
            'label' => 'Atlas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('atlas_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('domain');
            $table->uuid('source_capture_id')->nullable();
            $table->text('goal')->nullable();
            $table->text('next_action')->nullable();
            $table->string('project_type')->default('personal');
            $table->text('desired_outcome')->nullable();
            $table->text('minimum_viable_outcome')->nullable();
            $table->text('definition_of_done')->nullable();
            $table->text('why_now')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->string('deadline_kind')->default('none');
            $table->string('priority')->default('normal');
            $table->string('energy_profile')->default('mixed');
            $table->string('avoidance_reason')->default('unknown');
            $table->uuid('active_next_task_id')->nullable();
            $table->uuid('current_step_id')->nullable();
            $table->timestamp('last_touched_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_project_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('active_task_id')->nullable();
            $table->integer('step_order');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('step_type')->default('action');
            $table->text('expected_output')->nullable();
            $table->text('acceptance_criteria')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->string('energy_required')->default('medium');
            $table->smallInteger('friction_level')->default(50);
            $table->json('metadata')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain');
            $table->uuid('source_capture_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('routine_id')->nullable();
            $table->date('routine_occurrence_date')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->date('planned_for_date')->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->string('energy_required')->default('medium');
            $table->smallInteger('urgency_score')->default(50);
            $table->smallInteger('impact_score')->default(50);
            $table->smallInteger('effort_score')->default(50);
            $table->smallInteger('priority_score')->default(50);
            $table->string('planning_status')->default('unscheduled');
            $table->timestamp('completed_at')->nullable();
            $table->string('execution_mode')->default('quick_win');
            $table->smallInteger('friction_level')->default(50);
            $table->smallInteger('emotional_resistance')->default(50);
            $table->smallInteger('clarity_level')->default(60);
            $table->text('starter_step')->nullable();
            $table->text('minimum_viable_action')->nullable();
            $table->text('if_then_plan')->nullable();
            $table->text('reward_hint')->nullable();
            $table->text('failure_reason_last')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('recovery_count')->default(0);
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_project_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('event_type');
            $table->string('source')->default('app');
            $table->json('payload')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
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

        Schema::create('atlas_project_blockers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('task_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('unblock_task_id')->nullable();
            $table->string('status')->default('open');
            $table->string('severity')->default('medium');
            $table->string('reason_code')->default('other');
            $table->text('description');
            $table->text('unblock_next_action')->nullable();
            $table->string('waiting_on')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->uuid('created_from_event_id')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
