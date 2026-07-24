<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

use App\Services\Ai\Aemor\AtlasAemorCertificationService;
use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\AtlasDecide\AiDecisionReceiptRefreshService;
use App\Services\Ai\Memory\AiMemoryDeltaProposer;
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
use App\Support\UtcIsoTimestamp;

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
    public const FIELD_RIVALS_CLAIM_ALLOWED = 'rivals_claim_allowed';
    public const FIELD_ROWS = 'rows';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_SUM = 'sum';
    public const FIELD_SUPERIORITY_CLAIM_ALLOWED = 'superiority_claim_allowed';
    public const FIELD_SUPPLEMENTAL = 'supplemental';
    public const FIELD_AUCRI = 'aucri';
    public const FIELD_ATLAS_DECIDE = 'atlas_decide';
    public const FIELD_COGNITIVE_IMMUNE = 'cognitive_immune';
    public const FIELD_PATAMAR_4 = 'patamar_4';
    public const FIELD_GOVERNANCE = 'governance';
    public const FIELD_SELF_CONSTRUCTION = 'self_construction';
    public const FIELD_COMPOUNDING = 'compounding';
    public const FIELD_MEMORY_CORE = 'memory_core';
    public const FIELD_APP = 'app';
    public const FIELD_COGNITION = 'cognition';
    public const FIELD_CROSS_DOMAIN = 'cross_domain';
    public const FIELD_PROGRAMMING = 'programming';
    public const FIELD_TEOS = 'teos';
    public const FIELD_AEMOR = 'aemor';
    public const FIELD_AUTONOMY = 'autonomy';
    public const FIELD_CARTOGRAPHY = 'cartography';
    public const FIELD_CONTEXT_CACHE = 'context_cache';
    public const FIELD_CONTEXT_INTELLIGENCE = 'context_intelligence';
    public const FIELD_CONTEXT_QUALITY = 'context_quality';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_LONG_HORIZON = 'long_horizon';
    public const FIELD_OPEN_BRAIN = 'open_brain';
    public const FIELD_PERSISTENT_CONTEXT = 'persistent_context';
    public const FIELD_REALITY = 'reality';
    public const FIELD_RESEARCH_DOMAIN = 'research_domain';
    public const FIELD_SELF_IMPROVEMENT = 'self_improvement';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_VERIFIED_CONTEXT = 'verified_context';
    public const FIELD_AAA = 'AAA';
    public const FIELD_AACM = 'AACM';
    public const FIELD_AARF = 'AARF';
    public const FIELD_AARR = 'AARR';
    public const FIELD_ABDD = 'ABDD';
    public const FIELD_ACCCR = 'ACCCR';
    public const FIELD_ACCR = 'ACCR';
    public const FIELD_ACDM = 'ACDM';
    public const FIELD_ACFA = 'ACFA';
    public const FIELD_ACFD = 'ACFD';
    public const FIELD_ACFQ = 'ACFQ';
    public const FIELD_ACIE = 'ACIE';
    public const FIELD_ACK = 'ACK';
    public const FIELD_ACL8 = 'ACL8';
    public const FIELD_ACMF = 'ACMF';
    public const FIELD_ACOP = 'ACOP';
    public const FIELD_ACPFR = 'ACPFR';
    public const FIELD_ACQCG = 'ACQCG';
    public const FIELD_ACRS = 'ACRS';
    public const FIELD_ACSR = 'ACSR';
    public const FIELD_ACTG = 'ACTG';
    public const FIELD_ACVS = 'ACVS';
    public const FIELD_ADGW = 'ADGW';
    public const FIELD_ADLF = 'ADLF';
    public const FIELD_ADML = 'ADML';
    public const FIELD_ADTI4 = 'ADTI4';
    public const FIELD_AEMB = 'AEMB';
    public const FIELD_AEMOR_2 = 'AEMOR';
    public const FIELD_AGPF = 'AGPF';
    public const FIELD_AGRN = 'AGRN';
    public const FIELD_AHRI = 'AHRI';
    public const FIELD_AKIF = 'AKIF';
    public const FIELD_ALMR = 'ALMR';
    public const FIELD_ANCF = 'ANCF';
    public const FIELD_AOBG = 'AOBG';
    public const FIELD_APCP = 'APCP';
    public const FIELD_APCR = 'APCR';
    public const FIELD_APDR = 'APDR';
    public const FIELD_ARCLG = 'ARCLG';
    public const FIELD_ARDR = 'ARDR';
    public const FIELD_ARDS = 'ARDS';
    public const FIELD_AREBA = 'AREBA';
    public const FIELD_ARFL = 'ARFL';
    public const FIELD_ARPTL = 'ARPTL';
    public const FIELD_ASAF = 'ASAF';
    public const FIELD_ASAR = 'ASAR';
    public const FIELD_ASCB = 'ASCB';
    public const FIELD_ASDM = 'ASDM';
    public const FIELD_ASEF = 'ASEF';
    public const FIELD_ASOS = 'ASOS';
    public const FIELD_ASPD = 'ASPD';
    public const FIELD_ASPR = 'ASPR';
    public const FIELD_ASWC = 'ASWC';
    public const FIELD_ASWE = 'ASWE';
    public const FIELD_ATBS = 'ATBS';
    public const FIELD_ATDC = 'ATDC';
    public const FIELD_ATER = 'ATER';
    public const FIELD_AURG = 'AURG';
    public const FIELD_AVCEL = 'AVCEL';
    public const FIELD_EVIDENCE_2 = 'EVIDENCE';
    public const FIELD_ACMF_SE = 'ACMF-SE';
    public const FIELD_AKIF_OCR = 'AKIF-OCR';
    public const FIELD_ASCB_EX = 'ASCB-EX';
    public const FIELD_ASCB_PP = 'ASCB-PP';
    public const FIELD_ASI_L7 = 'ASI-L7';
    public const FIELD_AURG_4_D = 'AURG-4D';
    public const FIELD_MEM_CORE = 'MEM-CORE';
    public const FIELD_MEM_DELTA = 'MEM-DELTA';
    public const FIELD_MEM_RECALL = 'MEM-RECALL';
    public const FIELD_TEOS_I1 = 'TEOS-I1';
    public const FIELD_TEOS_I3 = 'TEOS-I3';
    public const FIELD_TEOS_I4 = 'TEOS-I4';
    public const FIELD_AGENTIC_RAG_FRAMEWORK = 'Agentic RAG Framework';
    public const FIELD_ANTIFRAGILITY_COMPOSITION_METRIC = 'Antifragility Composition Metric';
    public const FIELD_ATLAS_DECIDE_GATEWAY_CONSULTATION = 'Atlas Decide Gateway Consultation';
    public const FIELD_ATLAS_DECIDE_LIVE_OUTCOME_FEEDBACK = 'Atlas Decide Live Outcome Feedback';
    public const FIELD_ATLAS_SWARM_PARALLEL_DISPATCHER = 'Atlas Swarm Parallel Dispatcher';
    public const FIELD_AUTONOMOUS_RECONCILIATION_RUNTIME = 'Autonomous Reconciliation Runtime';
    public const FIELD_AUTONOMY_ADMISSION = 'Autonomy Admission';
    public const FIELD_BDD_ACCEPTANCE_RUNTIME = 'BDD Acceptance Runtime';
    public const FIELD_CARTOGRAPHY_TRUTH_GUARD = 'Cartography Truth Guard';
    public const FIELD_COGNITIVE_FUNCTION_ATLAS = 'Cognitive Function Atlas';
    public const FIELD_COGNITIVE_MEMORY_FABRIC = 'Cognitive Memory Fabric';
    public const FIELD_COGNITIVE_MEMORY_FABRIC_SCHEMA_EVOLUTION = 'Cognitive Memory Fabric Schema Evolution';
    public const FIELD_COMPOUNDING_EFFECT = 'Compounding Effect';
    public const FIELD_CONSTITUTIONAL_KERNEL = 'Constitutional Kernel';
    public const FIELD_CONSTITUTIONAL_VAULT_SERVICE = 'Constitutional Vault Service';
    public const FIELD_CONTEXT_CACHE_COMPILER_RUNTIME = 'Context Cache Compiler Runtime';
    public const FIELD_CONTEXT_COMPILER_RUNTIME = 'Context Compiler Runtime';
    public const FIELD_CONTEXT_FRESHNESS_QUALITY_GATE = 'Context Freshness Quality Gate';
    public const FIELD_CONTEXT_GATE = 'Context Gate';
    public const FIELD_CONTEXT_INTELLIGENCE_ENGINE = 'Context Intelligence Engine';
    public const FIELD_CONTEXT_OBSERVABILITY_PLANE = 'Context Observability Plane';
    public const FIELD_CONTEXT_PARETO_FRONTIER_RUNTIME = 'Context Pareto Frontier Runtime';
    public const FIELD_CONTEXT_QUALITY_CERTIFICATION_GATE = 'Context Quality Certification Gate';
    public const FIELD_CONTEXT_RANKING_SYSTEM = 'Context Ranking System';
    public const FIELD_DECISION_GATE = 'Decision Gate';
    public const FIELD_EVIDENCE_LEDGER_MEMORY_SIDE = 'Evidence Ledger Memory Side';
    public const FIELD_EVIDENCE_PROMOTION_GATE = 'Evidence Promotion Gate';
    public const FIELD_EXECUTION_MEMORY_OUTCOME_RUNTIME = 'Execution Memory Outcome Runtime';
    public const FIELD_G0 = 'G0';
    public const FIELD_G1 = 'G1';
    public const FIELD_G2 = 'G2';
    public const FIELD_G3 = 'G3';
    public const FIELD_G4 = 'G4';
    public const FIELD_G5 = 'G5';
    public const FIELD_G6 = 'G6';
    public const FIELD_G7 = 'G7';
    public const FIELD_G8 = 'G8';
    public const FIELD_GRAPH_RETRIEVAL_NETWORK = 'Graph Retrieval Network';
    public const FIELD_HYBRID_RETRIEVAL_INFRASTRUCTURE = 'Hybrid Retrieval Infrastructure';
    public const FIELD_KNOWLEDGE_INGESTION_FABRIC = 'Knowledge Ingestion Fabric';
    public const FIELD_LEARNING_MUTATION_RUNTIME = 'Learning Mutation Runtime';
    public const FIELD_LEARNING_SIGNAL_EXTRACTION = 'Learning Signal Extraction';
    public const FIELD_MEMORY_DELTA_PROPOSER = 'Memory Delta Proposer';
    public const FIELD_MEMORY_PROMOTION = 'Memory Promotion';
    public const FIELD_NIGHTLY_COUNTERFACTUALS = 'Nightly Counterfactuals';
    public const FIELD_OPEN_BRAIN_GATEWAY = 'Open Brain Gateway';
    public const FIELD_OUTCOME_REPLAY = 'Outcome Replay';
    public const FIELD_PERSISTENT_CONTEXT_RUNTIME = 'Persistent Context Runtime';
    public const FIELD_PROGRAMMING_CARTOGRAPHY_PUBLISHER = 'Programming Cartography Publisher';
    public const FIELD_PYTHON_DATA_RETRIEVAL_RUNTIME = 'Python Data Retrieval Runtime';
    public const FIELD_RAW_CAPTURE_LAYER = 'Raw Capture Layer';
    public const FIELD_RESEARCH_DOMAIN_RUNTIME = 'Research Domain Runtime';
    public const FIELD_RETRIEVAL_COST_LATENCY_GOVERNOR = 'Retrieval Cost Latency Governor';
    public const FIELD_RETRIEVAL_EVALUATION_ARENA = 'Retrieval Evaluation Arena';
    public const FIELD_RETRIEVAL_FEEDBACK_LOOP = 'Retrieval Feedback Loop';
    public const FIELD_RETRIEVAL_PRIVACY_TRUST_LAYER = 'Retrieval Privacy Trust Layer';
    public const FIELD_RUNTIME_DEGRADATION_SIGNAL_INGRESS = 'Runtime Degradation Signal Ingress';
    public const FIELD_SEMANTIC_EMBEDDING_FOUNDATION = 'Semantic Embedding Foundation';
    public const FIELD_SWARM_CONDUCTOR = 'Swarm Conductor';
    public const FIELD_SWARM_EXECUTOR = 'Swarm Executor';
    public const FIELD_TEMPORARY_DOMAIN_COMPOSITION = 'Temporary Domain Composition';
    public const FIELD_TOKEN_ECONOMY_RUNTIME = 'Token Economy Runtime';
    public const FIELD_TRUST_BUDGET_SERVICE = 'Trust Budget Service';
    public const FIELD_UNIFIED_REALITY_GRAPH = 'Unified Reality Graph';
    public const FIELD_VERIFIED_CONTEXT_EXECUTION_LOOP = 'Verified Context Execution Loop';
    public const FIELD_AKIF_OCR_CONFIDENCE_SCORED_INGESTION = 'AKIF OCR Confidence-Scored Ingestion';
    public const FIELD_ATLAS_DECIDE_META_LEARNING = 'Atlas Decide Meta-Learning';
    public const FIELD_ATLAS_DECIDE_TEOS_I4_LOOKAHEAD = 'Atlas Decide TEOS-I4 Lookahead';
    public const FIELD_ATLAS_GATEWAY_PREFLIGHT__TEOS_I4_ = 'Atlas Gateway Preflight (TEOS-I4)';
    public const FIELD_ATLAS_SCHEDULER_OS__CRON_24_7_ = 'Atlas Scheduler OS (Cron 24/7)';
    public const FIELD_COGNITIVE_FUNCTION_DECOMPOSER__6_AXIS_ = 'Cognitive Function Decomposer (6-axis)';
    public const FIELD_COGNITIVE_FUNCTION_SWARM_ROUTER__P6_CLOSURE_ = 'Cognitive Function Swarm Router (P6 closure)';
    public const FIELD_COMPOUNDING_LEVEL_8_9_DISTILLATION = 'Compounding Level 8/9 Distillation';
    public const FIELD_CROSS_DOMAIN_MESH = 'Cross-Domain Mesh';
    public const FIELD_EMBODIMENT_INTEGRATION__P7_CLOSURE_ = 'Embodiment Integration (P7 closure)';
    public const FIELD_LONG_HORIZON_INTELLIGENCE_LAYER = 'Long-Horizon Intelligence Layer';
    public const FIELD_MEMORY_CORE__ENTRIES_RELATIONS_ = 'Memory Core (entries+relations)';
    public const FIELD_MEMORY_RECALL__HYBRID_ = 'Memory Recall (hybrid)';
    public const FIELD_SELF_CONSTRUCTION_PROMOTION_PLAN = 'Self-Construction Promotion Plan';
    public const FIELD_SELF_CONSTRUCTION_SCAFFOLD_STAGING_EXECUTOR = 'Self-Construction Scaffold Staging Executor';
    public const FIELD_SELF_CONSTRUCTION_SUBSYSTEM_BUILDER = 'Self-Construction Subsystem Builder';
    public const FIELD_SELF_DIVERGENCE_MODEL__TARGET_VS_CURRENT_ = 'Self-Divergence Model (target vs current)';
    public const FIELD_SELF_IMPROVEMENT_CLOSED_LOOP_L7 = 'Self-Improvement Closed Loop L7';
    public const FIELD_SELF_IMPROVEMENT_LOOP = 'Self-Improvement Loop';
    public const FIELD_SUBSYSTEM_AUTO_REBALANCE = 'Subsystem Auto-Rebalance';
    public const FIELD_SWARM_AUTO_FAILOVER__A4_ = 'Swarm Auto-Failover (A4)';
    public const FIELD_SWARM_PRODUCTION_RESOLVER__REAL_PROVIDER_ = 'Swarm Production Resolver (real provider)';
    public const FIELD_TEOS_I3_COUNTERFACTUAL_RUNTIME = 'TEOS-I3 Counterfactual Runtime';
    public const FIELD_TEOS_I4_COUNTERFACTUAL_TREE = 'TEOS-I4 Counterfactual Tree';
    public const FIELD_UNIFIED_REALITY_GRAPH_TEMPORAL__4_D_ = 'Unified Reality Graph Temporal (4D)';
    public const FIELD_A_UNIQUE_SERVICE_FACET_IS_READY_WHEN_CODE__DOC_AND_PIPELINE_ARE_READY__ALIAS_FACETS_REMAIN_VISIBLE_BUT_ARE_NOT_SCORED_TWICE__REAL_WORLD_VOLUME_REMAINS_SEPARATE_ = 'A unique service facet is ready when code, doc and pipeline are ready. Alias facets remain visible but are not scored twice. Real-world volume remains separate.';
    public const INT_3 = 3;
    public const INT_6 = 6;
    public const INT_10 = 10;

    /** Score points per status. */
    public const STATUS_POINTS = [
        self::STATUS_READY => self::INT_10,
        self::STATUS_PARTIAL => self::INT_6,
        self::STATUS_BUILDING => self::INT_3,
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
            ?? (function_exists(self::FIELD_APP) && app()->bound(self::FIELD_APP)
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
        [self::FIELD_G0,     self::FIELD_RAW_CAPTURE_LAYER,           self::FIELD_COGNITIVE_IMMUNE, AtlasAemorRuntimeService::class],
        [self::FIELD_G1,     self::FIELD_EVIDENCE_PROMOTION_GATE,     self::FIELD_COGNITIVE_IMMUNE, AtlasAemorRuntimeService::class],
        [self::FIELD_G2,     self::FIELD_LEARNING_SIGNAL_EXTRACTION,  self::FIELD_COGNITIVE_IMMUNE, AtlasLearningDistiller::class],
        [self::FIELD_G3,     self::FIELD_MEMORY_PROMOTION,            self::FIELD_COGNITIVE_IMMUNE, AiMemoryDeltaProposer::class],
        [self::FIELD_G4,     self::FIELD_CONTEXT_GATE,                self::FIELD_COGNITIVE_IMMUNE, AtlasContextFreshnessQualityGateService::class],
        [self::FIELD_G5,     self::FIELD_DECISION_GATE,               self::FIELD_COGNITIVE_IMMUNE, AiDecisionReceiptRefreshService::class],
        [self::FIELD_G6,     self::FIELD_OUTCOME_REPLAY,              self::FIELD_COGNITIVE_IMMUNE, AtlasAemorJudgmentService::class],
        [self::FIELD_G7,     self::FIELD_SELF_IMPROVEMENT_LOOP,       self::FIELD_COGNITIVE_IMMUNE, AtlasSelfImprovementOrchestrator::class],
        [self::FIELD_G8,     self::FIELD_COMPOUNDING_EFFECT,          self::FIELD_COGNITIVE_IMMUNE, AtlasCompoundingRuntimeService::class],

        // Memory Core (3)
        [self::FIELD_MEM_CORE,   self::FIELD_MEMORY_CORE__ENTRIES_RELATIONS_, self::FIELD_MEMORY_CORE, AtlasMemoryConflictResolutionService::class],
        [self::FIELD_MEM_DELTA,  self::FIELD_MEMORY_DELTA_PROPOSER,           self::FIELD_MEMORY_CORE, AiMemoryDeltaProposer::class],
        [self::FIELD_MEM_RECALL, self::FIELD_MEMORY_RECALL__HYBRID_,          self::FIELD_MEMORY_CORE, AtlasHybridRetrievalInfrastructureService::class],

        // AUCRI 18 blocks
        [self::FIELD_ASEF,  self::FIELD_SEMANTIC_EMBEDDING_FOUNDATION,     self::FIELD_AUCRI, AtlasSemanticEmbeddingFoundationService::class],
        [self::FIELD_AHRI,  self::FIELD_HYBRID_RETRIEVAL_INFRASTRUCTURE,   self::FIELD_AUCRI, AtlasHybridRetrievalInfrastructureService::class],
        [self::FIELD_AARF,  self::FIELD_AGENTIC_RAG_FRAMEWORK,             self::FIELD_AUCRI, AtlasAgenticRagFrameworkService::class],
        [self::FIELD_ACRS,  self::FIELD_CONTEXT_RANKING_SYSTEM,            self::FIELD_AUCRI, AtlasContextRankingSystemService::class],
        [self::FIELD_ACFQ,  self::FIELD_CONTEXT_FRESHNESS_QUALITY_GATE,    self::FIELD_AUCRI, AtlasContextFreshnessQualityGateService::class],
        [self::FIELD_ARFL,  self::FIELD_RETRIEVAL_FEEDBACK_LOOP,           self::FIELD_AUCRI, AtlasRetrievalFeedbackLoopService::class],
        [self::FIELD_AGRN,  self::FIELD_GRAPH_RETRIEVAL_NETWORK,           self::FIELD_AUCRI, AtlasGraphRetrievalNetworkService::class],
        [self::FIELD_AURG,  self::FIELD_UNIFIED_REALITY_GRAPH,             self::FIELD_AUCRI, AtlasUnifiedRealityGraphService::class],
        [self::FIELD_APDR,  self::FIELD_PYTHON_DATA_RETRIEVAL_RUNTIME,     self::FIELD_AUCRI, AtlasPythonDataRetrievalRuntimeService::class],
        [self::FIELD_AREBA, self::FIELD_RETRIEVAL_EVALUATION_ARENA,        self::FIELD_AUCRI, AtlasRetrievalEvaluationBenchmarkArenaService::class],
        [self::FIELD_ARCLG, self::FIELD_RETRIEVAL_COST_LATENCY_GOVERNOR,   self::FIELD_AUCRI, AtlasRetrievalCostLatencyGovernorService::class],
        [self::FIELD_ACOP,  self::FIELD_CONTEXT_OBSERVABILITY_PLANE,       self::FIELD_AUCRI, AtlasContextObservabilityPlaneService::class],
        [self::FIELD_ARPTL, self::FIELD_RETRIEVAL_PRIVACY_TRUST_LAYER,     self::FIELD_AUCRI, AtlasRetrievalPrivacyTrustLayerService::class],
        [self::FIELD_AKIF,  self::FIELD_KNOWLEDGE_INGESTION_FABRIC,        self::FIELD_AUCRI, AtlasKnowledgeSourcePacketRegistryService::class],
        [self::FIELD_ACMF,  self::FIELD_COGNITIVE_MEMORY_FABRIC,           self::FIELD_AUCRI, AtlasCognitiveMemoryFabricService::class],
        [self::FIELD_ACCR,  self::FIELD_CONTEXT_COMPILER_RUNTIME,          self::FIELD_AUCRI, AtlasContextCompilerRuntimeService::class],
        [self::FIELD_ATER,  self::FIELD_TOKEN_ECONOMY_RUNTIME,             self::FIELD_AUCRI, AtlasTokenEconomyBudgetPolicyService::class],
        [self::FIELD_ACPFR, self::FIELD_CONTEXT_PARETO_FRONTIER_RUNTIME,   self::FIELD_AUCRI, AtlasContextParetoFrontierRuntimeService::class],

        // Self-Improvement L7 (closed loop)
        [self::FIELD_ASI_L7, self::FIELD_SELF_IMPROVEMENT_CLOSED_LOOP_L7, self::FIELD_SELF_IMPROVEMENT, AtlasSelfImprovementResultLedgerService::class],

        // Patamar 2/3 — meta-learning, self-construction, AURG-4D, cross-domain mesh, TEOS-I3
        [self::FIELD_ADML,    self::FIELD_ATLAS_DECIDE_META_LEARNING,          self::FIELD_ATLAS_DECIDE,      AtlasDecideMetaLearningService::class],
        [self::FIELD_ASCB,    self::FIELD_SELF_CONSTRUCTION_SUBSYSTEM_BUILDER, self::FIELD_SELF_CONSTRUCTION, AtlasSelfConstructionSubsystemBuilderService::class],
        [self::FIELD_AURG_4_D, self::FIELD_UNIFIED_REALITY_GRAPH_TEMPORAL__4_D_, self::FIELD_REALITY,           AtlasUnifiedRealityGraphTemporalService::class],
        [self::FIELD_ACDM,    self::FIELD_CROSS_DOMAIN_MESH,                   self::FIELD_CROSS_DOMAIN,      AtlasCrossDomainMeshService::class],
        [self::FIELD_TEOS_I3, self::FIELD_TEOS_I3_COUNTERFACTUAL_RUNTIME,      self::FIELD_TEOS,              AtlasTeosI3CounterfactualService::class],

        // Patamar 4 — Constitutional Kernel, Autonomy Admission, CognitiveFunctionAtlas, Reconciliation Runtime, TEOS-I4, Swarm Conductor, Temporary Domain Composition
        [self::FIELD_ACK,     self::FIELD_CONSTITUTIONAL_KERNEL,               self::FIELD_GOVERNANCE,        AtlasConstitutionalKernelService::class],
        [self::FIELD_AAA,     self::FIELD_AUTONOMY_ADMISSION,                  self::FIELD_GOVERNANCE,        AtlasAutonomyAdmissionService::class],
        [self::FIELD_ACFA,    self::FIELD_COGNITIVE_FUNCTION_ATLAS,            self::FIELD_COGNITION,         AtlasCognitiveFunctionAtlasService::class],
        [self::FIELD_AARR,    self::FIELD_AUTONOMOUS_RECONCILIATION_RUNTIME,   self::FIELD_AUTONOMY,          AtlasAutonomousReconciliationRuntimeService::class],
        [self::FIELD_TEOS_I4, self::FIELD_TEOS_I4_COUNTERFACTUAL_TREE,         self::FIELD_TEOS,              AtlasTeosI4CounterfactualTreeService::class],
        [self::FIELD_ASWC,    self::FIELD_SWARM_CONDUCTOR,                     self::FIELD_ATLAS_DECIDE,      AtlasSwarmConductorService::class],
        [self::FIELD_ASWE,    self::FIELD_SWARM_EXECUTOR,                      self::FIELD_ATLAS_DECIDE,      AtlasSwarmExecutorService::class],
        [self::FIELD_ATDC,    self::FIELD_TEMPORARY_DOMAIN_COMPOSITION,        self::FIELD_CROSS_DOMAIN,      AtlasTemporaryDomainCompositionService::class],

        // Patamar 4 · integration layer
        [self::FIELD_ADGW,    self::FIELD_ATLAS_DECIDE_GATEWAY_CONSULTATION,   self::FIELD_ATLAS_DECIDE,      AtlasDecideGatewayConsultationService::class],
        [self::FIELD_ADLF,    self::FIELD_ATLAS_DECIDE_LIVE_OUTCOME_FEEDBACK,  self::FIELD_ATLAS_DECIDE,      AtlasDecideLiveOutcomeFeedbackService::class],
        [self::FIELD_AACM,    self::FIELD_ANTIFRAGILITY_COMPOSITION_METRIC,    self::FIELD_COMPOUNDING,       AtlasAntifragilityCompositionMetricService::class],
        [self::FIELD_ACMF_SE, self::FIELD_COGNITIVE_MEMORY_FABRIC_SCHEMA_EVOLUTION, self::FIELD_AUCRI,         AtlasCognitiveMemoryFabricSchemaEvolutionService::class],
        [self::FIELD_ASCB_EX, self::FIELD_SELF_CONSTRUCTION_SCAFFOLD_STAGING_EXECUTOR, self::FIELD_SELF_CONSTRUCTION, AtlasSelfConstructionScaffoldStagingExecutorService::class],
        [self::FIELD_ASCB_PP, self::FIELD_SELF_CONSTRUCTION_PROMOTION_PLAN,          self::FIELD_SELF_CONSTRUCTION, AtlasSelfConstructionPromotionPlanService::class],
        [self::FIELD_ACTG,    self::FIELD_CARTOGRAPHY_TRUTH_GUARD,                   self::FIELD_CARTOGRAPHY,       CartographyTruthGuardService::class],
        [self::FIELD_AGPF,    self::FIELD_ATLAS_GATEWAY_PREFLIGHT__TEOS_I4_,         self::FIELD_ATLAS_DECIDE,      AtlasGatewayPreflightService::class],
        [self::FIELD_ACVS,    self::FIELD_CONSTITUTIONAL_VAULT_SERVICE,              self::FIELD_GOVERNANCE,        AtlasConstitutionalVaultService::class],
        [self::FIELD_ATBS,    self::FIELD_TRUST_BUDGET_SERVICE,                      self::FIELD_GOVERNANCE,        AtlasTrustBudgetService::class],
        [self::FIELD_ANCF,    self::FIELD_NIGHTLY_COUNTERFACTUALS,                   self::FIELD_PATAMAR_4,         AtlasNightlyCounterfactualsService::class],
        [self::FIELD_ASAR,    self::FIELD_SUBSYSTEM_AUTO_REBALANCE,                  self::FIELD_PATAMAR_4,         AtlasSubsystemAutoRebalanceService::class],
        [self::FIELD_ASOS,    self::FIELD_ATLAS_SCHEDULER_OS__CRON_24_7_,            self::FIELD_PATAMAR_4,         AtlasSchedulerHealthService::class],
        [self::FIELD_ASPR,    self::FIELD_SWARM_PRODUCTION_RESOLVER__REAL_PROVIDER_, self::FIELD_ATLAS_DECIDE,      AtlasSwarmProductionResolverService::class],
        [self::FIELD_ACFD,    self::FIELD_COGNITIVE_FUNCTION_DECOMPOSER__6_AXIS_,    self::FIELD_COGNITION,         AtlasCognitiveFunctionDecomposerService::class],
        [self::FIELD_ASPD,    self::FIELD_ATLAS_SWARM_PARALLEL_DISPATCHER,           self::FIELD_ATLAS_DECIDE,      AtlasSwarmParallelDispatchService::class],
        [self::FIELD_ACSR,    self::FIELD_COGNITIVE_FUNCTION_SWARM_ROUTER__P6_CLOSURE_, self::FIELD_ATLAS_DECIDE,   AtlasCognitiveFunctionSwarmRouterService::class],
        [self::FIELD_ASAF,    self::FIELD_SWARM_AUTO_FAILOVER__A4_,                  self::FIELD_ATLAS_DECIDE,      AtlasSwarmAutoFailoverService::class],
        [self::FIELD_ARDS,    self::FIELD_RUNTIME_DEGRADATION_SIGNAL_INGRESS,        self::FIELD_PATAMAR_4,         AtlasRuntimeDegradationSignalService::class],
        [self::FIELD_ASDM,    self::FIELD_SELF_DIVERGENCE_MODEL__TARGET_VS_CURRENT_, self::FIELD_SELF_CONSTRUCTION, AtlasSelfDivergenceModelService::class],
        [self::FIELD_AEMB,    self::FIELD_EMBODIMENT_INTEGRATION__P7_CLOSURE_,       self::FIELD_PATAMAR_4,         AtlasEmbodimentIntegrationService::class],

        // Patamar 1/2/3 closures — OCR confidence, Compounding L8/L9
        // COM-09: ACOP→ACRS bridge deleted — JSONL had 1 smoke signal (null value);
        // ARFL feedbackHint in ACRS already closes observability→ranking.
        [self::FIELD_AKIF_OCR, self::FIELD_AKIF_OCR_CONFIDENCE_SCORED_INGESTION, self::FIELD_AUCRI,           AtlasKnowledgeIngestionFabricOcrConfidenceService::class],
        [self::FIELD_ACL8,    self::FIELD_COMPOUNDING_LEVEL_8_9_DISTILLATION,   self::FIELD_COMPOUNDING,     AtlasCompoundingLevel8DistillationService::class],

        // Patamar 4 · intelligence boost
        [self::FIELD_ADTI4, self::FIELD_ATLAS_DECIDE_TEOS_I4_LOOKAHEAD,        self::FIELD_ATLAS_DECIDE,     AtlasDecideTeosI4LookaheadService::class],

        // Domain runtimes — Research (review-only, source-grounded)
        [self::FIELD_ARDR,  self::FIELD_RESEARCH_DOMAIN_RUNTIME,               self::FIELD_RESEARCH_DOMAIN, ResearchRuntimeService::class],
        [self::FIELD_ABDD,  self::FIELD_BDD_ACCEPTANCE_RUNTIME,                self::FIELD_PROGRAMMING,     AtlasBddAcceptanceRuntimeService::class],
        [self::FIELD_ALMR,  self::FIELD_LEARNING_MUTATION_RUNTIME,             self::FIELD_COMPOUNDING,     AtlasLearningMutationRuntimeService::class],
        [self::FIELD_APCP,  self::FIELD_PROGRAMMING_CARTOGRAPHY_PUBLISHER,     self::FIELD_PROGRAMMING,     AtlasProgrammingCartographyPublisherService::class],
    ];

    /**
     * Deep ACOS modules declared by the canonical architecture but historically
     * absent from the 73-row v3 facet inventory. They are emitted only in v4 so
     * v3 remains a compatibility surface while v4 becomes the truthful boundary.
     */
    public const V4_SUPPLEMENTAL_SUBSYSTEMS = [
        [self::FIELD_ACCCR, self::FIELD_CONTEXT_CACHE_COMPILER_RUNTIME, self::FIELD_CONTEXT_CACHE, AtlasContextCacheCompilerRuntimeService::class],
        [self::FIELD_ACIE, self::FIELD_CONTEXT_INTELLIGENCE_ENGINE, self::FIELD_CONTEXT_INTELLIGENCE, AtlasContextOperationsRuntimeService::class],
        [self::FIELD_APCR, self::FIELD_PERSISTENT_CONTEXT_RUNTIME, self::FIELD_PERSISTENT_CONTEXT, AtlasPersistentContextRuntimeService::class],
        [self::FIELD_AEMOR_2, self::FIELD_EXECUTION_MEMORY_OUTCOME_RUNTIME, self::FIELD_AEMOR, AtlasAemorCertificationService::class],
        [self::FIELD_TEOS_I1, self::FIELD_LONG_HORIZON_INTELLIGENCE_LAYER, self::FIELD_LONG_HORIZON, LongHorizonContinuityCertificationService::class],
        [self::FIELD_AVCEL, self::FIELD_VERIFIED_CONTEXT_EXECUTION_LOOP, self::FIELD_VERIFIED_CONTEXT, AtlasVerifiedContextExecutionLoopService::class],
        [self::FIELD_ACQCG, self::FIELD_CONTEXT_QUALITY_CERTIFICATION_GATE, self::FIELD_CONTEXT_QUALITY, AtlasContextQualityCertificationService::class],
        [self::FIELD_AOBG, self::FIELD_OPEN_BRAIN_GATEWAY, self::FIELD_OPEN_BRAIN, AtlasOpenBrainMcpService::class],
        [self::FIELD_EVIDENCE_2, self::FIELD_EVIDENCE_LEDGER_MEMORY_SIDE, self::FIELD_EVIDENCE, AtlasEvidenceLedger::class],
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
            self::FIELD_GENERATED_AT => UtcIsoTimestamp::now(),
            self::FIELD_SUBSYSTEM_COUNT => count($rows),
            self::FIELD_SCORED_SUBSYSTEM_COUNT => count(array_filter(
                $rows,
                static fn (array $row): bool => $row[self::FIELD_EVIDENCE_ALIAS_OF] === null,
            )),
            self::FIELD_SUBSYSTEMS => $rows,
            self::FIELD_SCORE => $score,
            self::FIELD_CLAIM_POLICY => $this->claimPolicy(),
            self::FIELD_NOTES => [
                self::FIELD_READINESS_DEFINITION => self::FIELD_A_UNIQUE_SERVICE_FACET_IS_READY_WHEN_CODE__DOC_AND_PIPELINE_ARE_READY__ALIAS_FACETS_REMAIN_VISIBLE_BUT_ARE_NOT_SCORED_TWICE__REAL_WORLD_VOLUME_REMAINS_SEPARATE_,
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
            self::FIELD_GENERATED_AT => $v3[self::FIELD_GENERATED_AT] ?? UtcIsoTimestamp::now(),
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
        $dimensions = [self::FIELD_CODE_STATUS, self::FIELD_DOC_STATUS, self::FIELD_PIPELINE_STATUS];
        $totals = [];
        foreach ($dimensions as $dim) {
            $sum = 0;
            $max = count($rows) * self::STATUS_POINTS[self::STATUS_READY];
            foreach ($rows as $r) {
                $sum += self::STATUS_POINTS[$r[$dim]] ?? 0;
            }
            $key = str_replace('_status', '', $dim);
            $totals[$key] = [
                self::FIELD_SUM => $sum,
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
            self::FIELD_RIVALS_CLAIM_ALLOWED => false,
            self::FIELD_SUPERIORITY_CLAIM_ALLOWED => false,
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

        return 'sha256:'.hash(self::FIELD_SHA256, json_encode([
            self::FIELD_SCHEMA => self::SCHEMA_VERSION,
            self::FIELD_ROWS => $canonical,
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
                self::FIELD_SUPPLEMENTAL => true,
                self::FIELD_CODE_STATUS => $this->probeCodeStatus($serviceClass),
                self::FIELD_DOC_STATUS => $this->evidence->resolveDocStatus($serviceClass),
                self::FIELD_PIPELINE_STATUS => $this->evidence->resolvePipelineStatus($serviceClass),
            ];
        }

        return $rows;
    }
}
