<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiPolicyApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        parent::tearDown();
    }

    public function test_profiles_endpoint_exposes_domain_flow_catalog_for_app_configuration(): void
    {
        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson('/ai/policies/profiles')
            ->assertOk()
            ->assertJsonPath('profile_registry.source', 'static_fallback')
            ->assertJsonPath('profile_registry.domains.0.id', 'general')
            ->assertJsonPath(
                'profile_registry.flows',
                fn (mixed $flows): bool => is_array($flows)
                    && collect($flows)->contains(fn (array $flow): bool => ($flow['id'] ?? null) === 'programming.forge')
            );
    }

    public function test_preview_endpoint_projects_session_override_into_effective_policy(): void
    {
        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/policies/preview', [
                'profile_id' => 'programming.forge',
                'ai_policy_override' => [
                    'default_provider' => 'codex_cli',
                    'providers' => [
                        'codex_cli' => [
                            'model' => 'gpt-5.5',
                            'model_label' => 'GPT-5.5',
                            'model_tier' => 'premium',
                        ],
                    ],
                    'allowed_models' => [
                        'codex_cli' => ['gpt-5.5'],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('profile.profile_id', 'programming.forge')
            ->assertJsonPath('profile.domain', 'programming')
            ->assertJsonPath('profile.flow', 'programming.forge')
            ->assertJsonPath('effective_policy.policy_version', 'atlas-ai-policy-v2')
            ->assertJsonPath('effective_policy.runtime_policy.default_provider', 'codex_cli')
            ->assertJsonPath('effective_policy.runtime_policy.providers.codex_cli.model', 'gpt-5.5')
            ->assertJsonPath('effective_policy.runtime_policy.allowed_models.codex_cli.0', 'gpt-5.5')
            ->assertJsonPath('effective_policy.session_override.present', true)
            ->assertJsonPath('effective_policy.execution_policy.executor_preference', 'engineering_harness')
            ->assertJsonPath('effective_policy.operational_contracts.model_graph.graph', 'scout_execute_review')
            ->assertJsonPath('effective_policy.operational_contracts.model_graph.nodes.1.provider', 'codex_cli')
            ->assertJsonPath('effective_policy.operational_contracts.tools.mode', 'harness')
            ->assertJsonPath('effective_policy.operational_contracts.gates.minimum_gate', 'strict');
    }

    public function test_flow_policy_endpoint_updates_database_profile_and_returns_effective_policy(): void
    {
        $this->createProfileTables();
        $now = now();
        DB::table('ai_domain_profiles')->insert([
            'id' => 'programming',
            'label' => 'Programming',
            'status' => 'active',
            'default_flow' => 'programming.dev',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'runtime_family' => 'engineering',
            'description' => null,
            'autonomy_default' => 'medium',
            'background_allowed' => false,
            'model_policy' => '{}',
            'context_policy' => '{}',
            'skill_policy' => '{}',
            'tool_policy' => '{}',
            'memory_policy' => '{}',
            'gate_policy' => '{}',
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('ai_flow_profiles')->insert([
            'id' => 'programming.dev',
            'domain_id' => 'programming',
            'label' => 'Dev',
            'status' => 'active',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'runtime' => 'ProviderExecution',
            'description' => null,
            'autonomy' => 'medium',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'model_policy' => '{}',
            'context_policy' => '{}',
            'skill_policy' => '{}',
            'tool_policy' => '{}',
            'memory_policy' => '{}',
            'gate_policy' => '{}',
            'execution_policy' => json_encode(['executor_preference' => 'simple_provider_execution'], JSON_THROW_ON_ERROR),
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->patchJson('/ai/policies/flows/programming.dev', [
                'label' => 'Dev Enterprise',
                'model_policy' => ['preset' => 'quality', 'default' => 'quality'],
                'context_policy' => ['preset' => 'deep', 'require_context_pack' => true],
                'memory_policy' => ['preset' => 'deep', 'record_decisions' => true],
                'skill_policy' => ['preset' => 'domain', 'require_skill_trace' => true],
                'tool_policy' => ['preset' => 'workspace_write', 'workspace_write' => true],
                'gate_policy' => ['minimum_gate' => 'strict'],
                'execution_policy' => ['executor_preference' => 'dev_repair_executor'],
            ])
            ->assertOk()
            ->assertJsonPath('flow_profile.label', 'Dev Enterprise')
            ->assertJsonPath('flow_profile.model_policy.preset', 'quality')
            ->assertJsonPath('flow_profile.context_policy.preset', 'deep')
            ->assertJsonPath('flow_profile.memory_policy.record_decisions', true)
            ->assertJsonPath('flow_profile.skill_policy.require_skill_trace', true)
            ->assertJsonPath('flow_profile.tool_policy.workspace_write', true)
            ->assertJsonPath('flow_profile.gate_policy.minimum_gate', 'strict')
            ->assertJsonPath('flow_profile.execution_policy.executor_preference', 'dev_repair_executor')
            ->assertJsonPath('profile_registry.source', 'database')
            ->assertJsonPath('effective_policy.profile_id', 'programming.dev')
            ->assertJsonPath('effective_policy.profile_declared_execution_policy.executor_preference', 'dev_repair_executor')
            ->assertJsonPath('effective_policy.execution_policy.executor_preference', 'dev_repair_executor')
            ->assertJsonPath('effective_policy.policies.model_policy.preset', 'quality')
            ->assertJsonPath('effective_policy.policies.context_policy.require_context_pack', true)
            ->assertJsonPath('effective_policy.policies.memory_policy.preset', 'deep')
            ->assertJsonPath('effective_policy.policies.skill_policy.preset', 'domain')
            ->assertJsonPath('effective_policy.policies.tool_policy.preset', 'workspace_write')
            ->assertJsonPath('effective_policy.policies.gate_policy.minimum_gate', 'strict')
            ->assertJsonPath('effective_policy.operational_contracts.model_graph.preset', 'quality')
            ->assertJsonPath('effective_policy.operational_contracts.context.depth', 'deep')
            ->assertJsonPath('effective_policy.operational_contracts.memory.scope', 'deep')
            ->assertJsonPath('effective_policy.operational_contracts.skills.mode', 'domain')
            ->assertJsonPath('effective_policy.operational_contracts.skills.required_bundles.0', 'dev-quality-gate')
            ->assertJsonPath('effective_policy.operational_contracts.tools.workspace_write', true)
            ->assertJsonPath('effective_policy.operational_contracts.gates.evidence_required', true)
            ->assertJsonPath('effective_policy.execution_authority', 'domain_flow_profile')
            ->assertJsonPath('effective_policy.compatibility.flow_execution_policy_authoritative', true);
    }

    private function createProfileTables(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        Schema::create('ai_domain_profiles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('label');
            $table->string('status')->default('active');
            $table->string('default_flow')->nullable();
            $table->string('orchestrator')->nullable();
            $table->string('runtime_family')->nullable();
            $table->text('description')->nullable();
            $table->string('autonomy_default')->default('medium');
            $table->boolean('background_allowed')->default(false);
            $table->json('model_policy')->default('{}');
            $table->json('context_policy')->default('{}');
            $table->json('skill_policy')->default('{}');
            $table->json('tool_policy')->default('{}');
            $table->json('memory_policy')->default('{}');
            $table->json('gate_policy')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_flow_profiles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('domain_id');
            $table->string('label');
            $table->string('status')->default('active');
            $table->string('orchestrator')->nullable();
            $table->string('runtime')->nullable();
            $table->text('description')->nullable();
            $table->string('autonomy')->default('medium');
            $table->boolean('background_allowed')->default(false);
            $table->boolean('requires_human_approval_for_destructive')->default(true);
            $table->json('model_policy')->default('{}');
            $table->json('context_policy')->default('{}');
            $table->json('skill_policy')->default('{}');
            $table->json('tool_policy')->default('{}');
            $table->json('memory_policy')->default('{}');
            $table->json('gate_policy')->default('{}');
            $table->json('execution_policy')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
