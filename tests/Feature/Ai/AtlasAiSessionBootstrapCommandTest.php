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
        $this->assertContains('provider_projection_status', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('ap_agent_workflow_registry', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('review_owner_docs', data_get($payload, 'session_gate.required_before_code'));
        $this->assertContains(
            'review_ap_agent_workflow_registry_when_touching_ap_or_architecture_governance',
            data_get($payload, 'session_gate.required_before_code'),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
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
        $this->assertSame(
            ['memory_open_brain'],
            collect(data_get($payload, 'docs_split_plan.execution_order', []))
                ->map(fn (string $path): string => str_contains($path, 'memory') || str_contains($path, 'open-brain') ? 'memory_open_brain' : 'other')
                ->unique()
                ->values()
                ->all(),
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
        $this->assertContains('app/Services/Ai/Surface', data_get($payload, 'implementation_contract.allowed_write_scopes'));
        $this->assertContains('surface_must_collect_input_and_render_output_only', data_get($payload, 'implementation_contract.forbidden_write_scopes'));
        $this->assertContains('review_duplicate_candidates_and_reuse_existing_capabilities_first', $payload['pre_implementation_checklist']);
        $this->assertContains(
            'possible_existing_capability_or_doc_overlap_review_duplicate_candidates_first',
            $payload['risks'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            collect($payload['duplicate_candidates'])->pluck('path')->all(),
        );
        $this->assertContains('repo_code', collect($payload['duplicate_candidates'])->pluck('source')->all());
        $this->assertContains(
            'high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision',
            $payload['blocked_when'],
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
        $this->assertGreaterThan(0, $payload['split_required_count']);
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
        $this->assertArrayHasKey('severity', $payload['docs'][0]);
        $this->assertArrayHasKey('owner_area', $payload['docs'][0]);
        $this->assertArrayHasKey('target_shape', $payload['docs'][0]);
        $this->assertArrayHasKey('owner_update', $payload['docs'][0]);
        $this->assertArrayHasKey('proposed_child_docs', $payload['docs'][0]);
        $this->assertArrayHasKey('migration_steps', $payload['docs'][0]);
        $this->assertContains('sync_and_index_code_are_rerun', $payload['docs'][0]['acceptance_criteria']);
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
        $this->assertNotEmpty($payload['docs']);
        $this->assertSame(
            ['split_required'],
            collect($payload['docs'])->pluck('status')->unique()->values()->all(),
        );
    }

    public function test_docs_split_plan_human_output_exposes_execution_metadata(): void
    {
        $exit = Artisan::call('atlas:ai:docs-split-plan');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AI Docs Split Plan', $output);
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
