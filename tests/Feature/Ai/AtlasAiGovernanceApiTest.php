<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiGovernanceApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_session_bootstrap_api_returns_canonical_package(): void
    {
        $this->getJson('/ai/session-bootstrap?task=voice%20realtime%20no%20mobile', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.session_bootstrap.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('placement.surface', 'voice_realtime')
            ->assertJsonPath('placement.layer', 'surface')
            ->assertJsonPath('docs_split_plan.owner', 'knowledge_governance')
            ->assertJsonPath('docs_split_plan.command', 'php artisan atlas:ai:docs-split-plan --owner=knowledge_governance --json')
            ->assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')
            ->assertJsonStructure(['gate_status', 'session_gate', 'implementation_contract', 'pre_implementation_checklist'])
            ->assertJsonPath('provider_projection.status', 'passed')
            ->assertJsonFragment(['docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md'])
            ->assertJsonFragment(['path' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md', 'exists' => 'yes']);

        $response = $this->getJson('/ai/session-bootstrap?task=voice%20realtime%20no%20mobile', $this->headers);
        $this->assertContains('architecture_readiness', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', $response->json('architecture_operations.operation_ids'));
    }

    public function test_session_bootstrap_api_strict_mode_conflicts_when_gate_is_blocked(): void
    {
        $this->getJson('/ai/session-bootstrap?task=coisa%20generica%20sem%20owner%20claro&strict=1', $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('gate_status', 'blocked')
            ->assertJsonPath('session_gate.strict_blocks_session', true)
            ->assertJsonFragment(['ambiguous_placement_requires_more_specific_feature_or_hint']);
    }

    public function test_feature_placement_api_requires_feature_and_places_provider_release(): void
    {
        $this->getJson('/ai/feature-placement', $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['feature']);

        $this->getJson('/ai/feature-placement?feature=Anthropic%20Finance%20Agents%20provider%20release', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.feature_placement.v1')
            ->assertJsonPath('placement.layer', 'provider_evolution')
            ->assertJsonPath('placement.domain', 'finance')
            ->assertJsonPath('placement.flow', 'provider_evolution.review')
            ->assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')
            ->assertJsonPath('implementation_contract.owner_layer', 'provider_evolution')
            ->assertJsonFragment(['run_provider_release_review_before_changing_routing'])
            ->assertJsonFragment(['path' => 'docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md', 'exists' => 'yes']);

        $response = $this->getJson('/ai/feature-placement?feature=Anthropic%20Finance%20Agents%20provider%20release', $this->headers);
        $this->assertContains('architecture_readiness', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', $response->json('architecture_operations.operation_ids'));
    }

    public function test_feature_placement_api_separates_business_context_from_domain(): void
    {
        $this->getJson('/ai/feature-placement?feature=corrigir%20bug%20em%20producao%20da%20Blackink', $this->headers)
            ->assertOk()
            ->assertJsonPath('placement.domain', 'programming')
            ->assertJsonPath('placement.business_context', 'blackink')
            ->assertJsonPath('placement.business_context_role', 'context_not_atlas_ai_domain')
            ->assertJsonPath('implementation_contract.business_context', 'blackink')
            ->assertJsonFragment(['keep_business_context_separate_from_atlas_ai_domain'])
            ->assertJsonFragment(['path' => 'docs/engineering-knowledge-base/atlas-ai-business-contexts.md', 'exists' => 'yes'])
            ->assertJsonFragment(['business_context_must_not_be_promoted_to_atlas_ai_domain_without_dedicated_runtime_gates_memory_and_evidence']);
    }

    public function test_feature_placement_api_strict_mode_conflicts_when_gate_is_blocked(): void
    {
        $this->getJson('/ai/feature-placement?feature=coisa%20generica%20sem%20owner%20claro&strict=1', $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('gate_status', 'blocked')
            ->assertJsonFragment(['ambiguous_placement_requires_more_specific_feature_or_hint']);
    }

    public function test_docs_split_plan_api_exposes_operational_backlog(): void
    {
        $response = $this->getJson('/ai/docs-split-plan', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.documentation_split_plan.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('policy.line_limits_source', 'EngineeringDocumentationHealthService')
            ->assertJsonPath('policy.growth_gate', 'If a split_required doc is touched, the change must either reduce it or add a focused child spec and backlink.');

        $this->assertGreaterThan(0, $response->json('split_required_count'));
        $this->assertNotEmpty($response->json('execution_order'));
        $this->assertNotSame('', $response->json('docs.0.target_shape'));
        $this->assertNotEmpty($response->json('docs.0.proposed_child_docs'));
        $this->assertContains('sync_and_index_code_are_rerun', $response->json('docs.0.acceptance_criteria'));
    }

    public function test_docs_split_plan_api_accepts_filters(): void
    {
        $response = $this->getJson('/ai/docs-split-plan?owner=kernel_architecture', $this->headers)
            ->assertOk()
            ->assertJsonPath('filters.owner', 'kernel_architecture');

        $this->assertGreaterThan(0, $response->json('split_required_count'));
        $this->assertSame(
            ['kernel_architecture'],
            collect($response->json('docs'))->pluck('owner_area')->unique()->values()->all(),
        );
    }

    public function test_architecture_readiness_api_returns_governance_snapshot(): void
    {
        $response = $this->getJson('/ai/architecture/readiness?owner=kernel_architecture', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.architecture_readiness.v1')
            ->assertJsonPath('checks.architecture_validate.status', 'ok')
            ->assertJsonPath('checks.documentation_health.status', 'ok')
            ->assertJsonPath('docs_split_plan.owner', 'kernel_architecture')
            ->assertJsonPath('docs_split_plan.command', 'php artisan atlas:ai:docs-split-plan --owner=kernel_architecture --json')
            ->assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')
            ->assertJsonPath('architecture_operations.commands.0.id', 'architecture_readiness');

        $this->assertContains($response->json('checks.provider_projection.status'), ['passed', 'needs_review']);
        $this->assertContains('architecture_readiness', $response->json('architecture_operations.related_operation_ids'));
        $this->assertContains('session_bootstrap', $response->json('architecture_operations.related_operation_ids'));
        $this->assertContains('feature_placement', $response->json('architecture_operations.related_operation_ids'));
        $this->assertContains('architecture_readiness', collect($response->json('architecture_operations.related_commands'))->pluck('id')->all());
        $this->assertContains('php artisan atlas:ai:docs-split-plan --owner=kernel_architecture --json', $response->json('review_signal.required_next_commands'));
    }

    public function test_architecture_readiness_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/architecture/readiness')->assertUnauthorized();
    }

    public function test_governance_apis_require_atlas_token(): void
    {
        $this->getJson('/ai/session-bootstrap?task=x')->assertUnauthorized();
        $this->getJson('/ai/feature-placement?feature=x')->assertUnauthorized();
        $this->getJson('/ai/docs-split-plan')->assertUnauthorized();
    }
}
