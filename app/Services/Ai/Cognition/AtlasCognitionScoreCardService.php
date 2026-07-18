<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

use App\Services\Ai\Aemor\AtlasAemorCertificationService;
use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\AiDecisionReceiptRefreshService;
use App\Services\Ai\AiMemoryDeltaProposer;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\AtlasDecide\AtlasCognitiveFunctionSwarmRouterService;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasDecideTeosI4LookaheadService;
use App\Services\Ai\AtlasDecide\AtlasSwarmAutoFailoverService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmParallelDispatchService;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\Cartography\CartographyTruthGuardService;
use App\Services\Ai\Compounding\AtlasAntifragilityCompositionMetricService;
use App\Services\Ai\Compounding\AtlasCompoundingLevel8DistillationService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Compounding\AtlasLearningDistiller;
use App\Services\Ai\Compounding\AtlasLearningMutationRuntimeService;
use App\Services\Ai\Context\AtlasAgenticRagFrameworkService;
use App\Services\Ai\Context\AtlasCognitiveMemoryFabricService;
use App\Services\Ai\Context\AtlasContextCacheCompilerRuntimeService;
use App\Services\Ai\Context\AtlasContextCompilerRuntimeService;
use App\Services\Ai\Context\AtlasContextFreshnessQualityGateService;
use App\Services\Ai\Context\AtlasContextObservabilityPlaneService;
use App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService;
use App\Services\Ai\Context\AtlasContextQualityCertificationService;
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
use App\Services\Ai\ContextIntelligence\AtlasContextOperationsRuntimeService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\CrossDomain\AtlasTemporaryDomainCompositionService;
use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasConstitutionalVaultService;
use App\Services\Ai\Governance\AtlasTrustBudgetService;
use App\Services\Ai\Knowledge\AtlasKnowledgeIngestionFabricOcrConfidenceService;
use App\Services\Ai\Knowledge\AtlasKnowledgeSourcePacketRegistryService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\LongHorizon\LongHorizonContinuityCertificationService;
use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
use App\Services\Ai\Patamar4\AtlasEmbodimentIntegrationService;
use App\Services\Ai\Patamar4\AtlasNightlyCounterfactualsService;
use App\Services\Ai\Patamar4\AtlasRuntimeDegradationSignalService;
use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use App\Services\Ai\Programming\Bdd\AtlasBddAcceptanceRuntimeService;
use App\Services\Ai\Programming\Cartography\AtlasProgrammingCartographyPublisherService;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\ResearchDomain\ResearchRuntimeService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionPromotionPlanService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionScaffoldStagingExecutorService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfDivergenceModelService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use App\Services\Ai\VerifiedContextExecution\AtlasVerifiedContextExecutionLoopService;
use ReflectionClass;
use Throwable;

/**
 * Atlas Cognition Operating System — runtime scorecard.
 *
 * Mede em runtime real o status dos 73 subsistemas canonicos do ACOS em
 * 3 dimensoes honestas:
 *
 *   - code      : a classe de servico existe e e instanciavel (class_exists).
 *   - doc       : RESOLVIDO em runtime — um doc canonico declara ownership FQN-bound
 *                 (`symbol: <ref>`) que resolve para ESTE service_class. NUNCA lido da
 *                 tupla SUBSYSTEMS; reusa o ADRS evidence resolver.
 *   - pipeline  : RESOLVIDO em runtime — o teste do doc owner tem um GREEN-RUN RECEIPT
 *                 real e FRESCO (B3 hasGreenReceipt), nao apenas um simbolo *Test* que
 *                 existe. NUNCA lido da tupla SUBSYSTEMS.
 *
 * Schema: atlas.cognition.scorecard.v3 (v3 remove volume — uso organico nao
 * conta como dimensao mensuravel; pipeline_proof e o teto honesto).
 *
 * ANTI-OVER-CLAIM: doc_status e pipeline_status sao FUNCAO de evidencia resolvida em
 * runtime (AtlasCognitionEvidenceResolver), NAO literais auto-declarados. Mexer a
 * evidencia (remover o doc owner / o receipt) move o status e o score agregado — um
 * valor hardcoded nao pode se mover. So code_status era a unica dimensao ja real.
 *
 * IMPORTANT (claim_policy):
 *  - Nao emite claim de "benchmark", "rivals" ou "superiority".
 *  - O score reflete maturidade interna do Atlas, nao comparacao externa.
 *  - external_rivals_certification permanece BLOCKED.
 */
