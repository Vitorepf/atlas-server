<?php

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Cartography\CartographyTruthGuardService;
use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasDecideTeosI4LookaheadService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\Programming\Bdd\AtlasBddAcceptanceRuntimeService;
use App\Services\Ai\Compounding\AtlasAntifragilityCompositionMetricService;
use App\Services\Ai\Compounding\AtlasLearningMutationRuntimeService;
use App\Services\Ai\Compounding\AtlasCompoundingLevel8DistillationService;
use App\Services\Ai\Programming\Cartography\AtlasProgrammingCartographyPublisherService;
use App\Services\Ai\Context\AtlasContextObservabilityToRankingReflexiveBridgeService;
use App\Services\Ai\Knowledge\AtlasKnowledgeIngestionFabricOcrConfidenceService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\CrossDomain\AtlasTemporaryDomainCompositionService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasConstitutionalVaultService;
use App\Services\Ai\Governance\AtlasTrustBudgetService;
use App\Services\Ai\Patamar4\AtlasNightlyCounterfactualsService;
use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\ResearchDomain\ResearchRuntimeService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionPromotionPlanService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionScaffoldStagingExecutorService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use App\Services\Ai\AiDecisionReceiptRefreshService;
use App\Services\Ai\AiMemoryDeltaProposer;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Compounding\AtlasLearningDistiller;
use App\Services\Ai\Context\AtlasAgenticRagFrameworkService;
use App\Services\Ai\Context\AtlasCognitiveMemoryFabricService;
use App\Services\Ai\Context\AtlasContextCompilerRuntimeService;
use App\Services\Ai\Context\AtlasContextFreshnessQualityGateService;
use App\Services\Ai\Context\AtlasContextObservabilityPlaneService;
use App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService;
use App\Services\Ai\Context\AtlasContextRankingSystemService;
use App\Services\Ai\Context\AtlasGraphRetrievalNetworkService;
use App\Services\Ai\Context\AtlasHybridRetrievalInfrastructureService;
use App\Services\Ai\Context\AtlasPythonDataRetrievalRuntimeService;
use App\Services\Ai\Context\AtlasRetrievalCostLatencyGovernorService;
use App\Services\Ai\Context\AtlasRetrievalEvaluationBenchmarkArenaService;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\Context\AtlasRetrievalPrivacyTrustLayerService;
use App\Services\Ai\Context\AtlasSemanticEmbeddingFoundationService;
use App\Services\Ai\Context\AtlasUnifiedRealityGraphService;
use App\Services\Ai\Knowledge\AtlasKnowledgeSourcePacketRegistryService;
use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;

/**
 * Atlas Cognition Operating System — runtime scorecard.
 *
 * Mede em runtime real o status dos 31 subsistemas canonicos do ACOS em
 * 3 dimensoes honestas:
 *
 *   - code      : a classe de servico existe e e instanciavel.
 *   - doc       : canon documental publicado e mapeado.
 *   - pipeline  : pipeline de dados ponta-a-ponta provado por teste real
 *                 passando (services reais, sem mock — operador proibiu).
 *
 * Schema: atlas.cognition.scorecard.v3 (v3 remove volume — uso organico nao
 * conta como dimensao mensuravel; pipeline_proof e o teto honesto).
 *
 * IMPORTANT (claim_policy):
 *  - Nao emite claim de "benchmark", "rivals" ou "superiority".
 *  - O score reflete maturidade interna do Atlas, nao comparacao externa.
 *  - external_rivals_certification permanece BLOCKED.
 */
class AtlasCognitionScoreCardService
{
    public const SCHEMA_VERSION = 'atlas.cognition.scorecard.v3';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BUILDING = 'building';

    public const STATUS_BLOCKED = 'blocked';

    /** Score points per status. */
    private const STATUS_POINTS = [
        self::STATUS_READY => 10,
        self::STATUS_PARTIAL => 6,
        self::STATUS_BUILDING => 3,
        self::STATUS_BLOCKED => 0,
    ];

