<?php

namespace Tests\Feature\Ai\RuntimeEfficiency;

use App\Models\AtlasRuntimeEfficiencyDecision;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

class AtlasRuntimeEfficiencyGovernorServiceTest extends TestCase
{
    use CreatesRuntimeEfficiencyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRuntimeEfficiencyTables();
    }

    protected function tearDown(): void
    {
        $this->dropRuntimeEfficiencyTables();
        parent::tearDown();
    }

    public function test_simple_conversation_uses_fast_path_and_skips_heavy_layers(): void
    {
        $payload = app(AtlasRuntimeEfficiencyGovernorService::class)->govern([
            'prompt' => 'oi',
            'domain' => 'conversation',
        ]);

        $this->assertSame(AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('fast_path', $payload['path']);
        $this->assertLessThanOrEqual(1200, $payload['context_budget_tokens']);
        $this->assertSame(0, $payload['tool_budget']);
        $this->assertTrue(collect($payload['layer_admissions'])->every(fn (array $layer): bool => $layer['admitted'] === false));
        $this->assertDatabaseCount('atlas_runtime_efficiency_decisions', 1);
    }

    public function test_programming_task_gets_context_minimum_pack_tools_and_outcome_layer(): void
    {
        $payload = app(AtlasRuntimeEfficiencyGovernorService::class)->govern([
            'prompt' => 'Corrija este bug no controller, rode testes e entregue evidencias.',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:areg:programming'],
        ]);
        $admitted = collect($payload['layer_admissions'])->where('admitted', true)->pluck('layer_id')->all();

        $this->assertContains($payload['path'], ['standard_path', 'deep_path']);
        $this->assertContains('apcr', $admitted);
        $this->assertContains('acie', $admitted);
        $this->assertContains('aemor', $admitted);
        $this->assertContains('focused_tests', data_get($payload, 'verification_plan.checks'));
        $this->assertContains('rg', data_get($payload, 'tool_policy.allowed_tool_groups'));
        $this->assertSame('atlas.context_minimum_pack.v1', data_get($payload, 'context_minimum_pack.schema_version'));
    }

    public function test_forge_work_gets_long_horizon_budget_and_subagents(): void
    {
        $payload = app(AtlasRuntimeEfficiencyGovernorService::class)->govern([
            'prompt' => 'Implemente uma Obra enterprise completa, auditavel, com milestones, receipts e certificacao final.',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'evidence_refs' => ['doc:forge', 'goal:obra'],
        ]);
        $admitted = collect($payload['layer_admissions'])->where('admitted', true)->pluck('layer_id')->all();

        $this->assertSame('forge_path', $payload['path']);
        $this->assertGreaterThanOrEqual(36000, $payload['context_budget_tokens']);
        $this->assertGreaterThanOrEqual(5, $payload['subagent_budget']);
        $this->assertContains('teos', $admitted);
        $this->assertContains('aemor', $admitted);
        $this->assertContains('milestone_gate', data_get($payload, 'verification_plan.checks'));
    }

    public function test_missing_evidence_on_risky_task_flags_undercontext(): void
    {
        $payload = app(AtlasRuntimeEfficiencyGovernorService::class)->govern([
            'prompt' => 'Analise minha carteira de investimento e decida o que vender.',
            'domain' => 'finance',
        ]);

        $this->assertSame('watch', $payload['status']);
        $this->assertContains('undercontext', collect($payload['efficiency_risks'])->pluck('kind')->all());
        $this->assertFalse(data_get($payload, 'tool_policy.external_side_effects_allowed'));
        $this->assertTrue(data_get($payload, 'tool_policy.requires_operator_approval'));
    }

    public function test_external_side_effect_request_blocks_before_provider_or_tools(): void
    {
        $payload = app(AtlasRuntimeEfficiencyGovernorService::class)->govern([
            'prompt' => 'Comprar e vender automaticamente ativos agora.',
            'domain' => 'finance',
            'external_execution_requested' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked_path', $payload['path']);
        $this->assertSame(false, data_get($payload, 'claim_policy.provider_invoked'));
        $this->assertSame(false, data_get($payload, 'claim_policy.external_execution_performed'));
    }

    public function test_overcontext_is_detected_without_exposing_raw_prompt_in_control_plane(): void
    {
        $rawPrompt = 'Prompt confidencial de eficiencia '.str_repeat('contexto ', 2000);
        app(AtlasRuntimeEfficiencyGovernorService::class)->govern([
            'prompt' => $rawPrompt,
            'domain' => 'programming',
            'context_refs' => array_map(fn (int $i): string => 'ctx:'.$i, range(1, 60)),
            'evidence_refs' => ['test:overcontext'],
        ]);

        $decision = AtlasRuntimeEfficiencyDecision::query()->firstOrFail();
        $this->assertContains('overcontext', collect($decision->efficiency_risks)->pluck('kind')->all());

        $controlPlane = app(AtlasRuntimeEfficiencyGovernorService::class)->controlPlane();
        $json = json_encode($controlPlane, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($rawPrompt, $json);
        $this->assertStringContainsString('prompt_hash', $json);
    }

    public function test_record_outcome_creates_learning_candidates(): void
    {
        $decision = app(AtlasRuntimeEfficiencyGovernorService::class)->govern([
            'prompt' => 'Corrija bug com testes.',
            'domain' => 'programming',
            'evidence_refs' => ['test:outcome'],
        ]);

        $outcome = app(AtlasRuntimeEfficiencyGovernorService::class)->recordOutcome([
            'decision_id' => $decision['decision_id'],
            'status' => 'watch',
            'quality_score' => 0.50,
            'context_roi_score' => 0.30,
            'signals' => ['rework_required' => true],
            'evidence_refs' => ['outcome:test'],
        ]);

        $this->assertSame('atlas.runtime_efficiency_outcome.v1', $outcome['schema_version']);
        $this->assertCount(3, $outcome['learning_candidates']);
        $this->assertDatabaseCount('atlas_runtime_efficiency_outcomes', 1);
    }

    public function test_record_outcome_can_emit_feedback_without_persisting(): void
    {
        $outcome = app(AtlasRuntimeEfficiencyGovernorService::class)->recordOutcome([
            'decision_id' => null,
            'status' => 'ready',
            'quality_score' => 0.88,
            'context_roi_score' => 0.79,
            'signals' => ['source' => 'assisted_execution_feedback'],
            'evidence_refs' => ['outcome:dry-run'],
            'persist' => false,
        ]);

        $this->assertSame('atlas.runtime_efficiency_outcome.v1', $outcome['schema_version']);
        $this->assertFalse($outcome['writes']);
        $this->assertNotEmpty($outcome['outcome_hash']);
        $this->assertDatabaseCount('atlas_runtime_efficiency_outcomes', 0);
    }

    public function test_counterfactual_replay_scores_alternate_paths_without_provider_calls(): void
    {
        $replay = app(AtlasRuntimeEfficiencyGovernorService::class)->counterfactualReplay([
            'prompt' => 'Corrija uma falha de produção com testes, rollback e evidências.',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:replay'],
        ]);

        $this->assertSame(AtlasRuntimeEfficiencyGovernorService::COUNTERFACTUAL_REPLAY_SCHEMA, $replay['schema_version']);
        $this->assertNotEmpty($replay['replay_hash']);
        $this->assertGreaterThanOrEqual(5, count($replay['candidates']));
        $this->assertNotEmpty($replay['winning_candidate']);
        $this->assertDatabaseCount('atlas_runtime_efficiency_replays', 1);
    }

    public function test_compile_policy_learns_from_outcomes_and_future_govern_uses_it(): void
    {
        $service = app(AtlasRuntimeEfficiencyGovernorService::class);

        foreach ([0.93, 0.91] as $quality) {
            $decision = $service->govern([
                'prompt' => 'Corrija bug pequeno com teste.',
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'evidence_refs' => ['test:policy'],
            ]);
            $service->recordOutcome([
                'decision_id' => $decision['decision_id'],
                'status' => 'ready',
                'quality_score' => $quality,
                'context_roi_score' => 0.81,
                'evidence_refs' => ['outcome:policy'],
            ]);
        }

        $policy = $service->compilePolicy([
            'flow_id' => 'atlas_dev',
            'domain' => 'programming',
            'min_samples' => 2,
        ]);
        $future = $service->govern([
            'prompt' => 'Corrija bug pequeno com teste.',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:future'],
        ]);

        $this->assertSame(AtlasRuntimeEfficiencyGovernorService::POLICY_SCHEMA, $policy['schema_version']);
        $this->assertSame('ready', $policy['status']);
        $this->assertSame('enforced', data_get($policy, 'policy_rules.enforcement_level'));
        $this->assertSame($policy['policy_hash'], data_get($future, 'adaptive_policy.policy_hash'));
        $this->assertSame('adaptive_policy', data_get($future, 'quality_prediction.source'));
        $this->assertDatabaseCount('atlas_runtime_efficiency_policies', 3);
    }

    public function test_record_outcome_feedback_compiles_policy_candidate(): void
    {
        $service = app(AtlasRuntimeEfficiencyGovernorService::class);
        $first = $service->govern([
            'prompt' => 'Planeje feature média com testes.',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:first'],
        ]);
        $service->recordOutcome([
            'decision_id' => $first['decision_id'],
            'quality_score' => 0.78,
            'context_roi_score' => 0.72,
            'evidence_refs' => ['outcome:first'],
        ]);
        $second = $service->govern([
            'prompt' => 'Planeje outra feature média com testes.',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:second'],
        ]);

        $outcome = $service->recordOutcome([
            'decision_id' => $second['decision_id'],
            'quality_score' => 0.80,
            'context_roi_score' => 0.74,
            'evidence_refs' => ['outcome:second'],
        ]);

        $this->assertSame(AtlasRuntimeEfficiencyGovernorService::POLICY_SCHEMA, data_get($outcome, 'compiled_policy.schema_version'));
        $this->assertSame('ready', data_get($outcome, 'compiled_policy.status'));
        $this->assertDatabaseCount('atlas_runtime_efficiency_policies', 2);
    }
}