class AtlasCognitionScoreCardService
{
    public const FIELD_V4 = 'v4';
    public const FIELD_CODE = 'code';
    public const SCHEMA_VERSION = 'atlas.cognition.scorecard.v3';

    public const DUAL_EMIT_V3_CONFIG_KEY = 'atlas_elite_compaction.scorecard.dual_emit_v3';

    public const DEFAULT_DUAL_EMIT_V3 = true;

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BUILDING = 'building';

    public const STATUS_BLOCKED = 'blocked';
    public const FIELD_EVIDENCE_ALIAS_OF = 'evidence_alias_of';
    public const FIELD_ACRONYM = 'acronym';
    public const FIELD_SCORE_OUT_OF_10 = 'score_out_of_10';
    public const FIELD_PIPELINE_STATUS = 'pipeline_status';
    public const FIELD_DOC_STATUS = 'doc_status';
    public const FIELD_CODE_STATUS = 'code_status';
    public const FIELD_STATUS = 'status';
    public const FIELD_OVERALL = 'overall';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_SUBSYSTEM_COUNT = 'subsystem_count';
    public const FIELD_SCORED_SUBSYSTEM_COUNT = 'scored_subsystem_count';
    public const FIELD_SCORE = 'score';
    public const FIELD_CLAIM_POLICY = 'claim_policy';
    public const FIELD_SCORECARD_HASH = 'scorecard_hash';
    public const FIELD_MODULE_COUNT = 'module_count';
    public const FIELD_MODULES = 'modules';
    public const FIELD_GROUP = 'group';
    public const FIELD_SERVICE_CLASS = 'service_class';
    public const FIELD_CONSUMER_MODULE_COUNT = 'consumer_module_count';
    public const FIELD_CONSUMER_MODULES = 'consumer_modules';
    public const FIELD_SUPPLEMENTAL_SUBSYSTEM_COUNT = 'supplemental_subsystem_count';
    public const FIELD_NAME = 'name';
    public const FIELD_OVERALL_OUT_OF_10 = 'overall_out_of_10';
    public const FIELD_SUBSYSTEMS = 'subsystems';
    public const FIELD_NOTES = 'notes';
    public const FIELD_BENCHMARK_CLAIM_ALLOWED = 'benchmark_claim_allowed';
    public const FIELD_COGNITIVE_IMMUNE_LAW_ENFORCED = 'cognitive_immune_law_enforced';
    public const FIELD_DIMENSIONS = 'dimensions';
    public const FIELD_DOC = 'doc';
    public const FIELD_EXTERNAL_RIVALS_CERTIFICATION_TOUCHED = 'external_rivals_certification_touched';
    public const FIELD_MAX = 'max';
    public const FIELD_MUST_KEEP_COVERAGE_INVARIANT = 'must_keep_coverage_invariant';
    public const FIELD_PIPELINE = 'pipeline';
    public const FIELD_PROVIDER_SAFE_ONLY_ENFORCED = 'provider_safe_only_enforced';
    public const FIELD_READINESS_DEFINITION = 'readiness_definition';

    /** Score points per status. */
    public const STATUS_POINTS = [
        self::STATUS_READY => 10,
        self::STATUS_PARTIAL => 6,
        self::STATUS_BUILDING => 3,
        self::STATUS_BLOCKED => 0,
    ];

    /**
     * Resolves doc_status + pipeline_status from REAL evidence (FQN-bound doc
     * ownership + a fresh B3 green-run receipt), reusing the ADRS evidence stack.
     * build() reads doc/pipeline from this, never from the SUBSYSTEMS tuple.
     */
    private readonly AtlasCognitionEvidenceResolver $evidence;

    /**
     * The dep is nullable-defaulted so the many `new AtlasCognitionScoreCardService`
     * call sites (consumers + tests) keep working; when a container is present it is
     * resolved through DI so a test can swap a stub resolver in.
     */
    public function __construct(?AtlasCognitionEvidenceResolver $evidence = null)
    {
        $this->evidence = $evidence
            ?? (function_exists('app') && app()->bound('app')
                ? app(AtlasCognitionEvidenceResolver::class)
                : new AtlasCognitionEvidenceResolver);
    }

