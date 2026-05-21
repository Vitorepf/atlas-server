<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasAucriOptimizationAuditService
{
    public const SCHEMA_VERSION = 'atlas.aucri.optimization_audit.v1';

    private const STATUS_READY = 'ready';

    private const STATUS_BLOCKED = 'blocked';

    private const STATUS_PARTIAL = 'partial';

    /**
     * @var array<int, array{block:int, acronym:string, path:string}>
     */
    private const BLOCK_DOCS = [
        ['block' => 1, 'acronym' => 'ASEF', 'path' => 'docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md'],
        ['block' => 2, 'acronym' => 'AHRI', 'path' => 'docs/engineering-knowledge-base/atlas-hybrid-retrieval-infrastructure.md'],
        ['block' => 3, 'acronym' => 'AARF', 'path' => 'docs/engineering-knowledge-base/atlas-agentic-rag-framework.md'],
        ['block' => 4, 'acronym' => 'ACRS', 'path' => 'docs/engineering-knowledge-base/atlas-context-ranking-system.md'],
        ['block' => 5, 'acronym' => 'ACFQ', 'path' => 'docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md'],
        ['block' => 6, 'acronym' => 'ARFL', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md'],
        ['block' => 7, 'acronym' => 'AGRN', 'path' => 'docs/engineering-knowledge-base/atlas-graph-retrieval-network.md'],
        ['block' => 8, 'acronym' => 'AURG', 'path' => 'docs/engineering-knowledge-base/atlas-unified-reality-graph.md'],
        ['block' => 9, 'acronym' => 'APDR', 'path' => 'docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md'],
        ['block' => 10, 'acronym' => 'AREBA', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md'],
        ['block' => 11, 'acronym' => 'ARCLG', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md'],
        ['block' => 12, 'acronym' => 'ACOP', 'path' => 'docs/engineering-knowledge-base/atlas-context-observability-plane.md'],
        ['block' => 13, 'acronym' => 'ARPTL', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md'],
        ['block' => 14, 'acronym' => 'AKIF', 'path' => 'docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md'],
        ['block' => 15, 'acronym' => 'ACMF', 'path' => 'docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md'],
        ['block' => 16, 'acronym' => 'ACCR', 'path' => 'docs/engineering-knowledge-base/atlas-context-compiler-runtime.md'],
        ['block' => 17, 'acronym' => 'ATER', 'path' => 'docs/engineering-knowledge-base/atlas-token-economy-runtime.md'],
        ['block' => 18, 'acronym' => 'ACPFR', 'path' => 'docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md'],
    ];

    public function __construct(private readonly ?string $repoRoot = null) {}

    /**
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        $checks = [
            $this->checkMotherIndex(),
            $this->checkAllBlockDocsExist(),
            $this->checkOptimizationProtocol(),
            $this->checkTokenQualityGuards(),
            $this->checkCompilerLossAccounting(),
            $this->checkMemoryReservePolicy(),
            $this->checkEvaluationAndObservability(),
            $this->checkPrivacyAndIngestionLineage(),
            $this->checkSemanticEmbeddingFoundationRuntimeSurface(),
            $this->checkHybridRetrievalInfrastructureRuntimeSurface(),
            $this->checkAgenticRagFrameworkRuntimeSurface(),
            $this->checkContextRankingSystemRuntimeSurface(),
            $this->checkContextFreshnessQualityGateRuntimeSurface(),
            $this->checkRetrievalFeedbackLoopRuntimeSurface(),
            $this->checkGraphRetrievalNetworkRuntimeSurface(),
            $this->checkUnifiedRealityGraphRuntimeSurface(),
            $this->checkPythonDataRetrievalRuntimeSurface(),
            $this->checkRetrievalEvaluationBenchmarkArenaRuntimeSurface(),
            $this->checkRetrievalCostLatencyGovernorRuntimeSurface(),
            $this->checkContextObservabilityPlaneRuntimeSurface(),
            $this->checkRetrievalPrivacyTrustLayerRuntimeSurface(),
            $this->checkKnowledgeIngestionFabricRuntimeSurface(),
            $this->checkCognitiveMemoryFabricRuntimeSurface(),
            $this->checkContextCompilerRuntimeSurface(),
            $this->checkTokenEconomyRuntimeSurface(),
            $this->checkParetoFrontierOptimization(),
            $this->checkParetoFrontierRuntimeSurface(),
            $this->checkRuntimeEnforcementWiredIntoProgrammingFlows(),
        ];

        $criticalFailures = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'
                && ($check['severity'] ?? 'critical') === 'critical',
        ));
        $warnings = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'
                && ($check['severity'] ?? 'critical') === 'warn',
        ));

        $status = match (true) {
            $criticalFailures !== [] => self::STATUS_BLOCKED,
            $warnings !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total' => count($checks),
                'passed' => count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'passed')),
                'critical_failed' => count($criticalFailures),
                'warn_failed' => count($warnings),
                'aucri_blocks' => count(self::BLOCK_DOCS),
            ],
            'checks' => $checks,
            'remaining_blockers' => array_values(array_map(
                static fn (array $check): string => (string) ($check['id'] ?? 'unknown'),
                $criticalFailures,
            )),
            'claims' => [
                'runtime_implemented' => true,
                'programming_flow_enforced' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'scope' => 'documentation_runtime_surface_and_programming_enforcement_audit',
            ],
            'next_actions' => [
                'Persistir receipts somente onde cada bloco exigir estado duravel.',
                'Ampliar AUCRI para demais flows nao-programming depois de Dev/Forge.',
                'Manter benchmark externo bloqueado ate gate humano separado.',
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['audit_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMotherIndex(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md';
        $contents = $this->contents($path);
        $missing = [];

        foreach (self::BLOCK_DOCS as $doc) {
            if (! str_contains($contents, '| '.$doc['block'].' | '.$doc['acronym'].' |')) {
                $missing[] = $doc['acronym'];
            }
        }

        return $this->check(
            'mother_index_lists_18_blocks',
            $missing === [],
            'critical',
            ['path' => $path, 'missing_blocks' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAllBlockDocsExist(): array
    {
        $missing = array_values(array_filter(
            self::BLOCK_DOCS,
            fn (array $doc): bool => ! is_file($this->absolute($doc['path'])),
        ));

        return $this->check(
            'all_18_block_docs_exist',
            $missing === [],
            'critical',
            [
                'expected_count' => count(self::BLOCK_DOCS),
                'missing' => array_column($missing, 'path'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkOptimizationProtocol(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md';
        $contents = $this->contents($path);
        $tokens = [
            'Context Ablation Testing',
            'Semantic Redundancy Removal',
            'Prompt Distillation',
            'Local Tool Substitution',
            'Provider-Aware Packing',
            'Segment ROI Scoring',
            'Regression Canary Set',
            'must_keep_coverage',
        ];

        return $this->checkTokens('continuous_optimization_protocol_present', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTokenQualityGuards(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-token-economy-runtime.md';
        $contents = $this->contents($path);
        $tokens = [
            'must_keep_coverage = 1.0',
            'quality_gate_status',
            'loss_score',
            'Atlas Quality Token Check',
            'Atlas Local Pre-Reasoning Runtime',
            'Atlas Provider Model Selector',
        ];

        return $this->checkTokens('token_quality_guards_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCompilerLossAccounting(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-context-compiler-runtime.md';
        $contents = $this->contents($path);
        $tokens = [
            'atlas.context.loss_check.v1',
            'Provider Profile Registry',
            'Must-Keep Preserver',
            'Prompt Budget Allocator',
            'Loss Accounting Engine',
            'Compiled Pack Hasher',
        ];

        return $this->checkTokens('compiler_loss_accounting_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMemoryReservePolicy(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md';
        $contents = $this->contents($path);
        $tokens = [
            'reserva absoluta do usuario: 3GB',
            'Atlas Memory Pressure Governor',
            'emergency_trim',
            'Atlas Context Delta Engine',
            'Atlas Snapshot & Memory Spillover',
        ];

        return $this->checkTokens('memory_reserve_policy_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEvaluationAndObservability(): array
    {
        $arena = $this->contents('docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md');
        $observability = $this->contents('docs/engineering-knowledge-base/atlas-context-observability-plane.md');
        $missing = [];

        foreach (['golden sets', 'groundedness', 'context ROI', 'regressao'] as $token) {
            if (! str_contains($arena, $token)) {
                $missing[] = "AREBA:{$token}";
            }
        }

        foreach (['misses', 'ruido', 'blockers', 'sem texto cru'] as $token) {
            if (! str_contains($observability, $token)) {
                $missing[] = "ACOP:{$token}";
            }
        }

        return $this->check(
            'evaluation_and_observability_support_token_quality',
            $missing === [],
            'critical',
            ['missing_tokens' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPrivacyAndIngestionLineage(): array
    {
        $privacy = $this->contents('docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md');
        $ingestion = $this->contents('docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md');
        $missing = [];

        foreach (['provider-safe', 'redaction', 'retention', 'trust receipt'] as $token) {
            if (! str_contains($privacy, $token)) {
                $missing[] = "ARPTL:{$token}";
            }
        }

        foreach (['source packet', 'lineage', 'confidence', 'source_hash'] as $token) {
            if (! str_contains($ingestion, $token)) {
                $missing[] = "AKIF:{$token}";
            }
        }

        return $this->check(
            'privacy_and_ingestion_lineage_defined',
            $missing === [],
            'critical',
            ['missing_tokens' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkParetoFrontierOptimization(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md';
        $contents = $this->contents($path);
        $tokens = [
            'Pareto frontier search',
            'Marginal utility per token',
            'Constrained utility function',
            'Shadow multi-armed bandit',
            'must_keep_coverage < 1.0',
            'Pareto-dominado',
        ];

        return $this->checkTokens('pareto_frontier_optimization_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkHybridRetrievalInfrastructureRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasHybridRetrievalInfrastructureService.php',
            'app/Console/Commands/AtlasHybridRetrievalInfrastructureCommand.php',
            'tests/Feature/Ai/Context/HybridRetrievalInfrastructureTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'hybrid_retrieval_infrastructure_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSemanticEmbeddingFoundationRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasSemanticEmbeddingFoundationService.php',
            'app/Console/Commands/AtlasSemanticEmbeddingFoundationCommand.php',
            'tests/Feature/Ai/Context/SemanticEmbeddingFoundationTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'semantic_embedding_foundation_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAgenticRagFrameworkRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasAgenticRagFrameworkService.php',
            'app/Console/Commands/AtlasAgenticRagFrameworkCommand.php',
            'tests/Feature/Ai/Context/AgenticRagFrameworkTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'agentic_rag_framework_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContextRankingSystemRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasContextRankingSystemService.php',
            'app/Console/Commands/AtlasContextRankingSystemCommand.php',
            'tests/Feature/Ai/Context/ContextRankingSystemTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'context_ranking_system_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContextFreshnessQualityGateRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasContextFreshnessQualityGateService.php',
            'app/Console/Commands/AtlasContextFreshnessQualityGateCommand.php',
            'tests/Feature/Ai/Context/ContextFreshnessQualityGateTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'context_freshness_quality_gate_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRetrievalFeedbackLoopRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php',
            'app/Console/Commands/AtlasRetrievalFeedbackLoopCommand.php',
            'tests/Feature/Ai/Context/RetrievalFeedbackLoopTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'retrieval_feedback_loop_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkGraphRetrievalNetworkRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php',
            'app/Console/Commands/AtlasGraphRetrievalNetworkCommand.php',
            'tests/Feature/Ai/Context/GraphRetrievalNetworkTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'graph_retrieval_network_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkUnifiedRealityGraphRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasUnifiedRealityGraphService.php',
            'app/Console/Commands/AtlasUnifiedRealityGraphCommand.php',
            'tests/Feature/Ai/Context/UnifiedRealityGraphTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'unified_reality_graph_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPythonDataRetrievalRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasPythonDataRetrievalRuntimeService.php',
            'app/Console/Commands/AtlasPythonDataRetrievalRuntimeCommand.php',
            'tests/Feature/Ai/Context/PythonDataRetrievalRuntimeTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'python_data_retrieval_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRetrievalEvaluationBenchmarkArenaRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasRetrievalEvaluationBenchmarkArenaService.php',
            'app/Console/Commands/AtlasRetrievalEvaluationBenchmarkArenaCommand.php',
            'tests/Feature/Ai/Context/RetrievalEvaluationBenchmarkArenaTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'retrieval_evaluation_benchmark_arena_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRetrievalCostLatencyGovernorRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasRetrievalCostLatencyGovernorService.php',
            'app/Console/Commands/AtlasRetrievalCostLatencyGovernorCommand.php',
            'tests/Feature/Ai/Context/RetrievalCostLatencyGovernorTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'retrieval_cost_latency_governor_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContextObservabilityPlaneRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasContextObservabilityPlaneService.php',
            'app/Console/Commands/AtlasContextObservabilityPlaneCommand.php',
            'tests/Feature/Ai/Context/ContextObservabilityPlaneTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'context_observability_plane_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRetrievalPrivacyTrustLayerRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasRetrievalPrivacyTrustLayerService.php',
            'app/Console/Commands/AtlasRetrievalPrivacyTrustLayerCommand.php',
            'tests/Feature/Ai/Context/RetrievalPrivacyTrustLayerTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'retrieval_privacy_trust_layer_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkKnowledgeIngestionFabricRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasKnowledgeIngestionFabricService.php',
            'app/Console/Commands/AtlasKnowledgeIngestionFabricCommand.php',
            'tests/Feature/Ai/Context/KnowledgeIngestionFabricTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'knowledge_ingestion_fabric_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCognitiveMemoryFabricRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasCognitiveMemoryFabricService.php',
            'app/Console/Commands/AtlasCognitiveMemoryFabricCommand.php',
            'tests/Feature/Ai/Context/CognitiveMemoryFabricTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'cognitive_memory_fabric_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContextCompilerRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasContextCompilerRuntimeService.php',
            'app/Console/Commands/AtlasContextCompilerRuntimeCommand.php',
            'tests/Feature/Ai/Context/ContextCompilerRuntimeTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'context_compiler_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTokenEconomyRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasTokenEconomyRuntimeService.php',
            'app/Console/Commands/AtlasTokenEconomyRuntimeCommand.php',
            'tests/Feature/Ai/Context/TokenEconomyRuntimeTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'token_economy_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkParetoFrontierRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasContextParetoFrontierRuntimeService.php',
            'app/Console/Commands/AtlasContextParetoFrontierCommand.php',
            'tests/Feature/Ai/Context/ContextParetoFrontierRuntimeTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'pareto_frontier_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRuntimeEnforcementWiredIntoProgrammingFlows(): array
    {
        $expectations = [
            'app/Services/Ai/Context/AtlasAucriRuntimeEnforcementService.php' => [
                'atlas.aucri.runtime_enforcement.v1',
                'enforced_before_provider_call',
                'block_refs',
                'all_18_aucri_blocks_executed',
                'ASEF',
                'AHRI',
                'AARF',
                'ACRS',
                'ACFQ',
                'ARFL',
                'AGRN',
                'AURG',
                'APDR',
                'AREBA',
                'ARCLG',
                'ACOP',
                'ARPTL',
                'AKIF',
                'ACMF',
                'ACCR',
                'ATER',
                'ACPFR',
            ],
            'app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php' => [
                'enforceAucriBeforeProvider',
                'blockedDueToAucri',
                'AtlasAucriRuntimeEnforcementService',
            ],
            'app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php' => [
                'stageAucriRuntimeEnforcement',
                'aucri_runtime_enforcement',
                'AtlasAucriRuntimeEnforcementService',
            ],
            'tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php' => [
                'test_aucri_runtime_enforcement_blocks_sensitive_prompt_before_provider_call',
            ],
            'tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php' => [
                'test_live_execution_runs_aucri_enforcement_before_patch_execution',
            ],
        ];

        $missing = [];
        foreach ($expectations as $path => $tokens) {
            $contents = $this->contents($path);
            if ($contents === '') {
                $missing[] = $path;

                continue;
            }

            foreach ($tokens as $token) {
                if (! str_contains($contents, $token)) {
                    $missing[] = $path.':'.$token;
                }
            }
        }

        return $this->check(
            'runtime_enforcement_wired_into_programming_flows',
            $missing === [],
            'critical',
            [
                'flows' => ['atlas_dev', 'atlas_forge'],
                'missing' => $missing,
            ],
        );
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<string,mixed>
     */
    private function checkTokens(string $id, string $path, string $contents, array $tokens): array
    {
        $missing = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! str_contains($contents, $token),
        ));

        return $this->check($id, $missing === [], 'critical', [
            'path' => $path,
            'missing_tokens' => $missing,
        ]);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, string $severity, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'severity' => $severity,
            'evidence' => $evidence,
        ];
    }

    private function contents(string $path): string
    {
        $absolute = $this->absolute($path);

        return is_file($absolute) ? (string) file_get_contents($absolute) : '';
    }

    private function absolute(string $path): string
    {
        return rtrim($this->repoRoot ?? base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }
}
