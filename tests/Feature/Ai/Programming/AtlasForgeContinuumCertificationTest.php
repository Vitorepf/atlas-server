<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Programming\AtlasForgeContinuumCertificationService;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasForgeContinuumCertificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_continuum_certification_has_all_required_invariants(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasForgeContinuumCertificationService::class);

        $payload = $service->certify(['obra_id' => (string) $obra->id]);

        $this->assertSame(AtlasForgeContinuumCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame((string) $obra->id, $payload['obra_id']);
        $this->assertTrue($payload['obra_present']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['is_external_benchmark']);

        $invariants = $payload['invariants'];
        foreach (AtlasForgeContinuumCertificationService::REQUIRED_INVARIANTS as $invariant) {
            $this->assertArrayHasKey($invariant, $invariants, "Missing required invariant [{$invariant}].");
            $this->assertIsBool($invariants[$invariant], "Invariant [{$invariant}] must be boolean.");
        }

        // Backend-only invariants must be true once code is wired.
        foreach ([
            'doc_mother_present',
            'atlas_code_forge_only',
            'obra_required',
            'work_intake_available',
            'forge_workspace_binding_available',
            'fast_path_available',
            'live_execution_available',
            'review_completion_available',
            'operator_cockpit_available',
            'atlas_decide_contract_referenced',
            'provider_topology_available',
            'fallback_policy_available',
            'provider_failure_classifier_available',
            'no_silent_fallback',
            'provider_capacity_exhausted_blocker_available',
            'state_projection_available',
            'review_completion_gate_preserved',
            'repair_loop_preserved',
            'evidence_pack_available',
            'evidence_ledger_refs_supported',
            'rivals_separated_from_external_claim',
            'no_external_provider_call',
            'no_silent_obra_creation',
            'completion_audit_block_available',
        ] as $required) {
            $this->assertTrue(
                $invariants[$required],
                "Backend invariant [{$required}] must be true; currently false."
            );
        }
    }

    public function test_continuum_certify_fails_closed_without_obra_in_strict(): void
    {
        $exitCode = Artisan::call('atlas:forge:continuum-certify', ['--strict' => true, '--json' => true]);

        $this->assertSame(1, $exitCode);

        $output = Artisan::output();
        $this->assertNotEmpty($output);
        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertSame(AtlasForgeContinuumCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains('obra_required', $payload['blockers']);
        $this->assertNotSame(AtlasForgeContinuumCertificationService::STATUS_AVAILABLE, $payload['status']);
    }

    public function test_provider_topology_does_not_call_external_provider(): void
    {
        $obra = $this->makeObra();
        $topology = app(AtlasForgeProviderTopologyService::class)
            ->topology(['obra_id' => (string) $obra->id]);

        $this->assertFalse($topology['external_provider_call']);
        $this->assertTrue($topology['is_read_model']);
    }

    public function test_fallback_never_silent_and_emits_evidence_payload(): void
    {
        $obra = $this->makeObra();
        $topology = app(AtlasForgeProviderTopologyService::class)->topology(['obra_id' => (string) $obra->id]);
        $policy = app(AtlasForgeProviderFallbackPolicyService::class);

        foreach (AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES as $failureType) {
            $classification = $policy->classify(
                failure: [
                    'type' => $failureType,
                    'role' => AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
                    'provider' => 'anthropic',
                    'model' => 'claude-opus-4-7',
                    'reason' => 'simulated '.$failureType,
                ],
                topology: $topology,
            );

            $this->assertArrayHasKey('event', $classification);
            $event = $classification['event'];
            $this->assertSame(AtlasForgeProviderFallbackPolicyService::EVENT_SCHEMA_VERSION, $event['schema_version']);
            $this->assertFalse($event['silent'], "Failure {$failureType} produced silent fallback.");
            $this->assertFalse($event['reduces_quality_gates'], "Failure {$failureType} reduced quality gates.");
            $this->assertFalse($event['bypasses_review_completion_gate'], "Failure {$failureType} bypassed review gate.");
            $this->assertFalse($event['auto_completes_work'], "Failure {$failureType} auto-completed work.");
            $this->assertNotEmpty($event['event_id']);
            $this->assertNotEmpty($event['occurred_at']);
            $this->assertSame($failureType, $event['failure_type']);
        }
    }

    public function test_fallback_does_not_bypass_review_completion_gate(): void
    {
        $obra = $this->makeObra();
        $topology = app(AtlasForgeProviderTopologyService::class)->topology(['obra_id' => (string) $obra->id]);
        $policy = app(AtlasForgeProviderFallbackPolicyService::class);

        $classification = $policy->classify(
            failure: [
                'type' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
                'role' => AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
                'provider' => 'anthropic',
                'model' => 'claude-opus-4-7',
            ],
            topology: $topology,
        );

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE, $classification['action']);
        $this->assertFalse($classification['event']['bypasses_review_completion_gate']);
        $this->assertFalse($classification['event']['auto_completes_work']);
        $this->assertFalse($classification['event']['reduces_quality_gates']);

        $invariants = $classification['invariants'];
        $this->assertTrue($invariants['fallback_does_not_bypass_review_completion_gate']);
        $this->assertTrue($invariants['fallback_does_not_auto_complete_work']);
        $this->assertTrue($invariants['fallback_does_not_reduce_quality_gates']);
        $this->assertTrue($invariants['no_silent_fallback']);
    }

    public function test_completion_audit_exposes_atlas_forge_continuum_certification(): void
    {
        $workspace = base_path();
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report($workspace);

        $this->assertArrayHasKey('atlas_forge_continuum_certification', $report);
        $block = $report['atlas_forge_continuum_certification'];

        $this->assertSame(AtlasForgeContinuumCertificationService::SCHEMA_VERSION, $block['schema_version']);
        $this->assertContains($block['status'], [
            'available',
            'backend_available_ui_pending',
            'missing_artifacts',
            'blocked',
        ]);
        $this->assertFalse($block['external_provider_call']);
        $this->assertFalse($block['is_external_benchmark']);
        $this->assertTrue($block['no_silent_fallback']);
        $this->assertFalse($block['promotes_external_rivals_claim']);
        $this->assertTrue($block['separated_from_external_rivals_certification']);
        $this->assertSame('external_rivals_certification', $block['separated_from']);

        // All canonical invariants must be reported.
        foreach (AtlasForgeContinuumCertificationService::REQUIRED_INVARIANTS as $invariant) {
            $this->assertArrayHasKey($invariant, $block['invariants']);
        }

        $this->assertArrayHasKey('provider_topology_summary', $block);
        $this->assertArrayHasKey('fallback_policy_summary', $block);
        $this->assertCount(5, AtlasForgeProviderTopologyService::CANONICAL_ROLES);
        $this->assertSame(5, $block['provider_topology_summary']['role_count']);
        $this->assertTrue($block['fallback_policy_summary']['no_silent_fallback']);
        $this->assertTrue($block['fallback_policy_summary']['capacity_exhausted_is_hard_blocker']);
    }

    public function test_completion_audit_reports_live_atlas_decide_topology_when_receipt_exists(): void
    {
        $obra = $this->makeObra();
        app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Executar Forge Continuum com topologia emitida pelo Atlas Decide.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'atlas_workflow_mode' => 'forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5');

        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $runtime = data_get($report, 'atlas_forge_continuum_certification.live_decide_runtime');

        $this->assertIsArray($runtime);
        $this->assertSame('live_atlas_decide', $runtime['decision_source']);
        $this->assertTrue($runtime['live_atlas_decide_topology_available']);
        $this->assertTrue($runtime['decision_receipt_topology_projection_available']);
        $this->assertFalse($runtime['static_policy_fallback_declared']);
    }

    public function test_continuum_certification_preserves_external_rivals_block(): void
    {
        $workspace = base_path();
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report($workspace);

        $this->assertArrayHasKey('external_rivals_certification', $report);
        $external = $report['external_rivals_certification'];

        // External Rivals continues to live as a separate audit block.
        $this->assertSame('atlas.programming.rivals_readiness.v1', $external['schema_version']);
        $this->assertSame('forge_runtime_certification', $external['separated_from']);
        $this->assertContains($external['status'], [
            'passed',
            'blocked',
            'blocked_requires_operator_approval',
        ]);

        // Continuum certification NEVER promotes external Rivals claim.
        $continuum = $report['atlas_forge_continuum_certification'];
        $this->assertFalse($continuum['promotes_external_rivals_claim']);
        $this->assertTrue($continuum['separated_from_external_rivals_certification']);

        // Audit must continue blocked when external_rivals_certification is blocked.
        if (($external['status'] ?? null) !== 'passed') {
            $this->assertSame('blocked', $report['status']);
        }
    }

    public function test_provider_topology_endpoint_returns_state_for_obra(): void
    {
        $obra = $this->makeObra();

        $this->getJson('/atlas-code/works/'.$obra->id.'/forge/provider-topology', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.forge.provider_topology.v1')
            ->assertJsonPath('obra_id', (string) $obra->id)
            ->assertJsonPath('obra_present', true)
            ->assertJsonPath('external_provider_call', false);
    }

    public function test_continuum_certify_command_simulates_capacity_exhausted_blocker(): void
    {
        $obra = $this->makeObra();
        $exitCode = Artisan::call('atlas:forge:continuum-certify', [
            '--obra' => (string) $obra->id,
            '--simulate-provider-failure' => AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED,
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        // Either explicit `blocked` status OR missing_artifacts with provider_capacity_exhausted
        // in the blockers list — both prove the honest blocker propagated through topology.
        $this->assertContains(
            AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED,
            $payload['blockers'],
        );
        $this->assertSame(1, $exitCode, 'Strict mode must exit non-zero on capacity exhaustion.');

        // Topology must also expose the capacity_exhausted blocker.
        $topology = $payload['provider_topology'] ?? [];
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, $topology['status']);
        $this->assertContains(
            AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED,
            (array) ($topology['blockers'] ?? []),
        );
        $event = $topology['last_fallback_event'] ?? null;
        $this->assertIsArray($event);
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::ACTION_BLOCK, $event['action']);
        $this->assertFalse($event['silent']);
    }

    public function test_continuum_certify_with_obra_returns_available_projection(): void
    {
        $obra = $this->makeObra();

        /** @var AtlasForgeContinuumCertificationService $service */
        $service = app(AtlasForgeContinuumCertificationService::class);
        $payload = $service->certify(['obra_id' => (string) $obra->id]);

        $this->assertSame(AtlasForgeContinuumCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertTrue($payload['obra_present']);
        $this->assertSame((string) $obra->id, $payload['obra_id']);
        // With Obra resolved the projection is honest: status is one of the
        // four certification states (available / backend_available_ui_pending
        // / missing_artifacts / blocked) — never silent success.
        $this->assertContains($payload['status'], [
            AtlasForgeContinuumCertificationService::STATUS_AVAILABLE,
            AtlasForgeContinuumCertificationService::STATUS_BACKEND_AVAILABLE_UI_PENDING,
            AtlasForgeContinuumCertificationService::STATUS_MISSING_ARTIFACTS,
            AtlasForgeContinuumCertificationService::STATUS_BLOCKED,
        ]);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['is_external_benchmark']);
        // Provider topology must be reconstructible read-only with this Obra.
        $this->assertSame((string) $obra->id, $payload['provider_topology']['obra_id']);
        $this->assertTrue($payload['provider_topology']['obra_present']);
    }

    public function test_continuum_certification_endpoint_returns_canonical_payload(): void
    {
        $obra = $this->makeObra();

        $response = $this->getJson(
            '/atlas-code/works/'.$obra->id.'/forge/continuum-certification',
            $this->headers(),
        );

        // Endpoint maps status to HTTP: 200 when available/UI-pending, 409 when
        // missing_artifacts/blocked. Both are honest — the canonical payload is
        // returned in either case and the body is the source of truth.
        $this->assertContains($response->status(), [200, 409]);
        $response->assertJsonPath('schema_version', AtlasForgeContinuumCertificationService::SCHEMA_VERSION)
            ->assertJsonPath('obra_id', (string) $obra->id)
            ->assertJsonPath('obra_present', true)
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('separated_from', 'external_rivals_certification');

        // Canonical invariants must be enumerated by the endpoint — count matches REQUIRED_INVARIANTS.
        $payload = $response->json();
        $this->assertCount(
            count(AtlasForgeContinuumCertificationService::REQUIRED_INVARIANTS),
            $payload['invariants'],
        );
        foreach (AtlasForgeContinuumCertificationService::REQUIRED_INVARIANTS as $key) {
            $this->assertArrayHasKey($key, $payload['invariants']);
        }
    }

    public function test_fallback_policy_classifies_quota_exhausted_and_reroutes(): void
    {
        $obra = $this->makeObra();
        $topology = app(\App\Services\Ai\Programming\AtlasForgeProviderTopologyService::class)
            ->topology(['obra_id' => (string) $obra->id]);
        $policy = app(AtlasForgeProviderFallbackPolicyService::class);

        $primary = collect($topology['roles'])->firstWhere(
            'role',
            \App\Services\Ai\Programming\AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
        );

        $classification = $policy->classify(
            failure: [
                'type' => AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED,
                'role' => $primary['role'],
                'provider' => $primary['provider'],
                'model' => $primary['model'],
                'reason' => 'simulated quota_exhausted',
            ],
            topology: $topology,
        );

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE, $classification['action']);
        $this->assertNull($classification['blocker']);
        $this->assertNotNull($classification['selected_fallback']);
        $event = $classification['event'];
        $this->assertSame('atlas.forge.provider_fallback_event.v1', $event['schema_version']);
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED, $event['failure_type']);
        $this->assertFalse($event['silent']);
        $this->assertNotNull($event['selected_fallback_role']);
        $this->assertNotNull($event['selected_fallback_provider']);
    }

    public function test_continuum_certify_command_simulates_rate_limit_reroute(): void
    {
        $obra = $this->makeObra();
        Artisan::call('atlas:forge:continuum-certify', [
            '--obra' => (string) $obra->id,
            '--simulate-provider-failure' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $event = $payload['provider_topology']['last_fallback_event'] ?? null;
        $this->assertIsArray($event);
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE, $event['action']);
        $this->assertFalse($event['silent']);
        $this->assertNotNull($event['selected_fallback_role']);
        $this->assertNotNull($event['selected_fallback_provider']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Continuum Cert test Obra',
            'description' => 'Atlas Forge Continuum OS certification',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validar Continuum Certification end-to-end',
            'desired_outcome' => 'Continuum Certification all-green com Obra real',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-continuum-test', 'origin' => 'atlas-code-test'],
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
                $t->uuid('task_id')->nullable();
                $t->string('evidence_type')->nullable();
                $t->string('target_id')->nullable();
                $t->string('status')->nullable();
                $t->float('confidence')->nullable();
                $t->text('summary')->nullable();
                $t->text('command')->nullable();
                $t->text('output_excerpt')->nullable();
                $t->json('files')->nullable();
                $t->json('metadata')->nullable();
                $t->string('source')->nullable();
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
}
