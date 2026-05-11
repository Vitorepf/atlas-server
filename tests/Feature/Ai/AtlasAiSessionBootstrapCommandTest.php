<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiSessionBootstrapCommandTest extends TestCase
{
    public function test_session_bootstrap_returns_owner_docs_and_feature_placement(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'voice realtime no mobile',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.session_bootstrap.v1', $payload['schema_version']);
        $this->assertSame('voice_realtime', data_get($payload, 'placement.surface'));
        $this->assertContains($payload['gate_status'], ['attention_required', 'blocked', 'passed']);
        $this->assertArrayHasKey('implementation_contract', $payload);
        $this->assertArrayHasKey('session_gate', $payload);
        $this->assertSame('knowledge_governance', data_get($payload, 'docs_split_plan.owner'));
        $this->assertSame(
            'php artisan atlas:ai:docs-split-plan --owner=knowledge_governance --json',
            data_get($payload, 'docs_split_plan.command'),
        );
        $this->assertGreaterThanOrEqual(0, data_get($payload, 'docs_split_plan.split_required_count'));
        $this->assertSame('atlas.architecture_readiness.v1', data_get($payload, 'architecture_readiness.schema_version'));
        $this->assertSame('ready', data_get($payload, 'architecture_readiness.status'));
        $this->assertSame('knowledge_governance', data_get($payload, 'architecture_readiness.owner'));
        $this->assertSame('atlas.implemented_vs_scaffold.coverage_boundary.v1', data_get($payload, 'coverage_boundary.schema_version'));
        $this->assertSame('available', data_get($payload, 'coverage_boundary.status'));
        $this->assertSame('diagnostic_read_model_only', data_get($payload, 'coverage_boundary.authority'));
        $this->assertSame('Voice Realtime product loop', data_get($payload, 'safe_next_blocks.0.block'));
        $this->assertSame(
            'atlas.implemented_vs_scaffold.coverage_boundary.v1',
            data_get($payload, 'architecture_readiness.coverage_boundary.schema_version'),
        );
        $this->assertSame(
            'Voice Realtime product loop',
            data_get($payload, 'architecture_readiness.safe_next_blocks.0.block'),
        );
        $this->assertSame(
            'php artisan atlas:ai:architecture-readiness --owner=knowledge_governance --json',
            data_get($payload, 'architecture_readiness.command'),
        );
        $this->assertSame('atlas.architecture_operations.v1', data_get($payload, 'architecture_operations.schema_version'));
        $this->assertContains('architecture_readiness', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('runtime_language_boundary', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_projection_status', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('ap_agent_workflow_registry', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_dependencies', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_dependency_install_plan', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertSame(
            'atlas.voice_realtime.python_runtime_plan.v1',
            collect(data_get($payload, 'architecture_operations.commands', []))
                ->firstWhere('id', 'voice_realtime_dependencies')['runtime_dependency_contract'] ?? null,
        );
        $this->assertSame(
            'atlas.voice_realtime.dependency_install_plan.v1',
            collect(data_get($payload, 'architecture_operations.commands', []))
                ->firstWhere('id', 'voice_realtime_dependency_install_plan')['runtime_dependency_contract'] ?? null,
        );
        $this->assertSame(
            ['runtime_language_boundary'],
            data_get($payload, 'architecture_operations.owner_layer_operations.runtime.operation_ids'),
        );
        $this->assertTrue(data_get($payload, 'architecture_operations.owner_layer_operations.runtime.commands.0.pre_implementation_gate'));
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', data_get($payload, 'required_validation'));
        $this->assertContains('review_owner_docs', data_get($payload, 'session_gate.required_before_code'));
        $this->assertContains(
            'run_runtime_language_boundary_when_touching_python_go_swift_or_rag_ml',
            data_get($payload, 'session_gate.required_before_code'),
        );
        $this->assertContains(
            'review_ap_agent_workflow_registry_when_touching_ap_or_architecture_governance',
            data_get($payload, 'session_gate.required_before_code'),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
            $payload['read_first'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
            $payload['read_first'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            collect($payload['owner_docs'])->pluck('path')->all(),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md',
            collect($payload['owner_docs'])->pluck('path')->all(),
        );
    }

    public function test_session_bootstrap_human_output_exposes_safe_next_blocks(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'voice realtime no mobile',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Blocos seguros:', $output);
        $this->assertStringContainsString('Voice Realtime product loop', $output);
        $this->assertStringContainsString('Leia primeiro:', $output);
    }

    public function test_session_bootstrap_includes_ap_workflow_registry_for_ap_tasks(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'implementar AP cognitive productive failure',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertContains(
            'docs/ap/AP-204-ap-agent-workflow-registry.md',
            $payload['read_first'],
        );
        $this->assertContains('ap_agent_workflow_registry', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertSame(
            'php artisan atlas:ai:ap-agent-workflow --json',
            collect(data_get($payload, 'architecture_operations.commands', []))
                ->firstWhere('id', 'ap_agent_workflow_registry')['command'] ?? null,
        );
    }

    public function test_session_bootstrap_focuses_docs_split_plan_for_memory_tasks(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'melhorar open brain memory retrieval',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('memory_open_brain', data_get($payload, 'docs_split_plan.owner'));
        $this->assertSame(
            'php artisan atlas:ai:docs-split-plan --owner=memory_open_brain --json',
            data_get($payload, 'docs_split_plan.command'),
        );
        $executionOwners = collect(data_get($payload, 'docs_split_plan.execution_order', []))
            ->map(fn (string $path): string => str_contains($path, 'memory') || str_contains($path, 'open-brain') ? 'memory_open_brain' : 'other')
            ->unique()
            ->values()
            ->all();
        $this->assertSame(
            data_get($payload, 'docs_split_plan.split_required_count') > 0 ? ['memory_open_brain'] : [],
            $executionOwners,
        );
    }

    public function test_session_bootstrap_promotes_runtime_docs_into_read_first_for_heavy_runtime_tasks(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'Graph RAG FAISS embeddings reranker local',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('runtime', data_get($payload, 'placement.layer'));
        $this->assertSame('python_ai_data', data_get($payload, 'placement.runtime'));
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
            $payload['read_first'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
            $payload['read_first'],
        );
        $this->assertContains(
            'declare_kernel_decision_receipt_contract_for_runtime_invocation',
            $payload['pre_implementation_checklist'],
        );
    }

    public function test_place_feature_reports_duplicate_candidates_before_implementation(): void
    {
        $exit = Artisan::call('atlas:ai:place-feature', [
            'feature' => 'voice realtime no mobile',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.feature_placement.v1', $payload['schema_version']);
        $this->assertSame('surface', data_get($payload, 'placement.layer'));
        $this->assertSame('voice_realtime', data_get($payload, 'placement.surface'));
        $this->assertContains(data_get($payload, 'gate_status'), ['attention_required', 'blocked']);
        $this->assertSame('atlas.architecture_operations.v1', data_get($payload, 'architecture_operations.schema_version'));
        $this->assertContains('architecture_readiness', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('runtime_language_boundary', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_dependencies', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_dependency_install_plan', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertSame(
            'ATLAS_VOICE_PYTHON_BIN',
            collect(data_get($payload, 'architecture_operations.commands', []))
                ->firstWhere('id', 'voice_realtime_dependencies')['python_binary_policy']['environment_variable'] ?? null,
        );
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', data_get($payload, 'required_validation'));
        $this->assertContains('app/Services/Ai/Surface', data_get($payload, 'implementation_contract.allowed_write_scopes'));
        $this->assertContains('surface_must_collect_input_and_render_output_only', data_get($payload, 'implementation_contract.forbidden_write_scopes'));
        $this->assertContains('review_duplicate_candidates_and_reuse_existing_capabilities_first', $payload['pre_implementation_checklist']);
        $this->assertContains(
            'possible_existing_capability_or_doc_overlap_review_duplicate_candidates_first',
            $payload['risks'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            collect($payload['owner_docs'])->pluck('path')->all(),
        );
        $this->assertContains('repo_code', collect($payload['duplicate_candidates'])->pluck('source')->all());
        $this->assertContains(
            'high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision',
            $payload['blocked_when'],
        );
    }

    public function test_place_feature_routes_livekit_agents_to_voice_surface_and_python_runtime(): void
    {
        $exit = Artisan::call('atlas:ai:place-feature', [
            'feature' => 'implementar voice realtime LiveKit Agents runtime mobile first',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('surface', data_get($payload, 'placement.layer'));
        $this->assertSame('voice_realtime', data_get($payload, 'placement.surface'));
        $this->assertSame('python_ai_data', data_get($payload, 'placement.runtime'));
        $this->assertSame('voice_realtime.session', data_get($payload, 'placement.flow'));
        $this->assertContains('voice_realtime_dependencies', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_dependency_install_plan', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('runtimes/python', data_get($payload, 'implementation_contract.allowed_write_scopes'));
        $this->assertContains('runtime_must_not_execute_without_decision_receipt', data_get($payload, 'implementation_contract.forbidden_write_scopes'));
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            collect($payload['owner_docs'])->pluck('path')->all(),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md',
            collect($payload['owner_docs'])->pluck('path')->all(),
        );
    }

    public function test_place_feature_routes_graph_rag_to_python_runtime_boundary(): void
    {
        $exit = Artisan::call('atlas:ai:place-feature', [
            'feature' => 'Graph RAG com FAISS embeddings reranker local para context packs',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $ownerDocs = collect($payload['owner_docs'])->pluck('path')->all();

        $this->assertSame(0, $exit);
        $this->assertSame('runtime', data_get($payload, 'placement.layer'));
        $this->assertSame('python_ai_data', data_get($payload, 'placement.runtime'));
        $this->assertTrue(data_get($payload, 'placement.requires_ap'));
        $this->assertContains('new_or_future_capability_requires_ap_contract_first', $payload['blocked_when']);
        $this->assertContains('create_or_update_ap_contract_before_runtime_code', $payload['pre_implementation_checklist']);
        $this->assertSame('python_ai_data', data_get($payload, 'implementation_contract.runtime_family'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($payload, 'implementation_contract.runtime_invocation_contract.schema_version'));
        $this->assertTrue(data_get($payload, 'implementation_contract.runtime_invocation_contract.kernel_first'));
        $this->assertSame('python_ai_data', data_get($payload, 'implementation_contract.runtime_invocation_contract.selected_runtime_family'));
        $this->assertContains('decision_receipt_hash', data_get($payload, 'implementation_contract.runtime_invocation_contract.required_fields'));
        $this->assertContains('evidence_sink', data_get($payload, 'implementation_contract.runtime_invocation_contract.required_fields'));
        $this->assertContains('create_parallel_context_store', data_get($payload, 'implementation_contract.runtime_invocation_contract.forbidden_runtime_authority'));
        $this->assertContains('runtimes/python', data_get($payload, 'implementation_contract.allowed_write_scopes'));
        $this->assertContains(
            'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app',
            data_get($payload, 'implementation_contract.forbidden_write_scopes'),
        );
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md', $ownerDocs);
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md', $ownerDocs);
        $this->assertSame(
            ['runtime_language_boundary'],
            data_get($payload, 'architecture_operations.owner_layer_operations.runtime.operation_ids'),
        );
        $this->assertTrue(data_get($payload, 'architecture_operations.owner_layer_operations.runtime.commands.0.pre_implementation_gate'));
        $this->assertContains('run_runtime_language_boundary_before_and_after_changes', $payload['pre_implementation_checklist']);
        $this->assertContains(
            'rag_ml_graph_or_embedding_work_must_not_create_parallel_memory_or_context_store',
            $payload['risks'],
        );
    }

    public function test_place_feature_routes_go_edge_streaming_to_go_runtime_boundary(): void
    {
        $exit = Artisan::call('atlas:ai:place-feature', [
            'feature' => 'Go edge runtime para postback streaming concorrente e webhooks de alto volume',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('runtime', data_get($payload, 'placement.layer'));
        $this->assertSame('go_edge_concurrency', data_get($payload, 'placement.runtime'));
        $this->assertSame('go_edge', data_get($payload, 'implementation_contract.runtime_invocation_contract.selected_runtime_family'));
        $this->assertSame('go_edge_concurrency', data_get($payload, 'implementation_contract.runtime_invocation_contract.placement_runtime_alias'));
        $this->assertContains('runtimes/go', data_get($payload, 'implementation_contract.allowed_write_scopes'));
        $this->assertContains(
            'do_not_put_provider_policy_memory_or_domain_decision_inside_go_edge_runtime',
            data_get($payload, 'implementation_contract.forbidden_write_scopes'),
        );
        $this->assertContains(
            'go_edge_must_only_ingest_or_stream_events_and_must_not_decide_policy_provider_or_domain',
            $payload['risks'],
        );
    }

    public function test_place_feature_routes_swift_native_mac_to_swift_runtime_boundary(): void
    {
        $exit = Artisan::call('atlas:ai:place-feature', [
            'feature' => 'Swift Native Mac Keychain Touch ID ScreenCaptureKit FSEvents Accessibility API',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $ownerDocs = collect($payload['owner_docs'])->pluck('path')->all();

        $this->assertSame(0, $exit);
        $this->assertSame('runtime', data_get($payload, 'placement.layer'));
        $this->assertSame('swift_native_mac', data_get($payload, 'placement.runtime'));
        $this->assertSame('swift_native_mac', data_get($payload, 'implementation_contract.runtime_invocation_contract.selected_runtime_family'));
        $this->assertContains('runtimes/swift', data_get($payload, 'implementation_contract.allowed_write_scopes'));
        $this->assertContains(
            'do_not_capture_mic_screen_keychain_touchid_or_accessibility_without_kernel_policy_and_user_consent',
            data_get($payload, 'implementation_contract.forbidden_write_scopes'),
        );
        $this->assertContains('docs/engineering-knowledge-base/atlas-native-mac-agent.md', $ownerDocs);
        $this->assertContains(
            'swift_native_mac_requires_explicit_privacy_consent_eclipse_rules_and_kernel_receipt',
            $payload['risks'],
        );
    }

    public function test_docs_split_plan_turns_split_required_docs_into_operational_backlog(): void
    {
        $exit = Artisan::call('atlas:ai:docs-split-plan', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.documentation_split_plan.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertGreaterThanOrEqual(0, $payload['split_required_count']);
        $this->assertIsBool(data_get($payload, 'summary.ready_for_new_docs'));
        $this->assertIsArray($payload['recommended_actions']);
        $this->assertSame(
            'EngineeringDocumentationHealthService',
            data_get($payload, 'policy.line_limits_source'),
        );
        $this->assertContains(
            'atlas engineering knowledge docs-health',
            $payload['required_validation'],
        );
        $this->assertArrayHasKey('execution_order', $payload);
        $this->assertSame(
            'If a split_required doc is touched, the change must either reduce it or add a focused child spec and backlink.',
            data_get($payload, 'policy.growth_gate'),
        );
        if ($payload['split_required_count'] > 0) {
            $this->assertArrayHasKey('severity', $payload['docs'][0]);
            $this->assertArrayHasKey('owner_area', $payload['docs'][0]);
            $this->assertArrayHasKey('target_shape', $payload['docs'][0]);
            $this->assertArrayHasKey('owner_update', $payload['docs'][0]);
            $this->assertArrayHasKey('proposed_child_docs', $payload['docs'][0]);
            $this->assertArrayHasKey('migration_steps', $payload['docs'][0]);
            $this->assertContains('sync_and_index_code_are_rerun', $payload['docs'][0]['acceptance_criteria']);
        }
    }

    public function test_docs_split_plan_can_be_filtered_for_focused_ai_sessions(): void
    {
        $exit = Artisan::call('atlas:ai:docs-split-plan', [
            '--status' => 'split_required',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('split_required', data_get($payload, 'filters.status'));
        $this->assertGreaterThanOrEqual($payload['split_required_count'], $payload['total_split_required_count']);
        if ($payload['split_required_count'] > 0) {
            $this->assertNotEmpty($payload['docs']);
            $this->assertSame(
                ['split_required'],
                collect($payload['docs'])->pluck('status')->unique()->values()->all(),
            );
        } else {
            $this->assertSame([], $payload['docs']);
        }
    }

    public function test_docs_split_plan_human_output_exposes_execution_metadata(): void
    {
        $exit = Artisan::call('atlas:ai:docs-split-plan');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AI Docs Split Plan', $output);
        $this->assertStringContainsString('Ready for new docs', $output);
        $this->assertStringContainsString('Next', $output);
        $this->assertStringContainsString('severity', $output);
        $this->assertStringContainsString('owner', $output);
        $this->assertStringContainsString('first child doc', $output);
    }

    public function test_docs_split_plan_human_output_exposes_filters(): void
    {
        $exit = Artisan::call('atlas:ai:docs-split-plan', [
            '--owner' => 'kernel_architecture',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Filters', $output);
        $this->assertStringContainsString('owner=kernel_architecture', $output);
    }

    public function test_session_bootstrap_places_provider_releases_in_provider_evolution_flow(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'analisar Anthropic Finance Agents e decidir se Atlas Finance deve absorver',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('provider_evolution', data_get($payload, 'placement.layer'));
        $this->assertSame('finance', data_get($payload, 'placement.domain'));
        $this->assertSame('provider_evolution.review', data_get($payload, 'placement.flow'));
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md',
            collect($payload['owner_docs'])->pluck('path')->all(),
        );
        $this->assertContains(
            'provider_release_must_be_reviewed_before_routing_policy_or_domain_maturity_changes',
            $payload['risks'],
        );
        $this->assertContains(
            'run_provider_release_review_before_changing_routing',
            $payload['next_actions'],
        );
    }

    public function test_place_feature_treats_blackink_as_business_context_not_atlas_ai_domain(): void
    {
        $exit = Artisan::call('atlas:ai:place-feature', [
            'feature' => 'corrigir bug em producao da Blackink',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('programming', data_get($payload, 'placement.domain'));
        $this->assertSame('blackink', data_get($payload, 'placement.business_context'));
        $this->assertSame('context_not_atlas_ai_domain', data_get($payload, 'placement.business_context_role'));
        $this->assertSame('blackink', data_get($payload, 'implementation_contract.business_context'));
        $this->assertContains('keep_business_context_separate_from_atlas_ai_domain', $payload['pre_implementation_checklist']);
        $this->assertContains(
            'do_not_create_new_domain_for_business_context_without_formal_domain_onboarding',
            data_get($payload, 'implementation_contract.forbidden_write_scopes'),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-business-contexts.md',
            collect($payload['owner_docs'])->pluck('path')->all(),
        );
        $this->assertContains(
            'business_context_must_not_be_promoted_to_atlas_ai_domain_without_dedicated_runtime_gates_memory_and_evidence',
            $payload['risks'],
        );
    }

    public function test_place_feature_strict_mode_fails_when_gate_is_blocked(): void
    {
        $exit = Artisan::call('atlas:ai:place-feature', [
            'feature' => 'coisa generica sem owner claro',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', $payload['gate_status']);
        $this->assertContains(
            'ambiguous_placement_requires_more_specific_feature_or_hint',
            $payload['blocked_when'],
        );
    }

    public function test_session_bootstrap_strict_mode_fails_when_placement_gate_is_blocked(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'coisa generica sem owner claro',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', $payload['gate_status']);
        $this->assertTrue(data_get($payload, 'session_gate.strict_blocks_session'));
        $this->assertContains(
            'ambiguous_placement_requires_more_specific_feature_or_hint',
            $payload['blocked_when'],
        );
    }
}
