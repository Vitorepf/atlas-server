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
            ->assertJsonPath('architecture_readiness.schema_version', 'atlas.architecture_readiness.v1')
            ->assertJsonPath('architecture_readiness.status', 'ready')
            ->assertJsonPath('architecture_readiness.owner', 'knowledge_governance')
            ->assertJsonPath('coverage_boundary.schema_version', 'atlas.implemented_vs_scaffold.coverage_boundary.v1')
            ->assertJsonPath('coverage_boundary.authority', 'diagnostic_read_model_only')
            ->assertJsonPath('safe_next_blocks.0.block', 'Voice Realtime product loop')
            ->assertJsonPath('architecture_readiness.coverage_boundary.schema_version', 'atlas.implemented_vs_scaffold.coverage_boundary.v1')
            ->assertJsonPath('architecture_readiness.safe_next_blocks.0.block', 'Voice Realtime product loop')
            ->assertJsonPath('architecture_readiness.command', 'php artisan atlas:ai:architecture-readiness --owner=knowledge_governance --json')
            ->assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')
            ->assertJsonPath('documentation_reality_gate.schema_version', 'atlas.session_bootstrap.documentation_reality_gate.v1')
            ->assertJsonPath('documentation_reality_gate.status', 'ready')
            ->assertJsonPath('documentation_reality_gate.adrs.block_count', 52)
            // Honest propagation: the gate forwards the real executes count (18 after Batch A
            // promoted six full-verb keys) from ADRS summary, not the retired all-52 integration
            // claim. Total block_count stays 52.
            ->assertJsonPath('documentation_reality_gate.adrs.integrated_runtime_block_count', 21)
            ->assertJsonPath('documentation_reality_gate.aurc.status', 'ready')
            ->assertJsonPath('documentation_reality_gate.claim_policy.providers_invoked', false)
            ->assertJsonPath('documentation_reality_gate.writes', false)
            ->assertJsonPath('cartography_navigation_slice.provider_safe', true)
            ->assertJsonStructure(['gate_status', 'session_gate', 'implementation_contract', 'pre_implementation_checklist'])
            ->assertJsonPath('provider_projection.status', 'passed')
            ->assertJsonFragment(['docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md'])
            ->assertJsonFragment(['path' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md', 'exists' => 'yes']);

        $response = $this->getJson('/ai/session-bootstrap?task=voice%20realtime%20no%20mobile', $this->headers);
        $this->assertContains('architecture_readiness', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('documentation_reality_score', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_anti_duplicate', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_reality_audit', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_reachability', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_deletion_preflight', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('universal_reality_cartography_navigation_slice', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('runtime_language_boundary', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_dependencies', $response->json('architecture_operations.operation_ids'));
        $this->assertSame(
            'atlas.voice_realtime.python_runtime_plan.v1',
            collect($response->json('architecture_operations.commands', []))
                ->firstWhere('id', 'voice_realtime_dependencies')['runtime_dependency_contract'] ?? null,
        );
        $this->assertSame(
            ['runtime_language_boundary'],
            $response->json('architecture_operations.owner_layer_operations.runtime.operation_ids'),
        );
        $this->assertTrue($response->json('architecture_operations.owner_layer_operations.runtime.commands.0.pre_implementation_gate'));
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', $response->json('required_validation'));
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
            ->assertJsonPath('documentation_reality_gate.schema_version', 'atlas.feature_placement.documentation_reality_gate.v1')
            ->assertJsonPath('documentation_reality_gate.status', 'ready')
            ->assertJsonPath('documentation_reality_gate.adrs.block_count', 52)
            // Honest propagation: feature-placement gate forwards the real executes count (18
            // after Batch A) from ADRS summary, not the retired all-52 integration claim.
            // block_count stays 52.
            ->assertJsonPath('documentation_reality_gate.adrs.integrated_runtime_block_count', 21)
            ->assertJsonPath('code_reality_anti_duplicate.schema_version', 'atlas.code_reality_usage_intelligence.v1')
            ->assertJsonPath('code_reality_anti_duplicate.status', 'ready')
            ->assertJsonPath('documentation_reality_gate.claim_policy.providers_invoked', false)
            ->assertJsonPath('documentation_reality_gate.writes', false)
            ->assertJsonPath('implementation_contract.owner_layer', 'provider_evolution')
            ->assertJsonFragment(['run_provider_release_review_before_changing_routing'])
            ->assertJsonFragment(['path' => 'docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md', 'exists' => 'yes']);

        $response = $this->getJson('/ai/feature-placement?feature=Anthropic%20Finance%20Agents%20provider%20release', $this->headers);
        $this->assertContains('architecture_readiness', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('documentation_reality_score', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_anti_duplicate', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_reality_audit', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_reachability', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('code_reality_deletion_preflight', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('universal_reality_cartography_navigation_slice', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('runtime_language_boundary', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', $response->json('required_validation'));
        $this->assertSame(
            ['runtime_language_boundary'],
            $response->json('architecture_operations.owner_layer_operations.runtime.operation_ids'),
        );
    }

    public function test_feature_placement_api_routes_heavy_runtime_features_to_language_boundaries(): void
    {
        $response = $this->getJson('/ai/feature-placement?feature=Graph%20RAG%20FAISS%20embeddings%20reranker%20local', $this->headers)
            ->assertOk()
            ->assertJsonPath('placement.layer', 'runtime')
            ->assertJsonPath('placement.runtime', 'python_ai_data')
            ->assertJsonPath('implementation_contract.runtime_family', 'python_ai_data')
            ->assertJsonPath('implementation_contract.runtime_invocation_contract.schema_version', 'atlas.runtime_invocation_contract.v1')
            ->assertJsonPath('implementation_contract.runtime_invocation_contract.kernel_first', true)
            ->assertJsonPath('implementation_contract.runtime_invocation_contract.selected_runtime_family', 'python_ai_data');

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
            collect($response->json('owner_docs'))->pluck('path')->all(),
        );
        $this->assertContains(
            'decision_receipt_hash',
            $response->json('implementation_contract.runtime_invocation_contract.required_fields'),
        );
        $this->assertContains(
            'create_parallel_context_store',
            $response->json('implementation_contract.runtime_invocation_contract.forbidden_runtime_authority'),
        );
        $this->assertContains(
            'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app',
            $response->json('implementation_contract.forbidden_write_scopes'),
        );
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', $response->json('required_validation'));
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
            ->assertJsonStructure(['summary' => ['ready_for_new_docs', 'message'], 'recommended_actions'])
            ->assertJsonPath('policy.line_limits_source', 'EngineeringDocumentationHealthService')
            ->assertJsonPath('policy.growth_gate', 'If a split_required doc is touched, the change must either reduce it or add a focused child spec and backlink.');

        $this->assertGreaterThanOrEqual(0, $response->json('split_required_count'));
        if ($response->json('split_required_count') > 0) {
            $this->assertNotEmpty($response->json('execution_order'));
            $this->assertNotSame('', $response->json('docs.0.target_shape'));
            $this->assertNotEmpty($response->json('docs.0.proposed_child_docs'));
            $this->assertContains('sync_and_index_code_are_rerun', $response->json('docs.0.acceptance_criteria'));
        } else {
            $this->assertSame([], $response->json('execution_order'));
            $this->assertSame([], $response->json('docs'));
        }
    }

    public function test_docs_split_plan_api_accepts_filters(): void
    {
        $response = $this->getJson('/ai/docs-split-plan?owner=kernel_architecture', $this->headers)
            ->assertOk()
            ->assertJsonPath('filters.owner', 'kernel_architecture');

        $this->assertGreaterThanOrEqual(0, $response->json('split_required_count'));
        if ($response->json('split_required_count') > 0) {
            $this->assertSame(
                ['kernel_architecture'],
                collect($response->json('docs'))->pluck('owner_area')->unique()->values()->all(),
            );
        } else {
            $this->assertSame([], $response->json('docs'));
        }
    }

    public function test_architecture_readiness_api_returns_governance_snapshot(): void
    {
        $response = $this->getJson('/ai/architecture/readiness?owner=kernel_architecture', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.architecture_readiness.v1')
            ->assertJsonStructure(['summary' => ['ready_for_implementation', 'message']])
            ->assertJsonPath('checks.architecture_validate.status', 'ok')
            ->assertJsonPath('checks.documentation_health.status', 'ok')
            ->assertJsonPath('docs_split_plan.owner', 'kernel_architecture')
            ->assertJsonPath('docs_split_plan.command', 'php artisan atlas:ai:docs-split-plan --owner=kernel_architecture --json')
            ->assertJsonPath('coverage_boundary.schema_version', 'atlas.implemented_vs_scaffold.coverage_boundary.v1')
            ->assertJsonPath('coverage_boundary.authority', 'diagnostic_read_model_only')
            ->assertJsonPath('safe_next_blocks.0.block', 'Voice Realtime product loop')
            ->assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')
            ->assertJsonPath('architecture_operations.commands.0.id', 'architecture_readiness');

        $this->assertStringContainsString('sem provider direto', $response->json('safe_next_blocks.0.dod_minimum'));
        $this->assertStringContainsString('VOICE_* real', $response->json('safe_next_blocks.0.dod_minimum'));
        $this->assertStringContainsString('review humano', $response->json('safe_next_blocks.0.dod_minimum'));
        $this->assertContains($response->json('checks.provider_projection.status'), ['passed', 'needs_review']);
        $this->assertContains('architecture_readiness', $response->json('architecture_operations.related_operation_ids'));
        $this->assertContains('session_bootstrap', $response->json('architecture_operations.related_operation_ids'));
        $this->assertContains('feature_placement', $response->json('architecture_operations.related_operation_ids'));
        $this->assertContains('runtime_language_boundary', $response->json('architecture_operations.related_operation_ids'));
        $this->assertContains('architecture_readiness', collect($response->json('architecture_operations.related_commands'))->pluck('id')->all());
        $this->assertContains('runtime_language_boundary', collect($response->json('architecture_operations.related_commands'))->pluck('id')->all());
        $this->assertSame(
            ['runtime_language_boundary'],
            $response->json('architecture_operations.owner_layer_operations.runtime.operation_ids'),
        );
        $this->assertSame(
            'runtime',
            $response->json('architecture_operations.owner_layer_operations.runtime.commands.0.owner_layer'),
        );
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