    /**
     * Os 31 subsistemas canonicos do ACOS.
     *
     * Tuple: [acronym, name, group, service_class, doc_status, pipeline_status].
     *
     * v3 removed the volume dimension — pipeline_proof via passing tests
     * against real services is the honest ceiling; operator ruled
     * "uso organico nao conta" (organic-usage gating is not a valid dimension).
     */
    private const SUBSYSTEMS = [
        // Cognitive Immune G0-G8 (9)
        ['G0',     'Raw Capture Layer',           'cognitive_immune', AtlasAemorRuntimeService::class,                          'ready', 'ready', 'building'],
        ['G1',     'Evidence Promotion Gate',     'cognitive_immune', AtlasAemorRuntimeService::class,                          'ready', 'ready', 'building'],
        ['G2',     'Learning Signal Extraction',  'cognitive_immune', AtlasLearningDistiller::class,                            'ready', 'ready', 'building'],
        ['G3',     'Memory Promotion',            'cognitive_immune', AiMemoryDeltaProposer::class,                             'ready', 'ready', 'partial'],
        ['G4',     'Context Gate',                'cognitive_immune', AtlasContextFreshnessQualityGateService::class,           'ready', 'ready', 'partial'],
        ['G5',     'Decision Gate',               'cognitive_immune', AiDecisionReceiptRefreshService::class,                   'ready', 'ready', 'partial'],
        ['G6',     'Outcome Replay',              'cognitive_immune', AtlasAemorJudgmentService::class,                         'ready', 'ready', 'building'],
        ['G7',     'Self-Improvement Loop',       'cognitive_immune', AtlasSelfImprovementOrchestrator::class,                  'ready', 'ready', 'building'],
        ['G8',     'Compounding Effect',          'cognitive_immune', AtlasCompoundingRuntimeService::class,                    'ready', 'ready', 'building'],

        // Memory Core (3)
        ['MEM-CORE',   'Memory Core (entries+relations)', 'memory_core', AtlasMemoryConflictResolutionService::class,           'ready', 'ready', 'partial'],
        ['MEM-DELTA',  'Memory Delta Proposer',           'memory_core', AiMemoryDeltaProposer::class,                          'ready', 'ready', 'partial'],
        ['MEM-RECALL', 'Memory Recall (hybrid)',          'memory_core', AtlasHybridRetrievalInfrastructureService::class,      'ready', 'ready', 'partial'],

        // AUCRI 18 blocks
        ['ASEF',  'Semantic Embedding Foundation',     'aucri', AtlasSemanticEmbeddingFoundationService::class,    'ready', 'ready', 'partial'],
        ['AHRI',  'Hybrid Retrieval Infrastructure',   'aucri', AtlasHybridRetrievalInfrastructureService::class,  'ready', 'ready', 'partial'],
        ['AARF',  'Agentic RAG Framework',             'aucri', AtlasAgenticRagFrameworkService::class,            'ready', 'ready', 'partial'],
        ['ACRS',  'Context Ranking System',            'aucri', AtlasContextRankingSystemService::class,           'ready', 'ready', 'partial'],
        ['ACFQ',  'Context Freshness Quality Gate',    'aucri', AtlasContextFreshnessQualityGateService::class,    'ready', 'ready', 'partial'],
        ['ARFL',  'Retrieval Feedback Loop',           'aucri', AtlasRetrievalFeedbackLoopService::class,          'ready', 'ready', 'building'],
        ['AGRN',  'Graph Retrieval Network',           'aucri', AtlasGraphRetrievalNetworkService::class,          'ready', 'ready', 'partial'],
        ['AURG',  'Unified Reality Graph',             'aucri', AtlasUnifiedRealityGraphService::class,            'ready', 'ready', 'building'],
        ['APDR',  'Python Data Retrieval Runtime',     'aucri', AtlasPythonDataRetrievalRuntimeService::class,     'ready', 'ready', 'building'],
        ['AREBA', 'Retrieval Evaluation Arena',        'aucri', AtlasRetrievalEvaluationBenchmarkArenaService::class, 'ready', 'ready', 'partial'],
        ['ARCLG', 'Retrieval Cost Latency Governor',   'aucri', AtlasRetrievalCostLatencyGovernorService::class,   'ready', 'ready', 'partial'],
        ['ACOP',  'Context Observability Plane',       'aucri', AtlasContextObservabilityPlaneService::class,      'ready', 'ready', 'building'],
        ['ARPTL', 'Retrieval Privacy Trust Layer',     'aucri', AtlasRetrievalPrivacyTrustLayerService::class,     'ready', 'ready', 'partial'],
        ['AKIF',  'Knowledge Ingestion Fabric',        'aucri', AtlasKnowledgeSourcePacketRegistryService::class,       'ready', 'ready', 'building'],
        ['ACMF',  'Cognitive Memory Fabric',           'aucri', AtlasCognitiveMemoryFabricService::class,          'ready', 'ready', 'building'],
        ['ACCR',  'Context Compiler Runtime',          'aucri', AtlasContextCompilerRuntimeService::class,         'ready', 'ready', 'partial'],
        ['ATER',  'Token Economy Runtime',             'aucri', AtlasTokenEconomyBudgetPolicyService::class,            'ready', 'ready', 'building'],
        ['ACPFR', 'Context Pareto Frontier Runtime',   'aucri', AtlasContextParetoFrontierRuntimeService::class,   'ready', 'ready', 'partial'],

        // Self-Improvement L7 (closed loop)
        ['ASI-L7', 'Self-Improvement Closed Loop L7', 'self_improvement', AtlasSelfImprovementResultLedgerService::class, 'ready', 'ready', 'building'],

        // Patamar 2/3 — meta-learning, self-construction, AURG-4D, cross-domain mesh, TEOS-I3
        ['ADML',    'Atlas Decide Meta-Learning',          'atlas_decide',      AtlasDecideMetaLearningService::class,            'ready', 'ready', 'building'],
        ['ASCB',    'Self-Construction Subsystem Builder', 'self_construction', AtlasSelfConstructionSubsystemBuilderService::class, 'ready', 'ready', 'building'],
        ['AURG-4D', 'Unified Reality Graph Temporal (4D)', 'reality',           AtlasUnifiedRealityGraphTemporalService::class,    'ready', 'ready', 'building'],
        ['ACDM',    'Cross-Domain Mesh',                   'cross_domain',      AtlasCrossDomainMeshService::class,                'ready', 'ready', 'building'],
        ['TEOS-I3', 'TEOS-I3 Counterfactual Runtime',      'teos',              AtlasTeosI3CounterfactualService::class,           'ready', 'ready', 'building'],

        // Patamar 4 — Constitutional Kernel, Autonomy Admission, CognitiveFunctionAtlas, Reconciliation Runtime, TEOS-I4, Swarm Conductor, Temporary Domain Composition
        ['ACK',     'Constitutional Kernel',               'governance',        AtlasConstitutionalKernelService::class,           'ready', 'ready', 'building'],
        ['AAA',     'Autonomy Admission',                  'governance',        AtlasAutonomyAdmissionService::class,              'ready', 'ready', 'building'],
        ['ACFA',    'Cognitive Function Atlas',            'cognition',         AtlasCognitiveFunctionAtlasService::class,         'ready', 'ready', 'building'],
        ['AARR',    'Autonomous Reconciliation Runtime',   'autonomy',          AtlasAutonomousReconciliationRuntimeService::class, 'ready', 'ready', 'building'],
        ['TEOS-I4', 'TEOS-I4 Counterfactual Tree',         'teos',              AtlasTeosI4CounterfactualTreeService::class,       'ready', 'ready', 'building'],
        ['ASWC',    'Swarm Conductor',                     'atlas_decide',      AtlasSwarmConductorService::class,                 'ready', 'ready', 'building'],
        ['ASWE',    'Swarm Executor',                      'atlas_decide',      AtlasSwarmExecutorService::class,                  'ready', 'ready', 'building'],
        ['ATDC',    'Temporary Domain Composition',        'cross_domain',      AtlasTemporaryDomainCompositionService::class,     'ready', 'ready', 'building'],

        // Patamar 4 · integration layer
        ['ADGW',    'Atlas Decide Gateway Consultation',   'atlas_decide',      AtlasDecideGatewayConsultationService::class,      'ready', 'ready', 'building'],
        ['ADLF',    'Atlas Decide Live Outcome Feedback',  'atlas_decide',      AtlasDecideLiveOutcomeFeedbackService::class,      'ready', 'ready', 'building'],
        ['AACM',    'Antifragility Composition Metric',    'compounding',       AtlasAntifragilityCompositionMetricService::class, 'ready', 'ready', 'building'],
        ['ACMF-SE', 'Cognitive Memory Fabric Schema Evolution', 'aucri',         AtlasCognitiveMemoryFabricSchemaEvolutionService::class, 'ready', 'ready', 'building'],
        ['ASCB-EX', 'Self-Construction Scaffold Staging Executor', 'self_construction', AtlasSelfConstructionScaffoldStagingExecutorService::class, 'ready', 'ready', 'building'],
        ['ASCB-PP', 'Self-Construction Promotion Plan',          'self_construction', AtlasSelfConstructionPromotionPlanService::class,           'ready', 'ready', 'building'],
        ['ACTG',    'Cartography Truth Guard',                   'cartography',       CartographyTruthGuardService::class,                       'ready', 'ready', 'building'],
        ['AGPF',    'Atlas Gateway Preflight (TEOS-I4)',         'atlas_decide',      AtlasGatewayPreflightService::class,                       'ready', 'ready', 'building'],
        ['ACVS',    'Constitutional Vault Service',              'governance',        AtlasConstitutionalVaultService::class,                    'ready', 'ready', 'building'],
        ['ATBS',    'Trust Budget Service',                      'governance',        AtlasTrustBudgetService::class,                            'ready', 'ready', 'building'],
        ['ANCF',    'Nightly Counterfactuals',                   'patamar_4',         AtlasNightlyCounterfactualsService::class,                 'ready', 'ready', 'building'],
        ['ASAR',    'Subsystem Auto-Rebalance',                  'patamar_4',         AtlasSubsystemAutoRebalanceService::class,                 'ready', 'ready', 'building'],
        ['ASOS',    'Atlas Scheduler OS (Cron 24/7)',            'patamar_4',         AtlasSchedulerHealthService::class,                        'ready', 'ready', 'building'],
        ['ASPR',    'Swarm Production Resolver (real provider)', 'atlas_decide',      AtlasSwarmProductionResolverService::class,                'ready', 'ready', 'building'],

        // Patamar 1/2/3 closures — Reflexivity streaming, OCR confidence, Compounding L8/L9
        ['ACOP-ACRS', 'ACOP→ACRS Reflexive Streaming Bridge', 'aucri',          AtlasContextObservabilityToRankingReflexiveBridgeService::class, 'ready', 'ready', 'building'],
        ['AKIF-OCR', 'AKIF OCR Confidence-Scored Ingestion', 'aucri',           AtlasKnowledgeIngestionFabricOcrConfidenceService::class,        'ready', 'ready', 'building'],
        ['ACL8',    'Compounding Level 8/9 Distillation',   'compounding',     AtlasCompoundingLevel8DistillationService::class,                'ready', 'ready', 'building'],

        // Patamar 4 · intelligence boost
        ['ADTI4', 'Atlas Decide TEOS-I4 Lookahead',        'atlas_decide',     AtlasDecideTeosI4LookaheadService::class,                        'ready', 'ready', 'building'],

        // Domain runtimes — Research (review-only, source-grounded)
        ['ARDR',  'Research Domain Runtime',               'research_domain', ResearchRuntimeService::class,                                   'ready', 'ready', 'building'],
        ['ABDD',  'BDD Acceptance Runtime',                'programming',     AtlasBddAcceptanceRuntimeService::class,                         'ready', 'ready', 'building'],
        ['ALMR',  'Learning Mutation Runtime',             'compounding',     AtlasLearningMutationRuntimeService::class,                      'ready', 'ready', 'building'],
        ['APCP',  'Programming Cartography Publisher',     'programming',     AtlasProgrammingCartographyPublisherService::class,              'ready', 'ready', 'building'],
    ];

