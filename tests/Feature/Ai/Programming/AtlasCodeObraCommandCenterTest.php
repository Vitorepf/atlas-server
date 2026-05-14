<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeObraCommandCenterService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atlas Code Obra Command Center v1 · feature tests.
 *
 * Auditam o schema canonico atlas.code.obra_command_center.v1: lifecycle 8
 * fases, progresso duplo, decision inbox, blocker translation honesto,
 * operational health unknown honesto, completion claim NUNCA promovido,
 * external rivals separado, state projection e audit certification.
 */
class AtlasCodeObraCommandCenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_command_center_fails_closed_without_obra(): void
    {
        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot([]);
        $this->assertSame(AtlasCodeObraCommandCenterService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasCodeObraCommandCenterService::STATUS_NO_OBRA, $payload['status']);
        $this->assertFalse($payload['obra_present']);
        $this->assertFalse($payload['primary_action_enabled']);
        $this->assertSame('obra_not_bound', $payload['primary_action_disabled_reason']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertTrue($payload['review_gate_preserved']);
    }

    public function test_command_center_never_calls_provider(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertTrue($payload['review_gate_preserved']);
        $this->assertFalse($payload['safety_summary']['external_provider_call']);
        $this->assertFalse($payload['safety_summary']['completion_claim_promoted']);
        $this->assertTrue($payload['safety_summary']['review_completion_gate_preserved']);
        $this->assertSame(
            'blocked_requires_operator_approval',
            $payload['safety_summary']['external_rivals_certification'],
        );
    }

    public function test_lifecycle_has_eight_canonical_phases(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $keys = array_map(static fn (array $p): string => (string) $p['key'], (array) $payload['lifecycle_phases']);
        $this->assertSame([
            'intake',
            'architecture',
            'forge_prep',
            'build',
            'review',
            'proofs',
            'decision',
            'learning',
        ], $keys);
    }

    public function test_readiness_and_proven_delivery_are_separate(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertArrayHasKey('readiness_progress', $payload);
        $this->assertArrayHasKey('proven_delivery_progress', $payload);
        $this->assertSame('Preparacao da Obra', $payload['readiness_progress']['label']);
        $this->assertSame('Entrega comprovada', $payload['proven_delivery_progress']['label']);
        $this->assertNotSame($payload['readiness_progress']['breakdown'], $payload['proven_delivery_progress']['breakdown']);
    }

    public function test_decision_inbox_appears_when_definition_incomplete(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $keys = array_map(static fn (array $d): string => (string) $d['key'], (array) $payload['decision_inbox']);
        $this->assertContains('complete_definition', $keys);
    }

    public function test_scope_blocker_is_not_governance_generic(): void
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

        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked_scope', $payload['blocker_translation']['kind']);
        $this->assertNotSame('blocked_governance', $payload['blocker_translation']['kind']);
        $this->assertContains('src/forbidden.ts', $payload['blocker_translation']['files_out_of_scope']);
    }

    public function test_governed_exception_without_details_becomes_honest_governance_blocker(): void
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

        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame('blocked_governance', $payload['blocker_translation']['kind']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('governed_execution_exception', $payload['blocker_summary']['kinds']);
    }

    public function test_operational_health_unknown_is_honest_without_obra(): void
    {
        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot([]);
        $health = (array) $payload['operational_health'];
        $this->assertSame('unknown', $health['queue_status']);
        $this->assertSame('unknown', $health['worker_status']);
        $this->assertSame('unknown', $health['heartbeat_status']);
        $this->assertNull($health['last_event_at']);
        $this->assertNull($health['current_state_age_seconds']);
    }

    public function test_completion_claim_is_never_promoted(): void
    {
        $obra = $this->makeObra();
        $obra->forceFill([
            'metadata' => array_merge((array) $obra->metadata, [
                'latest_atlas_code_forge_completion_claim' => [
                    'completion_status' => 'pending',
                    'human_approved' => false,
                    'final_completion_allowed' => false,
                ],
            ]),
        ])->save();

        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertFalse($payload['safety_summary']['completion_claim_promoted']);
    }

    public function test_external_rivals_remains_separated(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeObraCommandCenterService::class)->snapshot(['obra_id' => (string) $obra->id]);
        $this->assertSame('external_rivals_certification', $payload['separated_from']);
        $this->assertSame(
            'blocked_requires_operator_approval',
            $payload['safety_summary']['external_rivals_certification'],
        );
    }

    public function test_state_endpoint_exposes_obra_command_center(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('obra_command_center.schema_version', AtlasCodeObraCommandCenterService::SCHEMA_VERSION)
            ->assertJsonPath('obra_command_center.external_provider_call', false)
            ->assertJsonPath('obra_command_center.completion_claim_promoted', false)
            ->assertJsonPath('obra_command_center.review_gate_preserved', true);
    }

    public function test_endpoint_returns_command_center_payload(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/obra-command-center', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasCodeObraCommandCenterService::SCHEMA_VERSION)
            ->assertJsonPath('separated_from', 'external_rivals_certification');
    }

    public function test_cli_strict_without_obra_exits_one(): void
    {
        $exit = Artisan::call('atlas:code:obra-command-center', ['--strict' => true, '--json' => true]);
        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(AtlasCodeObraCommandCenterService::STATUS_NO_OBRA, $payload['status']);
    }

    public function test_audit_exposes_certification_block(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_code_obra_command_center_certification', $report);
        $cert = $report['atlas_code_obra_command_center_certification'];
        $this->assertSame('atlas.code.obra_command_center_certification.v1', $cert['schema_version']);
        $this->assertFalse($cert['external_provider_call']);
        $this->assertFalse($cert['promotes_external_rivals_claim']);
        $this->assertTrue($cert['separated_from_external_rivals_certification']);
        $this->assertContains('intake', $cert['lifecycle_phases']);
        $this->assertContains('learning', $cert['lifecycle_phases']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'command center test',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'command center smoke',
            'desired_outcome' => 'command center smoke',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-cc-test'],
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