    /**
     * Os 73 subsistemas canonicos do ACOS.
     *
     * Tuple: [acronym, name, group, service_class] — IDENTITY ONLY.
     *
     * The old doc_status/pipeline_status literals were DELETED: they were
     * self-declared 'ready' constants (the over-claim this build killed). doc_status
     * and pipeline_status are now RESOLVED from real evidence at runtime by
     * AtlasCognitionEvidenceResolver in build() — build() never reads a status from
     * this tuple. Only the [acronym,name,group,service_class] identity columns remain.
     *
     * v3 removed the volume dimension — pipeline_proof via passing tests
     * against real services is the honest ceiling; operator ruled
     * "uso organico nao conta" (organic-usage gating is not a valid dimension).
     */
    public const SUBSYSTEMS = [
        // Cognitive Immune G0-G8 (9)
        ['G0',     'Raw Capture Layer',           'cognitive_immune', AtlasAemorRuntimeService::class],
        ['G1',     'Evidence Promotion Gate',     'cognitive_immune', AtlasAemorRuntimeService::class],
        ['G2',     'Learning Signal Extraction',  'cognitive_immune', AtlasLearningDistiller::class],
        ['G3',     'Memory Promotion',            'cognitive_immune', AiMemoryDeltaProposer::class],
        ['G4',     'Context Gate',                'cognitive_immune', AtlasContextFreshnessQualityGateService::class],
        ['G5',     'Decision Gate',               'cognitive_immune', AiDecisionReceiptRefreshService::class],
        ['G6',     'Outcome Replay',              'cognitive_immune', AtlasAemorJudgmentService::class],
        ['G7',     'Self-Improvement Loop',       'cognitive_immune', AtlasSelfImprovementOrchestrator::class],
        ['G8',     'Compounding Effect',          'cognitive_immune', AtlasCompoundingRuntimeService::class],

        // Memory Core (3)
        ['MEM-CORE',   'Memory Core (entries+relations)', 'memory_core', AtlasMemoryConflictResolutionService::class],
        ['MEM-DELTA',  'Memory Delta Proposer',           'memory_core', AiMemoryDeltaProposer::class],
        ['MEM-RECALL', 'Memory Recall (hybrid)',          'memory_core', AtlasHybridRetrievalInfrastructureService::class],

        // AUCRI 18 blocks
        ['ASEF',  'Semantic Embedding Foundation',     'aucri', AtlasSemanticEmbeddingFoundationService::class],
        ['AHRI',  'Hybrid Retrieval Infrastructure',   'aucri', AtlasHybridRetrievalInfrastructureService::class],
        ['AARF',  'Agentic RAG Framework',             'aucri', AtlasAgenticRagFrameworkService::class],
        ['ACRS',  'Context Ranking System',            'aucri', AtlasContextRankingSystemService::class],
        ['ACFQ',  'Context Freshness Quality Gate',    'aucri', AtlasContextFreshnessQualityGateService::class],
        ['ARFL',  'Retrieval Feedback Loop',           'aucri', AtlasRetrievalFeedbackLoopService::class],
        ['AGRN',  'Graph Retrieval Network',           'aucri', AtlasGraphRetrievalNetworkService::class],
        ['AURG',  'Unified Reality Graph',             'aucri', AtlasUnifiedRealityGraphService::class],
        ['APDR',  'Python Data Retrieval Runtime',     'aucri', AtlasPythonDataRetrievalRuntimeService::class],
        ['AREBA', 'Retrieval Evaluation Arena',        'aucri', AtlasRetrievalEvaluationBenchmarkArenaService::class],
        ['ARCLG', 'Retrieval Cost Latency Governor',   'aucri', AtlasRetrievalCostLatencyGovernorService::class],
        ['ACOP',  'Context Observability Plane',       'aucri', AtlasContextObservabilityPlaneService::class],
        ['ARPTL', 'Retrieval Privacy Trust Layer',     'aucri', AtlasRetrievalPrivacyTrustLayerService::class],
        ['AKIF',  'Knowledge Ingestion Fabric',        'aucri', AtlasKnowledgeSourcePacketRegistryService::class],
        ['ACMF',  'Cognitive Memory Fabric',           'aucri', AtlasCognitiveMemoryFabricService::class],
        ['ACCR',  'Context Compiler Runtime',          'aucri', AtlasContextCompilerRuntimeService::class],
        ['ATER',  'Token Economy Runtime',             'aucri', AtlasTokenEconomyBudgetPolicyService::class],
        ['ACPFR', 'Context Pareto Frontier Runtime',   'aucri', AtlasContextParetoFrontierRuntimeService::class],

        // Self-Improvement L7 (closed loop)
        ['ASI-L7', 'Self-Improvement Closed Loop L7', 'self_improvement', AtlasSelfImprovementResultLedgerService::class],

        // Patamar 2/3 — meta-learning, self-construction, AURG-4D, cross-domain mesh, TEOS-I3
        ['ADML',    'Atlas Decide Meta-Learning',          'atlas_decide',      AtlasDecideMetaLearningService::class],
        ['ASCB',    'Self-Construction Subsystem Builder', 'self_construction', AtlasSelfConstructionSubsystemBuilderService::class],
        ['AURG-4D', 'Unified Reality Graph Temporal (4D)', 'reality',           AtlasUnifiedRealityGraphTemporalService::class],
        ['ACDM',    'Cross-Domain Mesh',                   'cross_domain',      AtlasCrossDomainMeshService::class],
        ['TEOS-I3', 'TEOS-I3 Counterfactual Runtime',      'teos',              AtlasTeosI3CounterfactualService::class],

        // Patamar 4 — Constitutional Kernel, Autonomy Admission, CognitiveFunctionAtlas, Reconciliation Runtime, TEOS-I4, Swarm Conductor, Temporary Domain Composition
        ['ACK',     'Constitutional Kernel',               'governance',        AtlasConstitutionalKernelService::class],
        ['AAA',     'Autonomy Admission',                  'governance',        AtlasAutonomyAdmissionService::class],
        ['ACFA',    'Cognitive Function Atlas',            'cognition',         AtlasCognitiveFunctionAtlasService::class],
        ['AARR',    'Autonomous Reconciliation Runtime',   'autonomy',          AtlasAutonomousReconciliationRuntimeService::class],
        ['TEOS-I4', 'TEOS-I4 Counterfactual Tree',         'teos',              AtlasTeosI4CounterfactualTreeService::class],
        ['ASWC',    'Swarm Conductor',                     'atlas_decide',      AtlasSwarmConductorService::class],
        ['ASWE',    'Swarm Executor',                      'atlas_decide',      AtlasSwarmExecutorService::class],
        ['ATDC',    'Temporary Domain Composition',        'cross_domain',      AtlasTemporaryDomainCompositionService::class],

        // Patamar 4 · integration layer
        ['ADGW',    'Atlas Decide Gateway Consultation',   'atlas_decide',      AtlasDecideGatewayConsultationService::class],
        ['ADLF',    'Atlas Decide Live Outcome Feedback',  'atlas_decide',      AtlasDecideLiveOutcomeFeedbackService::class],
        ['AACM',    'Antifragility Composition Metric',    'compounding',       AtlasAntifragilityCompositionMetricService::class],
        ['ACMF-SE', 'Cognitive Memory Fabric Schema Evolution', 'aucri',         AtlasCognitiveMemoryFabricSchemaEvolutionService::class],
        ['ASCB-EX', 'Self-Construction Scaffold Staging Executor', 'self_construction', AtlasSelfConstructionScaffoldStagingExecutorService::class],
        ['ASCB-PP', 'Self-Construction Promotion Plan',          'self_construction', AtlasSelfConstructionPromotionPlanService::class],
        ['ACTG',    'Cartography Truth Guard',                   'cartography',       CartographyTruthGuardService::class],
        ['AGPF',    'Atlas Gateway Preflight (TEOS-I4)',         'atlas_decide',      AtlasGatewayPreflightService::class],
        ['ACVS',    'Constitutional Vault Service',              'governance',        AtlasConstitutionalVaultService::class],
        ['ATBS',    'Trust Budget Service',                      'governance',        AtlasTrustBudgetService::class],
        ['ANCF',    'Nightly Counterfactuals',                   'patamar_4',         AtlasNightlyCounterfactualsService::class],
        ['ASAR',    'Subsystem Auto-Rebalance',                  'patamar_4',         AtlasSubsystemAutoRebalanceService::class],
        ['ASOS',    'Atlas Scheduler OS (Cron 24/7)',            'patamar_4',         AtlasSchedulerHealthService::class],
        ['ASPR',    'Swarm Production Resolver (real provider)', 'atlas_decide',      AtlasSwarmProductionResolverService::class],
        ['ACFD',    'Cognitive Function Decomposer (6-axis)',    'cognition',         AtlasCognitiveFunctionDecomposerService::class],
        ['ASPD',    'Atlas Swarm Parallel Dispatcher',           'atlas_decide',      AtlasSwarmParallelDispatchService::class],
        ['ACSR',    'Cognitive Function Swarm Router (P6 closure)', 'atlas_decide',   AtlasCognitiveFunctionSwarmRouterService::class],
        ['ASAF',    'Swarm Auto-Failover (A4)',                  'atlas_decide',      AtlasSwarmAutoFailoverService::class],
        ['ARDS',    'Runtime Degradation Signal Ingress',        'patamar_4',         AtlasRuntimeDegradationSignalService::class],
        ['ASDM',    'Self-Divergence Model (target vs current)', 'self_construction', AtlasSelfDivergenceModelService::class],
        ['AEMB',    'Embodiment Integration (P7 closure)',       'patamar_4',         AtlasEmbodimentIntegrationService::class],

        // Patamar 1/2/3 closures — OCR confidence, Compounding L8/L9
        // COM-09: ACOP→ACRS bridge deleted — JSONL had 1 smoke signal (null value);
        // ARFL feedbackHint in ACRS already closes observability→ranking.
        ['AKIF-OCR', 'AKIF OCR Confidence-Scored Ingestion', 'aucri',           AtlasKnowledgeIngestionFabricOcrConfidenceService::class],
        ['ACL8',    'Compounding Level 8/9 Distillation',   'compounding',     AtlasCompoundingLevel8DistillationService::class],

        // Patamar 4 · intelligence boost
        ['ADTI4', 'Atlas Decide TEOS-I4 Lookahead',        'atlas_decide',     AtlasDecideTeosI4LookaheadService::class],

        // Domain runtimes — Research (review-only, source-grounded)
        ['ARDR',  'Research Domain Runtime',               'research_domain', ResearchRuntimeService::class],
        ['ABDD',  'BDD Acceptance Runtime',                'programming',     AtlasBddAcceptanceRuntimeService::class],
        ['ALMR',  'Learning Mutation Runtime',             'compounding',     AtlasLearningMutationRuntimeService::class],
        ['APCP',  'Programming Cartography Publisher',     'programming',     AtlasProgrammingCartographyPublisherService::class],
    ];

