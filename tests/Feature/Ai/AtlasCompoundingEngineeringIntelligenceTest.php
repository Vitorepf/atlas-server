<?php

namespace Tests\Feature\Ai;

use App\Models\AiRunOutcome;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Router\AtlasAiHyperflowRivalsBatteryService;
use App\Services\Ai\Router\AtlasAiRouterDecision;
use App\Services\Ai\Router\AtlasAiRouterService;
use App\Services\Ai\Router\AtlasAiSpecialistFlowExecutionService;
use App\Services\Ai\Router\AtlasAiSpecialistFlowRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasCompoundingEngineeringIntelligenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->bootCompoundingSchema();
    }

    protected function tearDown(): void
    {
        $this->dropBenchmarkSchema();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_command_runs_end_to_end_and_certifies_runtime_evidence(): void
    {
        $readinessExit = Artisan::call('atlas:ai:compounding', ['action' => 'readiness', '--json' => true]);
        $readiness = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $readinessExit);
        $this->assertSame('passed', $readiness['status']);
        $this->assertSame('atlas.ai.compounding.readiness.v1', $readiness['schema_version']);
        $this->assertContains('atlas_dev_records_compounding_outcome', array_column($readiness['checks'], 'id'));
        $this->assertContains('forge_handoff_carries_learning_bundle', array_column($readiness['checks'], 'id'));

        // T1.2: a fábrica de noise do gateway (recordCompoundingFlowSignal por interação)
        // foi removida; o contrato de readiness agora exige a AUSÊNCIA dela e a presença
        // do caminho real (conductor → AtlasCompoundingRuntimeService::recordExecution).
        $checksById = array_column($readiness['checks'], null, 'id');
        $this->assertArrayHasKey('gateway_does_not_fabricate_learning_signals', $checksById);
        $this->assertSame('passed', $checksById['gateway_does_not_fabricate_learning_signals']['status']);
        $this->assertArrayHasKey('conductor_feeds_compounding_runtime', $checksById);
        $this->assertSame('passed', $checksById['conductor_feeds_compounding_runtime']['status']);
        $this->assertArrayNotHasKey('gateway_records_flow_learning_signal', $checksById);

        $runExit = Artisan::call('atlas:ai:compounding', [
            'action' => 'run',
            '--run-id' => 'feature-compounding-run',
            '--flow-id' => 'atlas_debug',
            '--status' => 'failed',
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $runExit);
        $this->assertSame('recorded', $run['status']);
        $this->assertSame('atlas.ai.compounding.outcome.v1', data_get($run, 'outcome.schema_version'));
        $this->assertNotNull(data_get($run, 'compounding_memory.memory_hash'));
        $this->assertNotNull(data_get($run, 'rag_feedback.feedback_hash'));
        $this->assertNotNull(data_get($run, 'benchmark_case.case_hash'));

        $certifyExit = Artisan::call('atlas:ai:compounding', ['action' => 'certify', '--json' => true]);
        $certification = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $certifyExit);
        $this->assertSame('passed', $certification['status']);
        $this->assertSame([], data_get($certification, 'runtime_evidence.blockers'));
        $this->assertGreaterThanOrEqual(1, data_get($certification, 'runtime_evidence.counts.outcomes'));
    }

    public function test_router_consumes_approved_compounding_memory(): void
    {
        app(AtlasCompoundingRuntimeService::class)->recordExecution($this->runtimePayload('router-memory', 'atlas_debug'));

        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'o endpoint de billing quebrou, faça debug do erro',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEBUG, $decision->flowId);
        $this->assertNotEmpty(data_get($decision->handoffPayload, 'compounding_memories'));
        $this->assertSame('debug_memory', data_get($decision->handoffPayload, 'compounding_memories.0.memory_type'));
    }

    public function test_specialist_flows_emit_learning_signal_contracts(): void
    {
        $data = [
            'input_text' => 'pesquise a melhor arquitetura para o router',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_ai_router' => [
                    'flow_id' => 'atlas_research',
                    'flow_origin' => 'router_auto',
                    'command_intent' => 'research',
                    'routing_reason' => 'research_like_intent',
                    'handoff_payload' => [
                        'surface_id' => 'atlas_desktop_ai',
                        'workspace_present' => false,
                    ],
                ],
            ],
        ];

        $withRuntime = app(AtlasAiSpecialistFlowRuntimeService::class)->apply($data);
        $withExecution = app(AtlasAiSpecialistFlowExecutionService::class)->apply($withRuntime);

        $this->assertSame(
            'atlas.ai.compounding.flow_learning_signal_contract.v1',
            data_get($withExecution, 'payload.specialist_flow_execution.learning_signal_contract.schema_version'),
        );
        $this->assertTrue(data_get($withExecution, 'payload.specialist_flow_execution.learning_signal_contract.emits_learning_signal'));
        $this->assertContains('evidence_refs', data_get($withExecution, 'payload.specialist_flow_execution.learning_signal_contract.required_fields'));
    }

    public function test_hyperflow_run_persists_compounding_outcome_receipt_when_runtime_tables_exist(): void
    {
        $this->bootBenchmarkSchema();

        $service = app(AtlasAiHyperflowRivalsBatteryService::class);
        $prepare = $service->prepare();
        $run = $service->runAndPersist(['triggered_by' => 'compounding_feature_test']);

        $this->assertSame('prepared', $prepare['status']);
        $this->assertSame('recorded', data_get($run, 'compounding_outcome.status'));
        $this->assertTrue(data_get($run, 'compounding_outcome.writes'));
        $this->assertTrue(AiRunOutcome::query()->where('run_id', 'like', 'hyperflow:%')->exists());
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePayload(string $runId, string $flowId): array
    {
        return [
            'run_id' => $runId,
            'flow_id' => $flowId,
            'outcome_status' => 'failed',
            'flow_quality' => 60,
            'retrieval_quality' => 55,
            'execution_quality' => 58,
            'evidence_quality' => 92,
            'evidence_refs' => ['receipt:'.$runId, 'test:feature-compounding'],
            'learning_signal' => [
                'claim' => 'Debug prompts with workspace need prior failure memory in the router handoff.',
                'memory_type' => 'debug_memory',
                'scope' => 'atlas-server',
                'confidence' => 88,
                'flow_id' => $flowId,
                'evidence_refs' => ['receipt:'.$runId, 'test:feature-compounding'],
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => 'retr_'.$runId,
                'included_sources' => 3,
                'used_sources' => 2,
                'noise_sources' => 1,
                'context_sufficiency' => 80,
                'post_execution_utility' => 78,
            ],
        ];
    }

    private function bootCompoundingSchema(): void
    {
        $this->dropCompoundingSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function bootBenchmarkSchema(): void
    {
        $this->dropBenchmarkSchema();
        (require database_path('migrations/2026_05_17_154000_repair_missing_atlas_engineering_benchmark_tables.php'))->up();
    }

    private function dropBenchmarkSchema(): void
    {
        foreach ([
            'atlas_engineering_benchmark_results',
            'atlas_engineering_benchmark_runs',
            'atlas_engineering_benchmark_cases',
            'atlas_engineering_benchmark_suites',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
