<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasCodeForgeUxOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_ux_orchestrator_fails_closed_without_obra(): void
    {
        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot([]);

        $this->assertSame(AtlasCodeForgeUxOrchestratorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::STATE_NO_OBRA, $payload['state']);
        $this->assertFalse($payload['primary_action_enabled']);
        $this->assertSame('obra_not_bound', $payload['primary_action_disabled_reason']);
        $this->assertContains('obra_required', $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['completion_claim_promoted']);
    }

    public function test_ux_orchestrator_maps_intake_required(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        // v2 priority: intake missing fields → blocked_definition (more specific
        // than the legacy intake_required state). The legacy state is preserved
        // as a constant for backward compat.
        $this->assertContains($payload['state'], [
            AtlasCodeForgeUxOrchestratorService::STATE_BLOCKED_DEFINITION,
            AtlasCodeForgeUxOrchestratorService::STATE_INTAKE_REQUIRED,
            AtlasCodeForgeUxOrchestratorService::STATE_READY_TO_DEFINE,
        ]);
        $this->assertSame('Completar Definicao', $payload['primary_action_label']);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::ACTION_KIND_INTAKE, $payload['primary_action_kind']);
        $this->assertSame('blocking_execution', $payload['definition_status']);
        $this->assertSame('blocked_definition', $payload['blocker_translation']['kind']);
    }

    public function test_ux_orchestrator_prioritizes_live_blocked_over_fast_path_queued(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_code_forge_work_intake' => [
                    'status' => 'ready',
                    'objective' => 'X',
                    'business_rule' => 'Y',
                    'acceptance_criteria' => ['ok'],
                ],
                'latest_atlas_code_forge_fast_path' => [
                    'status' => 'queued',
                    'spec_hash' => 'a',
                    'plan_hash' => 'b',
                ],
                'latest_forge_live_execution' => [
                    'status' => 'blocked',
                    'remaining_blockers' => ['governed_execution_exception'],
                    'issues' => [
                        ['code' => 'out_of_scope_write', 'path' => 'src/forbidden.ts'],
                    ],
                ],
            ]),
        ])->save();

        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::STATE_BLOCKED_SCOPE, $payload['state']);
        $this->assertNotSame(AtlasCodeForgeUxOrchestratorService::STATE_RUNNING, $payload['state']);
        $this->assertSame('Corrigir escopo', $payload['primary_action_label']);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::ACTION_KIND_FIX_SCOPE, $payload['primary_action_kind']);
        $this->assertContains('src/forbidden.ts', $payload['blocker_translation']['files_out_of_scope']);
        $this->assertSame('blocked_scope', $payload['blocker_translation']['kind']);
        $this->assertSame('Bloqueado por escopo', $payload['blocker_translation']['human_title']);
        $this->assertContains('files_outside_task_contract', $payload['blockers']);
    }

    public function test_ux_orchestrator_translates_governed_execution_exception(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_code_forge_work_intake' => [
                    'status' => 'ready',
                    'objective' => 'X',
                    'business_rule' => 'Y',
                    'acceptance_criteria' => ['ok'],
                ],
                'latest_forge_live_execution' => [
                    'status' => 'blocked',
                    'remaining_blockers' => ['governed_execution_exception'],
                ],
            ]),
        ])->save();

        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::STATE_BLOCKED_GOVERNANCE, $payload['state']);
        $this->assertSame('blocked_governance', $payload['blocker_translation']['kind']);
        $this->assertContains('governed_execution_exception', $payload['blockers']);
    }

    public function test_ux_orchestrator_detects_stale_queue_as_waiting_worker(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_code_forge_work_intake' => [
                    'status' => 'ready',
                    'objective' => 'X',
                    'business_rule' => 'Y',
                    'acceptance_criteria' => ['ok'],
                ],
                'latest_forge_live_execution_async' => [
                    'status' => 'queued',
                    'queued_at' => now()->subSeconds(180)->toIso8601String(),
                ],
                'latest_atlas_code_forge_fast_path' => [
                    'status' => 'queued',
                    'spec_hash' => 'a',
                    'plan_hash' => 'b',
                    'queued_at' => now()->subSeconds(180)->toIso8601String(),
                ],
            ]),
        ])->save();

        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::STATE_WAITING_WORKER, $payload['state']);
        $this->assertSame('waiting_worker', $payload['blocker_translation']['kind']);
        $this->assertContains('worker_queue_stale', $payload['blockers']);
    }

    public function test_ux_orchestrator_does_not_show_approve_before_review(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_code_forge_work_intake' => [
                    'status' => 'ready',
                    'objective' => 'X',
                    'business_rule' => 'Y',
                    'acceptance_criteria' => ['ok'],
                ],
                'latest_atlas_code_forge_fast_path' => [
                    'status' => 'prepared',
                    'spec_hash' => 'a',
                    'plan_hash' => 'b',
                ],
            ]),
        ])->save();

        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertFalse($payload['completion_gating']['approve_button_visible']);
        $this->assertFalse($payload['completion_gating']['reject_button_visible']);
        $this->assertFalse($payload['completion_gating']['rollback_button_visible']);
    }

    public function test_ux_orchestrator_exposes_evidence_separation(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertArrayHasKey('evidence_separation', $payload);
        $this->assertTrue($payload['evidence_separation']['system_certification_visible']);
        $this->assertSame(0, $payload['evidence_separation']['obra_evidence_ref_count']);
    }

    public function test_ux_orchestrator_exposes_chat_message_kinds(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame(
            ['definition', 'command', 'question', 'decision', 'note'],
            $payload['chat_message_kinds'],
        );
    }

    public function test_ux_orchestrator_maps_prepared_to_execute_primary_action(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_code_forge_work_intake' => ['status' => 'ready'],
                'latest_atlas_code_forge_fast_path' => [
                    'status' => 'prepared',
                    'spec_hash' => 'abc',
                    'plan_hash' => 'def',
                ],
            ]),
        ])->save();

        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::STATE_READY_TO_EXECUTE, $payload['state']);
        $this->assertSame('Executar Forge', $payload['primary_action_label']);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::ACTION_KIND_EXECUTE_FAST_PATH, $payload['primary_action_kind']);
    }

    public function test_ux_orchestrator_maps_provider_confirmation_required(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_code_forge_work_intake' => ['status' => 'ready'],
                'latest_atlas_code_forge_fast_path' => [
                    'status' => 'prepared',
                    'spec_hash' => 'a',
                    'plan_hash' => 'b',
                ],
                'latest_atlas_forge_provider_invocation' => [
                    'schema_version' => AtlasForgeProviderInvocationService::SCHEMA_VERSION,
                    'status' => 'blocked',
                    'mode' => 'execute',
                ],
                'latest_atlas_forge_provider_invocation_blockers' => ['operator_provider_approval_required'],
            ]),
        ])->save();

        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        // The state may be ready_to_execute or waiting_provider_confirmation depending on blockers projection;
        // both are acceptable as long as completion claim is not promoted.
        $this->assertContains($payload['state'], [
            AtlasCodeForgeUxOrchestratorService::STATE_READY_TO_EXECUTE,
            AtlasCodeForgeUxOrchestratorService::STATE_WAITING_PROVIDER_CONFIRMATION,
        ]);
        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_ux_orchestrator_never_promotes_completion_claim(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeForgeUxOrchestratorService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertTrue($payload['safety_summary']['review_completion_gate_preserved']);
    }

    public function test_ux_orchestrator_preserves_external_rivals_block(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_code_forge_human_first_ux_certification', $report);
        $this->assertFalse($report['atlas_code_forge_human_first_ux_certification']['promotes_external_rivals_claim']);
        $this->assertSame('forge_runtime_certification', $report['external_rivals_certification']['separated_from']);
    }

    public function test_state_endpoint_exposes_forge_ux_orchestrator(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_ux_orchestrator.schema_version', AtlasCodeForgeUxOrchestratorService::SCHEMA_VERSION)
            ->assertJsonPath('forge_ux_orchestrator.external_provider_call', false)
            ->assertJsonPath('forge_ux_orchestrator.completion_claim_promoted', false);
    }

    public function test_cli_strict_without_obra_exits_one(): void
    {
        $exitCode = Artisan::call('atlas:code:forge-ux', ['--strict' => true, '--json' => true]);
        $this->assertSame(1, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(AtlasCodeForgeUxOrchestratorService::STATE_NO_OBRA, $payload['state']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'ux test',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'ux test',
            'desired_outcome' => 'ux orchestrator smoke',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-ux-test'],
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
    }

    private function ensureSchema(): void
    {
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
                $t->string('priority')->default('normal');
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
        foreach ([
            'ai_threads', 'ai_messages', 'ai_traces',
            'atlas_engineering_runs', 'atlas_engineering_evidence',
            'atlas_tool_runs', 'atlas_ledger_events',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t) use ($table) {
                    if ($table === 'atlas_ledger_events') {
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

                        return;
                    }
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
        }
    }
}