    /**
     * Deep ACOS modules declared by the canonical architecture but historically
     * absent from the 73-row v3 facet inventory. They are emitted only in v4 so
     * v3 remains a compatibility surface while v4 becomes the truthful boundary.
     */
    public const V4_SUPPLEMENTAL_SUBSYSTEMS = [
        ['ACCCR', 'Context Cache Compiler Runtime', 'context_cache', AtlasContextCacheCompilerRuntimeService::class],
        ['ACIE', 'Context Intelligence Engine', 'context_intelligence', AtlasContextOperationsRuntimeService::class],
        ['APCR', 'Persistent Context Runtime', 'persistent_context', AtlasPersistentContextRuntimeService::class],
        ['AEMOR', 'Execution Memory Outcome Runtime', 'aemor', AtlasAemorCertificationService::class],
        ['TEOS-I1', 'Long-Horizon Intelligence Layer', 'long_horizon', LongHorizonContinuityCertificationService::class],
        ['AVCEL', 'Verified Context Execution Loop', 'verified_context', AtlasVerifiedContextExecutionLoopService::class],
        ['ACQCG', 'Context Quality Certification Gate', 'context_quality', AtlasContextQualityCertificationService::class],
        ['AOBG', 'Open Brain Gateway', 'open_brain', AtlasOpenBrainMcpService::class],
        ['EVIDENCE', 'Evidence Ledger Memory Side', 'evidence', AtlasEvidenceLedger::class],
    ];