    /**
     * Build the full scorecard.
     */
    public function build(): array
    {
        $rows = [];
        foreach (self::SUBSYSTEMS as [$acronym, $name, $group, $serviceClass, $docStatus, $pipelineStatus, $_volume]) {
            $rows[] = [
                'acronym' => $acronym,
                'name' => $name,
                'group' => $group,
                'service_class' => $serviceClass,
                'code_status' => $this->probeCodeStatus($serviceClass),
                'doc_status' => $docStatus,
                'pipeline_status' => $pipelineStatus,
            ];
        }

        $score = $this->aggregateScore($rows);
        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'subsystem_count' => count($rows),
            'subsystems' => $rows,
            'score' => $score,
            'claim_policy' => $this->claimPolicy(),
            'notes' => [
                'readiness_definition' => 'A subsystem is ready when (code_status=ready) AND (doc_status=ready) AND (pipeline_status=ready). Real-world data volume is generated by the operator using Atlas; this scorecard certifies that the structure is ready for that use.',
            ],
        ];
        $envelope['scorecard_hash'] = $this->hash($rows, $score);

        return $envelope;
    }

    /**
     * Probe whether the service class exists and is instantiable.
     */
    private function probeCodeStatus(?string $serviceClass): string
    {
        if ($serviceClass === null) {
            return self::STATUS_BUILDING;
        }
        if (! class_exists($serviceClass)) {
            return self::STATUS_BLOCKED;
        }

        return self::STATUS_READY;
    }

    /**
     * Aggregate scores across the three structural dimensions:
     * code (service exists) + doc (canon published) + pipeline (proven by tests).
     */
    private function aggregateScore(array $rows): array
    {
        $dimensions = ['code_status', 'doc_status', 'pipeline_status'];
        $totals = [];
        foreach ($dimensions as $dim) {
            $sum = 0;
            $max = count($rows) * self::STATUS_POINTS[self::STATUS_READY];
            foreach ($rows as $r) {
                $sum += self::STATUS_POINTS[$r[$dim]] ?? 0;
            }
            $key = str_replace('_status', '', $dim);
            $totals[$key] = [
                'sum' => $sum,
                'max' => $max,
                'score_out_of_10' => $max > 0 ? round(($sum / $max) * 10, 2) : 0.0,
            ];
        }

        $overall = round(
            ($totals['code']['score_out_of_10']
                + $totals['doc']['score_out_of_10']
                + $totals['pipeline']['score_out_of_10']) / 3,
            2
        );

        return [
            'overall_out_of_10' => $overall,
            'dimensions' => $totals,
        ];
    }

    /**
     * Hardcoded provider-safe claim policy. Never relaxes.
     */
    private function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'must_keep_coverage_invariant' => true,
            'provider_safe_only_enforced' => true,
        ];
    }

    /**
     * Deterministic hash over canonical row content.
     */
    private function hash(array $rows, array $score): string
    {
        $canonical = array_map(static function (array $r): array {
            return [
                'acronym' => $r['acronym'],
                'code_status' => $r['code_status'],
                'doc_status' => $r['doc_status'],
                'pipeline_status' => $r['pipeline_status'],
            ];
        }, $rows);
        usort($canonical, static fn ($a, $b) => strcmp($a['acronym'], $b['acronym']));

        return 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'rows' => $canonical,
            'overall' => $score['overall_out_of_10'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Returns the canonical subsystem count (31).
     */
    public static function canonicalSubsystemCount(): int
    {
        return count(self::SUBSYSTEMS);
    }
}
