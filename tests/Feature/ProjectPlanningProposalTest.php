<?php

namespace Tests\Feature;

use App\Models\AtlasProject;
use App\Models\Capture;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectPlanningProposalTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->createTables();
    }

    public function test_capture_project_plan_proposal_requires_acceptance_before_creating_project(): void
    {
        $capture = $this->capture('Preciso estudar investimentos para tomar decisoes melhores.', 'atlas');

        $proposalResponse = $this->postJson("/captures/{$capture->id}/project-plan/propose", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('project_id', null)
            ->assertJsonPath('source_capture_id', $capture->id)
            ->assertJsonPath('project_type', 'study')
            ->assertJsonPath('first_milestone', 'Definir pergunta de estudo')
            ->assertJsonPath('first_next_action', 'Definir a primeira pergunta de estudo e estudar por 25 minutos');

        $proposalId = $proposalResponse->json('id');
        $this->assertNotEmpty($proposalId);
        $this->assertDatabaseCount('atlas_projects', 0);
        $this->assertDatabaseHas('atlas_project_plan_proposals', [
            'id' => $proposalId,
            'status' => 'pending_review',
            'source_capture_id' => $capture->id,
        ]);

        $acceptResponse = $this->postJson("/project-plan-proposals/{$proposalId}/accept", [
            'priority' => 'high',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'accepted')
            ->assertJsonPath('project.project_type', 'study')
            ->assertJsonPath('project.priority', 'high')
            ->assertJsonPath('project.steps_count', 5)
            ->assertJsonPath('project.current_step.title', 'Definir pergunta de estudo')
            ->assertJsonPath('active_next_task.project_step.title', 'Definir pergunta de estudo')
            ->assertJsonPath('active_next_task.execution_mode', 'study');

        $projectId = $acceptResponse->json('project.id');
        $taskId = $acceptResponse->json('active_next_task.id');
        $this->assertNotEmpty($projectId);
        $this->assertNotEmpty($taskId);

        $this->assertDatabaseHas('atlas_project_plan_proposals', [
            'id' => $proposalId,
            'status' => 'accepted',
            'project_id' => $projectId,
        ]);
        $this->assertDatabaseHas('atlas_projects', [
            'id' => $projectId,
            'source_capture_id' => $capture->id,
            'active_next_task_id' => $taskId,
            'metadata->plan_proposal_id' => $proposalId,
        ]);
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $taskId,
            'project_id' => $projectId,
            'source_capture_id' => $capture->id,
            'metadata->role' => 'active_next_action',
        ]);
        $this->assertDatabaseHas('capture_links', [
            'capture_id' => $capture->id,
            'target_type' => 'project',
            'target_id' => $projectId,
            'relation_type' => 'triage_destination',
        ]);

        $capture->refresh();
        $this->assertSame('project', data_get($capture->metadata, 'triage.destination'));
        $this->assertSame($proposalId, data_get($capture->metadata, 'triage.plan_proposal_id'));
        $this->assertSame($projectId, data_get($capture->metadata, 'triage.target_id'));
    }

    public function test_short_capture_and_technical_capture_generate_distinct_project_plans(): void
    {
        $blackInk = $this->capture('Ideias de cruz para Black Ink.', 'blackink');
        $technical = $this->capture('Criar app Mac para Atlas com captura rapida e sincronizacao.', 'atlas');
        $tedious = $this->capture('Organizar documentos chatos do imposto.', 'atlas');

        $this->postJson("/captures/{$blackInk->id}/project-plan/propose", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('project_type', 'business')
            ->assertJsonPath('priority_suggestion', 'high')
            ->assertJsonPath('first_next_action', 'Escrever a hipótese de valor e a próxima validação')
            ->assertJsonPath('questions.0', 'Qual resultado pequeno faria este projeto valer a pena nos proximos 7 dias?');

        $this->postJson("/captures/{$technical->id}/project-plan/propose", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('project_type', 'technical_build')
            ->assertJsonPath('first_milestone', 'Definir resultado mínimo')
            ->assertJsonPath('estimated_energy', 'high')
            ->assertJsonPath('steps.1.title', 'Mapear stack e restrições');

        $this->postJson("/captures/{$tedious->id}/project-plan/propose", [], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('project_type', 'tedious')
            ->assertJsonPath('avoidance_profile', 'boring')
            ->assertJsonPath('first_milestone', 'Reduzir escopo para 10 minutos')
            ->assertJsonPath('first_next_action', 'Abrir o material e executar 10 minutos sem otimizar')
            ->assertJsonPath('estimated_energy', 'low')
            ->assertJsonPath('questions.2', 'Qual versao de 2 minutos reduz a resistencia para comecar?');
    }

    public function test_project_scoped_proposal_can_be_rejected_and_regenerated(): void
    {
        $project = AtlasProject::query()->create([
            'title' => 'Criar app Mac para Atlas',
            'description' => 'Projeto tecnico de app Mac.',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Ter um app Mac funcional para capturar pensamento.',
            'project_type' => 'technical_build',
            'desired_outcome' => 'App Mac funcional.',
            'minimum_viable_outcome' => 'Criar captura textual.',
            'definition_of_done' => 'Fluxo principal testado.',
            'priority' => 'high',
            'energy_profile' => 'mixed',
            'avoidance_reason' => 'too_large',
            'metadata' => [],
        ]);

        $proposal = $this->postJson("/projects/{$project->id}/plan/propose", [
            'instruction' => 'Reduzir escopo para MVP muito pequeno.',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('project_id', $project->id)
            ->assertJsonPath('project_type', 'technical_build')
            ->json();

        $this->postJson("/projects/{$project->id}/plan/proposals/{$proposal['id']}/reject", [
            'reason' => 'Ainda ficou grande demais.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'rejected')
            ->assertJsonPath('proposal.metadata.rejection_reason', 'Ainda ficou grande demais.');

        $newProposal = $this->postJson("/project-plan-proposals/{$proposal['id']}/regenerate", [
            'instruction' => 'Primeiro plano deve caber em 20 minutos.',
            'estimated_minutes' => 20,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('project_id', $project->id)
            ->assertJsonPath('estimated_duration_minutes', 20)
            ->json();

        $this->assertNotSame($proposal['id'], $newProposal['id']);
        $this->assertDatabaseHas('atlas_project_plan_proposals', [
            'id' => $proposal['id'],
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('atlas_project_plan_proposals', [
            'id' => $newProposal['id'],
            'status' => 'pending_review',
            'project_id' => $project->id,
        ]);
    }

    private function capture(string $text, string $domain): Capture
    {
        return Capture::query()->create([
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => $domain,
            'content_text' => $text,
            'transcription_status' => 'done',
            'captured_at' => now(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => [],
            'pre_capture_digital_context' => [],
        ]);
    }

    private function createTables(): void
    {
        foreach ([
            'audit_events',
            'capture_links',
            'atlas_project_plan_proposals',
            'atlas_project_events',
            'atlas_project_steps',
            'atlas_tasks',
            'atlas_projects',
            'atlas_domains',
            'captures',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->string('content_file_path')->nullable();
            $table->integer('content_duration_ms')->nullable();
            $table->integer('content_size_bytes')->nullable();
            $table->string('content_sha256')->nullable();
            $table->string('content_mime_type')->nullable();
            $table->string('transcription_status')->default('na');
            $table->string('transcription_engine')->nullable();
            $table->text('transcription_error')->nullable();
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->decimal('captured_lat', 10, 7)->nullable();
            $table->decimal('captured_lng', 10, 7)->nullable();
            $table->json('pre_capture_digital_context')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_domains', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('color_light');
            $table->string('color_dark');
            $table->string('default_sensitivity')->default('normal');
            $table->string('external_ai_policy')->default('allow');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(100);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        foreach (['atlas' => 'Atlas', 'blackink' => 'BlackInk', 'saude' => 'Saude'] as $slug => $label) {
            \DB::table('atlas_domains')->insert([
                'slug' => $slug,
                'label' => $label,
                'color_light' => '#173B57',
                'color_dark' => '#173B57',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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

        Schema::create('atlas_project_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('event_type');
            $table->string('source')->default('app');
            $table->json('payload')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_project_plan_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->uuid('source_capture_id')->nullable();
            $table->string('status')->default('pending_review');
            $table->text('proposed_title');
            $table->string('planner_version');
            $table->string('input_hash');
            $table->string('project_type');
            $table->string('avoidance_profile')->default('unknown');
            $table->text('desired_outcome');
            $table->text('definition_of_done');
            $table->text('minimum_useful_result');
            $table->text('first_milestone')->nullable();
            $table->text('first_next_action');
            $table->string('estimated_energy')->default('medium');
            $table->integer('estimated_duration_minutes')->default(25);
            $table->string('priority_suggestion')->default('normal');
            $table->decimal('confidence', 4, 3)->default(0.720);
            $table->json('phases_json')->default('[]');
            $table->json('steps_json')->default('[]');
            $table->json('risks_json')->default('[]');
            $table->json('questions_json')->default('[]');
            $table->text('rationale');
            $table->json('metadata')->default('{}');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('capture_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->string('target_type');
            $table->uuid('target_id')->nullable();
            $table->string('target_title')->nullable();
            $table->string('relation_type')->default('triage_destination');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('actor_type')->default('system');
            $table->string('actor_id')->nullable();
            $table->string('severity')->default('info');
            $table->text('summary');
            $table->json('evidence')->default('{}');
            $table->json('privacy')->default('{}');
            $table->json('refs')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });
    }
}