    /**
     * Build the full scorecard.
     */
    public function build(): array
    {
        $rows = [];
        $firstFacetByService = [];
        foreach (self::SUBSYSTEMS as [$acronym, $name, $group, $serviceClass]) {
            $evidenceAlias = $firstFacetByService[$serviceClass] ?? null;
            $firstFacetByService[$serviceClass] ??= $acronym;
            $rows[] = [
                self::FIELD_ACRONYM => $acronym,
                self::FIELD_NAME => $name,
                self::FIELD_GROUP => $group,
                self::FIELD_SERVICE_CLASS => $serviceClass,
                self::FIELD_EVIDENCE_ALIAS_OF => $evidenceAlias,
                self::FIELD_CODE_STATUS => $this->probeCodeStatus($serviceClass),
                // doc_status and pipeline_status are RESOLVED from real evidence at
                // runtime (FQN-bound doc ownership + a fresh B3 green-run receipt) —
                // never read from the SUBSYSTEMS tuple. Moving the evidence moves the
                // status and the aggregate; a hardcoded literal could not move.
                self::FIELD_DOC_STATUS => $this->evidence->resolveDocStatus($serviceClass),
                self::FIELD_PIPELINE_STATUS => $this->evidence->resolvePipelineStatus($serviceClass),
            ];
        }

        $score = $this->aggregateScore($rows);
        $envelope = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => gmdate('c'),
            self::FIELD_SUBSYSTEM_COUNT => count($rows),
            self::FIELD_SCORED_SUBSYSTEM_COUNT => count(array_filter(
                $rows,
                static fn (array $row): bool => $row[self::FIELD_EVIDENCE_ALIAS_OF] === null,
            )),
            self::FIELD_SUBSYSTEMS => $rows,
            self::FIELD_SCORE => $score,
            self::FIELD_CLAIM_POLICY => $this->claimPolicy(),
            self::FIELD_NOTES => [
                self::FIELD_READINESS_DEFINITION => 'A unique service facet is ready when code, doc and pipeline are ready. Alias facets remain visible but are not scored twice. Real-world volume remains separate.',
            ],
        ];
        $envelope[self::FIELD_SCORECARD_HASH] = $this->hash($rows, $score);

