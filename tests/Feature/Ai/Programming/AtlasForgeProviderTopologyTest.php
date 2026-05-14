<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasForgeProviderTopologyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_provider_topology_exposes_canonical_roles(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasForgeProviderTopologyService::class);

        $payload = $service->topology(['obra_id' => (string) $obra->id]);

        $this->assertSame('atlas.forge.provider_topology.v1', $payload['schema_version']);
        $this->assertSame((string) $obra->id, $payload['obra_id']);
        $this->assertTrue($payload['obra_present']);
        $this->assertSame('one_shot_enterprise_default', $payload['strategy']);
        $this->assertNotEmpty($payload['provider_topology_id']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertTrue($payload['is_read_model']);

        $roles = collect($payload['roles']);
        foreach (AtlasForgeProviderTopologyService::CANONICAL_ROLES as $expectedRole) {
            $entry = $roles->firstWhere('role', $expectedRole);
            $this->assertNotNull($entry, "Missing canonical role [{$expectedRole}].");
            $this->assertArrayHasKey('provider', $entry);
            $this->assertArrayHasKey('model', $entry);
            $this->assertArrayHasKey('status', $entry);
            $this->assertArrayHasKey('capability_reason', $entry);
            $this->assertArrayHasKey('risk_fit', $entry);
            $this->assertArrayHasKey('autonomy_level', $entry);
            $this->assertArrayHasKey('fallback_order', $entry);
            $this->assertArrayHasKey('evidence_required', $entry);
            $this->assertTrue($entry['evidence_required']);
        }

        $primary = $roles->firstWhere('role', AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER);
        $this->assertSame('selected', $primary['status']);
        $this->assertSame('claude_cli', $primary['provider']);
        $this->assertSame('claude-opus-4-7', $primary['model']);
        $this->assertSame('static_policy', $payload['decision_source']);
        $this->assertFalse($payload['runtime_dispatch_allowed']);
    }

    public function test_decide_emits_provider_topology_for_atlas_code_forge(): void
    {
        $obra = $this->makeObra();
        $decision = app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Implementar uma feature Forge pesada com testes e review.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'app_surface' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'atlas_workflow_mode' => 'forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5')->toArray();

        $topology = data_get($decision, 'receipt_v2.forge_provider_topology');
        $this->assertIsArray($topology);
        $this->assertSame('atlas.forge.provider_topology.v1', $topology['schema_version']);
        $this->assertSame('live_atlas_decide', $topology['decision_source']);
        $this->assertSame(data_get($decision, 'receipt_v2.receipt_id'), $topology['decision_receipt_id']);
        $this->assertSame(data_get($decision, 'receipt_v2.receipt_hash'), $topology['decision_receipt_hash']);
        $this->assertSame('atlas.decide.v2', $topology['receipt_schema_version']);
        $this->assertCount(5, $topology['roles']);
        $this->assertSame('codex_cli', data_get($topology, 'roles.0.provider'));
        $this->assertFalse($topology['external_provider_call']);
    }

    public function test_provider_topology_does_not_call_external_provider(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasForgeProviderTopologyService::class);

        $payload = $service->topology(['obra_id' => (string) $obra->id]);

        $this->assertFalse($payload['external_provider_call']);
        $this->assertTrue($payload['is_read_model']);
        $this->assertSame('atlas.forge.provider_topology.v1', $payload['schema_version']);
        $this->assertGreaterThanOrEqual(3, count($payload['evidence_refs']));
        foreach ($payload['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
            $this->assertStringStartsWith('docs/', $ref);
        }
        $this->assertGreaterThanOrEqual(4, count($payload['provider_capacity']));
        $this->assertGreaterThanOrEqual(3, count($payload['fallback_chain']));
    }

    public function test_provider_topology_prefers_real_decision_receipt_over_static_policy(): void
    {
        $obra = $this->makeObra();
        $decision = app(AtlasDecideService::class)->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Forge coding task',
            'payload' => [
                'surface_id' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'obra_id' => (string) $obra->id,
            ],
        ], 'codex_cli', 'gpt-5.5')->toArray();

        $payload = app(AtlasForgeProviderTopologyService::class)->topology([
            'obra_id' => (string) $obra->id,
            'decision_receipt' => $decision['receipt_v2'],
        ]);

        $this->assertSame('live_atlas_decide', $payload['decision_source']);
        $this->assertSame(data_get($decision, 'receipt_v2.receipt_id'), $payload['decision_receipt_id']);
        $this->assertSame(data_get($decision, 'receipt_v2.receipt_hash'), $payload['decision_receipt_hash']);
        $this->assertSame('codex_cli', data_get($payload, 'roles.0.provider'));
    }

    public function test_provider_topology_endpoint_returns_state_for_obra(): void
    {
        $obra = $this->makeObra();

        $response = $this->getJson(
            '/atlas-code/works/'.$obra->id.'/forge/provider-topology',
            $this->headers(),
        );

        $response->assertOk()
            ->assertJsonPath('schema_version', 'atlas.forge.provider_topology.v1')
            ->assertJsonPath('obra_id', (string) $obra->id)
            ->assertJsonPath('obra_present', true)
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('is_read_model', true);
    }

    public function test_state_projection_exposes_forge_provider_topology(): void
    {
        $obra = $this->makeObra();

        $response = $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers());

        $response->assertOk()
            ->assertJsonPath('forge_provider_topology.schema_version', 'atlas.forge.provider_topology.v1')
            ->assertJsonPath('forge_provider_topology.obra_id', (string) $obra->id)
            ->assertJsonPath('forge_provider_topology.obra_present', true)
            ->assertJsonPath('forge_provider_topology.external_provider_call', false)
            ->assertJsonPath('forge_provider_topology.is_read_model', true)
            ->assertJsonPath('forge_continuum_certification.schema_version', 'atlas.forge_continuum_certification.v1')
            ->assertJsonPath('forge_continuum_certification.obra_id', (string) $obra->id)
            ->assertJsonPath('forge_continuum_certification.obra_present', true)
            ->assertJsonPath('forge_continuum_certification.external_provider_call', false);
    }

    public function test_fallback_policy_classifies_rate_limit_and_reroutes(): void
    {
        $obra = $this->makeObra();
        $topology = app(AtlasForgeProviderTopologyService::class)->topology(['obra_id' => (string) $obra->id]);
        $policy = app(AtlasForgeProviderFallbackPolicyService::class);

        $primary = collect($topology['roles'])->firstWhere('role', AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER);
        $classification = $policy->classify(
            failure: [
                'type' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
                'role' => $primary['role'],
                'provider' => $primary['provider'],
                'model' => $primary['model'],
                'reason' => 'simulated rate_limit',
            ],
            topology: $topology,
        );

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE, $classification['action']);
        $this->assertNull($classification['blocker']);
        $this->assertNotNull($classification['selected_fallback']);
        $this->assertSame('atlas.forge.provider_fallback_event.v1', $classification['event']['schema_version']);
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $classification['event']['failure_type']);
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE, $classification['event']['action']);
        $this->assertFalse($classification['event']['silent']);
        $this->assertFalse($classification['event']['reduces_quality_gates']);
        $this->assertFalse($classification['event']['bypasses_review_completion_gate']);
        $this->assertFalse($classification['event']['auto_completes_work']);
        $this->assertTrue($classification['event']['fallback_child_receipt_required']);
        $this->assertFalse($classification['event']['runtime_dispatch_allowed']);
        $this->assertNotNull($classification['event']['selected_fallback_role']);
    }

    public function test_fallback_policy_blocks_provider_capacity_exhausted(): void
    {
        $obra = $this->makeObra();
        $topology = app(AtlasForgeProviderTopologyService::class)->topology(['obra_id' => (string) $obra->id]);
        $policy = app(AtlasForgeProviderFallbackPolicyService::class);

        $classification = $policy->classify(
            failure: [
                'type' => AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED,
                'role' => AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
                'provider' => 'anthropic',
                'model' => 'claude-opus-4-7',
                'reason' => 'no capable provider remaining',
            ],
            topology: $topology,
        );

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::ACTION_BLOCK, $classification['action']);
        $this->assertSame(
            AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED,
            $classification['blocker'],
        );
        $this->assertNull($classification['selected_fallback']);
        $this->assertFalse($classification['event']['silent']);
        $this->assertSame('provider_capacity_exhausted', $classification['event']['blocker']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Provider Topology test Obra',
            'description' => 'Atlas Forge Provider Topology read-model',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validar Provider Topology + governed fallback',
            'desired_outcome' => 'Provider Topology canonical read-model + classifier auditavel',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-provider-topology-test', 'origin' => 'atlas-code-test'],
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
