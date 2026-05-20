<?php

namespace Tests\Feature\Ai\StrategicReality;

use App\Models\AtlasStrategicDecision;
use App\Services\Ai\StrategicReality\AtlasStrategicRealityRuntimeService;
use Tests\Concerns\CreatesStrategicRealityTables;
use Tests\TestCase;

class AtlasStrategicRealityRuntimeServiceTest extends TestCase
{
    use CreatesStrategicRealityTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategicRealityTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategicRealityTables();
        parent::tearDown();
    }

    public function test_decide_records_full_strategic_chain_with_evidence(): void
    {
        $payload = app(AtlasStrategicRealityRuntimeService::class)->decide([
            'question' => 'Qual proxima decisao para evoluir Atlas Intelligence Factory OS?',
            'domain' => 'strategy',
            'entities' => [
                ['type' => 'company', 'name' => 'Atlas'],
                ['type' => 'project', 'name' => 'Atlas Intelligence Factory OS'],
            ],
            'evidence_refs' => ['doc:atlas-intelligence-factory-os', 'cert:asre:test'],
        ]);

        $this->assertSame(AtlasStrategicRealityRuntimeService::DECISION_SCHEMA, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) $payload['writes']);
        $this->assertNotEmpty($payload['decision_id']);
        $this->assertNotEmpty($payload['briefing_id']);
        $this->assertNotEmpty($payload['decision_hash']);
        $this->assertSame(false, data_get($payload, 'claim_policy.provider_invoked'));
        $this->assertSame(false, data_get($payload, 'claim_policy.external_execution_performed'));

        $this->assertDatabaseCount('atlas_reality_entities', 2);
        $this->assertDatabaseCount('atlas_reality_relationships', 1);
        $this->assertDatabaseCount('atlas_strategic_assumptions', 3);
        $this->assertDatabaseCount('atlas_opportunity_signals', 1);
        $this->assertDatabaseCount('atlas_risk_signals', 1);
        $this->assertDatabaseCount('atlas_priority_rankings', 1);
        $this->assertDatabaseCount('atlas_resource_allocation_plans', 1);
        $this->assertDatabaseCount('atlas_strategic_simulations', 1);
        $this->assertDatabaseCount('atlas_strategic_decisions', 1);
        $this->assertDatabaseCount('atlas_executive_briefings', 1);
    }

    public function test_missing_evidence_triggers_freshness_watch(): void
    {
        $payload = app(AtlasStrategicRealityRuntimeService::class)->decide([
            'question' => 'Qual e a melhor proxima acao para uma decisao estrategica nova?',
            'domain' => 'strategy',
        ]);

        $this->assertSame('watch', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'freshness.status'));
        $this->assertContains('evidence_refs', data_get($payload, 'freshness.missing_required_sources'));
        $this->assertContains('attach_evidence_refs', $payload['next_actions']);
    }

    public function test_financial_or_external_action_is_blocked_and_never_executes(): void
    {
        $payload = app(AtlasStrategicRealityRuntimeService::class)->decide([
            'question' => 'Comprar e vender automaticamente ativos em day trade agora.',
            'domain' => 'finance',
            'evidence_refs' => ['policy:financial-governance'],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('critical', data_get($payload, 'risk.severity'));
        $this->assertStringContainsString('approval', $payload['recommended_action']);
        $this->assertSame(false, data_get($payload, 'claim_policy.external_execution_performed'));
        $this->assertSame(false, data_get($payload, 'claim_policy.provider_invoked'));
        $this->assertContains('define_rollback_plan', $payload['next_actions']);
    }

    public function test_control_plane_aggregates_without_raw_question(): void
    {
        $question = 'Qual foco devo priorizar para fechar ASRE com evidencia?';
        app(AtlasStrategicRealityRuntimeService::class)->decide([
            'question' => $question,
            'domain' => 'strategy',
            'evidence_refs' => ['test:asre:control-plane'],
        ]);

        $payload = app(AtlasStrategicRealityRuntimeService::class)->controlPlane();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.strategic_reality.control_plane.v1', $payload['schema_version']);
        $this->assertSame(1, data_get($payload, 'summary.strategic_decisions_total'));
        $this->assertNotEmpty(data_get($payload, 'recent_decisions.0.question_hash'));
        $this->assertStringNotContainsString($question, $json);
    }

    public function test_decision_row_keeps_question_hash_and_evidence_refs(): void
    {
        $payload = app(AtlasStrategicRealityRuntimeService::class)->decide([
            'question' => 'Qual decisao estrategica maximiza compounding?',
            'domain' => 'strategy',
            'evidence_refs' => ['doc:asre', 'outcome:aemor'],
        ]);

        $decision = AtlasStrategicDecision::query()->findOrFail($payload['decision_id']);

        $this->assertNotSame('', $decision->question_hash);
        $this->assertSame(['doc:asre', 'outcome:aemor'], $decision->evidence_refs);
        $this->assertSame($payload['decision_hash'], $decision->decision_hash);
    }

    public function test_decide_records_sanitized_context_signals_from_internal_layers(): void
    {
        $payload = app(AtlasStrategicRealityRuntimeService::class)->decide([
            'question' => 'Qual decisao estrategica devo tomar com APCR, AEMOR e ASEIF?',
            'domain' => 'strategy',
            'evidence_refs' => ['doc:asre', 'receipt:router'],
            'context_signals' => [
                'persistent_context' => [
                    'schema_version' => 'atlas.persistent_context.runtime.v1',
                    'status' => 'ready',
                    'persistent_context_hash' => str_repeat('a', 64),
                    'raw_context' => 'nao deve vazar',
                ],
                'aemor' => [
                    'schema_version' => 'atlas.aemor.execution_episode.v1',
                    'status' => 'open',
                    'episode_hash' => str_repeat('b', 64),
                ],
                'intelligence_factory' => [
                    'schema_version' => 'atlas.intelligence_factory.advice.v1',
                    'status' => 'ready',
                    'advice_hash' => str_repeat('c', 64),
                ],
            ],
        ]);

        $this->assertCount(3, $payload['context_signals']);
        $this->assertSame(['persistent_context', 'aemor', 'intelligence_factory'], collect($payload['context_signals'])->pluck('source')->all());
        $this->assertNotEmpty(data_get($payload, 'context_signals.0.signal_hash'));

        $decision = AtlasStrategicDecision::query()->findOrFail($payload['decision_id']);
        $encodedRealityScope = json_encode($decision->reality_scope, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('context_signal_hashes', $encodedRealityScope);
        $this->assertStringNotContainsString('nao deve vazar', $encodedRealityScope);
    }
}