        if ((AiValueNormalizer::boolOrNull(config(self::DUAL_EMIT_V3_CONFIG_KEY, self::DEFAULT_DUAL_EMIT_V3)) ?? self::DEFAULT_DUAL_EMIT_V3)) {
            $grouper = new AtlasCognitionScoreCardV4Grouper;
            $supplemental = $this->v4SupplementalRows();
            $modules = $grouper->group(array_merge($rows, $supplemental));
            $consumerModules = $grouper->groupConsumers($rows);
            $envelope[self::FIELD_V4] = [
                self::FIELD_SCHEMA_VERSION => AtlasCognitionScoreCardV4Grouper::SCHEMA_VERSION,
                self::FIELD_MODULE_COUNT => count($modules),
                self::FIELD_MODULES => $modules,
                self::FIELD_CONSUMER_MODULE_COUNT => count($consumerModules),
                self::FIELD_CONSUMER_MODULES => $consumerModules,
                self::FIELD_SUPPLEMENTAL_SUBSYSTEM_COUNT => count($supplemental),
            ];
        }

        return $envelope;
    }

    /**
     * Build scorecard v4 module rollup only.
     *
     * @return array<string, mixed>
     */
    public function buildV4(): array
    {
        $v3 = $this->build();

        return [
            self::FIELD_SCHEMA_VERSION => AtlasCognitionScoreCardV4Grouper::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => $v3[self::FIELD_GENERATED_AT] ?? gmdate('c'),
            self::FIELD_MODULE_COUNT => $v3[self::FIELD_V4][self::FIELD_MODULE_COUNT] ?? 0,
            self::FIELD_MODULES => $v3[self::FIELD_V4][self::FIELD_MODULES] ?? [],
            self::FIELD_CONSUMER_MODULE_COUNT => $v3[self::FIELD_V4][self::FIELD_CONSUMER_MODULE_COUNT] ?? 0,
            self::FIELD_CONSUMER_MODULES => $v3[self::FIELD_V4][self::FIELD_CONSUMER_MODULES] ?? [],
            self::FIELD_SUPPLEMENTAL_SUBSYSTEM_COUNT => $v3[self::FIELD_V4][self::FIELD_SUPPLEMENTAL_SUBSYSTEM_COUNT] ?? 0,
            self::FIELD_SUBSYSTEM_COUNT => $v3[self::FIELD_SUBSYSTEM_COUNT] ?? 0,
            self::FIELD_SCORED_SUBSYSTEM_COUNT => $v3[self::FIELD_SCORED_SUBSYSTEM_COUNT] ?? 0,
            self::FIELD_SCORE => $v3[self::FIELD_SCORE] ?? [],
            self::FIELD_SCORECARD_HASH => $v3[self::FIELD_SCORECARD_HASH] ?? null,
            self::FIELD_CLAIM_POLICY => $v3[self::FIELD_CLAIM_POLICY] ?? [],
        ];
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

        try {
            return (new ReflectionClass($serviceClass))->isInstantiable()
                ? self::STATUS_READY
                : self::STATUS_PARTIAL;
        } catch (Throwable) {
            return self::STATUS_BLOCKED;
        }
    }

    /**
     * Aggregate scores across the three structural dimensions:
     * code (service exists) + doc (canon published) + pipeline (proven by tests).
     */
    private function aggregateScore(array $rows): array
    {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row[self::FIELD_EVIDENCE_ALIAS_OF] ?? null) === null,
        ));
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
                self::FIELD_MAX => $max,
                self::FIELD_SCORE_OUT_OF_10 => $max > 0 ? round(($sum / $max) * 10, 2) : 0.0,
            ];
        }

        $overall = round(
            ($totals[self::FIELD_CODE][self::FIELD_SCORE_OUT_OF_10]
                + $totals[self::FIELD_DOC][self::FIELD_SCORE_OUT_OF_10]
                + $totals[self::FIELD_PIPELINE][self::FIELD_SCORE_OUT_OF_10]) / 3,
            2
        );

        return [
            self::FIELD_OVERALL_OUT_OF_10 => $overall,
            self::FIELD_DIMENSIONS => $totals,
        ];
    }

    /**
     * Hardcoded provider-safe claim policy. Never relaxes.
     */
    private function claimPolicy(): array
    {
        return [
            self::FIELD_BENCHMARK_CLAIM_ALLOWED => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            self::FIELD_EXTERNAL_RIVALS_CERTIFICATION_TOUCHED => false,
            self::FIELD_COGNITIVE_IMMUNE_LAW_ENFORCED => true,
            self::FIELD_MUST_KEEP_COVERAGE_INVARIANT => true,
            self::FIELD_PROVIDER_SAFE_ONLY_ENFORCED => true,
        ];
    }

    /**
     * Deterministic hash over canonical row content.
     */
    private function hash(array $rows, array $score): string
    {
        $canonical = array_map(static function (array $r): array {
            return [
                self::FIELD_ACRONYM => $r[self::FIELD_ACRONYM],
                self::FIELD_EVIDENCE_ALIAS_OF => $r[self::FIELD_EVIDENCE_ALIAS_OF] ?? null,
                self::FIELD_CODE_STATUS => $r[self::FIELD_CODE_STATUS],
                self::FIELD_DOC_STATUS => $r[self::FIELD_DOC_STATUS],
                self::FIELD_PIPELINE_STATUS => $r[self::FIELD_PIPELINE_STATUS],
            ];
        }, $rows);
        usort($canonical, static fn ($a, $b) => strcmp($a[self::FIELD_ACRONYM], $b[self::FIELD_ACRONYM]));

        return 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'rows' => $canonical,
            self::FIELD_OVERALL => $score[self::FIELD_OVERALL_OUT_OF_10],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Returns the canonical subsystem count (73).
     */
    public static function canonicalSubsystemCount(): int
    {
        return count(self::SUBSYSTEMS);
    }

    /**
     * MAXL-04 needs the v4 supplemental rows to build the per-area v2 series
     * without shadow-computing them. Exposed as a public read-model so the
     * v2 producer can group subsystems + supplementals by `group` using the
     * SAME shape the v1 aggregate is built from.
     *
     * @return list<array<string,mixed>>
     */
    public function v4SupplementalRows(): array
    {
        $rows = [];
        foreach (self::V4_SUPPLEMENTAL_SUBSYSTEMS as [$acronym, $name, $group, $serviceClass]) {
            $rows[] = [
                self::FIELD_ACRONYM => $acronym,
                self::FIELD_NAME => $name,
                self::FIELD_GROUP => $group,
                self::FIELD_SERVICE_CLASS => $serviceClass,
                self::FIELD_EVIDENCE_ALIAS_OF => null,
                'supplemental' => true,
                self::FIELD_CODE_STATUS => $this->probeCodeStatus($serviceClass),
                self::FIELD_DOC_STATUS => $this->evidence->resolveDocStatus($serviceClass),
                self::FIELD_PIPELINE_STATUS => $this->evidence->resolvePipelineStatus($serviceClass),
            ];
        }

        return $rows;
    }
}
