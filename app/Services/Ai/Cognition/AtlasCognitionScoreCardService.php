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
        ['G0',     'Raw Capture Layer',           self::FIELD_COGNITIVE_IMMUNE, AtlasAemorRuntimeService::class],
        ['G1',     'Evidence Promotion Gate',     self::FIELD_COGNITIVE_IMMUNE, AtlasAemorRuntimeService::class],
        ['G2',     'Learning Signal Extraction',  self::FIELD_COGNITIVE_IMMUNE, AtlasLearningDistiller::class],
        ['G3',     'Memory Promotion',            self::FIELD_COGNITIVE_IMMUNE, AiMemoryDeltaProposer::class],
        ['G4',     'Context Gate',                self::FIELD_COGNITIVE_IMMUNE, AtlasContextFreshnessQualityGateService::class],
        ['G5',     'Decision Gate',               self::FIELD_COGNITIVE_IMMUNE, AiDecisionReceiptRefreshService::class],
        ['G6',     'Outcome Replay',              self::FIELD_COGNITIVE_IMMUNE, AtlasAemorJudgmentService::class],
        ['G7',     'Self-Improvement Loop',       self::FIELD_COGNITIVE_IMMUNE, AtlasSelfImprovementOrchestrator::class],
        ['G8',     'Compounding Effect',          self::FIELD_COGNITIVE_IMMUNE, AtlasCompoundingRuntimeService::class],

        // Memory Core (3)
        [self::FIELD_MEM_CORE,   'Memory Core (entries+relations)', self::FIELD_MEMORY_CORE, AtlasMemoryConflictResolutionService::class],
        [self::FIELD_MEM_DELTA,  'Memory Delta Proposer',           self::FIELD_MEMORY_CORE, AiMemoryDeltaProposer::class],
        [self::FIELD_MEM_RECALL, 'Memory Recall (hybrid)',          self::FIELD_MEMORY_CORE, AtlasHybridRetrievalInfrastructureService::class],

        // AUCRI 18 blocks
        [self::FIELD_ASEF,  'Semantic Embedding Foundation',     self::FIELD_AUCRI, AtlasSemanticEmbeddingFoundationService::class],
        [self::FIELD_AHRI,  'Hybrid Retrieval Infrastructure',   self::FIELD_AUCRI, AtlasHybridRetrievalInfrastructureService::class],
        [self::FIELD_AARF,  'Agentic RAG Framework',             self::FIELD_AUCRI, AtlasAgenticRagFrameworkService::class],
        [self::FIELD_ACRS,  'Context Ranking System',            self::FIELD_AUCRI, AtlasContextRankingSystemService::class],
        [self::FIELD_ACFQ,  'Context Freshness Quality Gate',    self::FIELD_AUCRI, AtlasContextFreshnessQualityGateService::class],
        [self::FIELD_ARFL,  'Retrieval Feedback Loop',           self::FIELD_AUCRI, AtlasRetrievalFeedbackLoopService::class],
        [self::FIELD_AGRN,  'Graph Retrieval Network',           self::FIELD_AUCRI, AtlasGraphRetrievalNetworkService::class],
        [self::FIELD_AURG,  'Unified Reality Graph',             self::FIELD_AUCRI, AtlasUnifiedRealityGraphService::class],
        [self::FIELD_APDR,  'Python Data Retrieval Runtime',     self::FIELD_AUCRI, AtlasPythonDataRetrievalRuntimeService::class],
        [self::FIELD_AREBA, 'Retrieval Evaluation Arena',        self::FIELD_AUCRI, AtlasRetrievalEvaluationBenchmarkArenaService::class],
        [self::FIELD_ARCLG, 'Retrieval Cost Latency Governor',   self::FIELD_AUCRI, AtlasRetrievalCostLatencyGovernorService::class],
        [self::FIELD_ACOP,  'Context Observability Plane',       self::FIELD_AUCRI, AtlasContextObservabilityPlaneService::class],
        [self::FIELD_ARPTL, 'Retrieval Privacy Trust Layer',     self::FIELD_AUCRI, AtlasRetrievalPrivacyTrustLayerService::class],
        [self::FIELD_AKIF,  'Knowledge Ingestion Fabric',        self::FIELD_AUCRI, AtlasKnowledgeSourcePacketRegistryService::class],
        [self::FIELD_ACMF,  'Cognitive Memory Fabric',           self::FIELD_AUCRI, AtlasCognitiveMemoryFabricService::class],
        [self::FIELD_ACCR,  'Context Compiler Runtime',          self::FIELD_AUCRI, AtlasContextCompilerRuntimeService::class],
        [self::FIELD_ATER,  'Token Economy Runtime',             self::FIELD_AUCRI, AtlasTokenEconomyBudgetPolicyService::class],
        [self::FIELD_ACPFR, 'Context Pareto Frontier Runtime',   self::FIELD_AUCRI, AtlasContextParetoFrontierRuntimeService::class],

        // Self-Improvement L7 (closed loop)
        [self::FIELD_ASI_L7, 'Self-Improvement Closed Loop L7', self::FIELD_SELF_IMPROVEMENT, AtlasSelfImprovementResultLedgerService::class],

        // Patamar 2/3 — meta-learning, self-construction, AURG-4D, cross-domain mesh, TEOS-I3
        [self::FIELD_ADML,    'Atlas Decide Meta-Learning',          self::FIELD_ATLAS_DECIDE,      AtlasDecideMetaLearningService::class],
        [self::FIELD_ASCB,    'Self-Construction Subsystem Builder', self::FIELD_SELF_CONSTRUCTION, AtlasSelfConstructionSubsystemBuilderService::class],
        [self::FIELD_AURG_4_D, 'Unified Reality Graph Temporal (4D)', self::FIELD_REALITY,           AtlasUnifiedRealityGraphTemporalService::class],
        [self::FIELD_ACDM,    'Cross-Domain Mesh',                   self::FIELD_CROSS_DOMAIN,      AtlasCrossDomainMeshService::class],
        [self::FIELD_TEOS_I3, 'TEOS-I3 Counterfactual Runtime',      self::FIELD_TEOS,              AtlasTeosI3CounterfactualService::class],

        // Patamar 4 — Constitutional Kernel, Autonomy Admission, CognitiveFunctionAtlas, Reconciliation Runtime, TEOS-I4, Swarm Conductor, Temporary Domain Composition
        [self::FIELD_ACK,     'Constitutional Kernel',               self::FIELD_GOVERNANCE,        AtlasConstitutionalKernelService::class],
        [self::FIELD_AAA,     'Autonomy Admission',                  self::FIELD_GOVERNANCE,        AtlasAutonomyAdmissionService::class],
        [self::FIELD_ACFA,    'Cognitive Function Atlas',            self::FIELD_COGNITION,         AtlasCognitiveFunctionAtlasService::class],
        [self::FIELD_AARR,    'Autonomous Reconciliation Runtime',   self::FIELD_AUTONOMY,          AtlasAutonomousReconciliationRuntimeService::class],
        [self::FIELD_TEOS_I4, 'TEOS-I4 Counterfactual Tree',         self::FIELD_TEOS,              AtlasTeosI4CounterfactualTreeService::class],
        [self::FIELD_ASWC,    'Swarm Conductor',                     self::FIELD_ATLAS_DECIDE,      AtlasSwarmConductorService::class],
        [self::FIELD_ASWE,    'Swarm Executor',                      self::FIELD_ATLAS_DECIDE,      AtlasSwarmExecutorService::class],
        [self::FIELD_ATDC,    'Temporary Domain Composition',        self::FIELD_CROSS_DOMAIN,      AtlasTemporaryDomainCompositionService::class],

        // Patamar 4 · integration layer
        [self::FIELD_ADGW,    'Atlas Decide Gateway Consultation',   self::FIELD_ATLAS_DECIDE,      AtlasDecideGatewayConsultationService::class],
        [self::FIELD_ADLF,    'Atlas Decide Live Outcome Feedback',  self::FIELD_ATLAS_DECIDE,      AtlasDecideLiveOutcomeFeedbackService::class],
        [self::FIELD_AACM,    'Antifragility Composition Metric',    self::FIELD_COMPOUNDING,       AtlasAntifragilityCompositionMetricService::class],
        [self::FIELD_ACMF_SE, 'Cognitive Memory Fabric Schema Evolution', self::FIELD_AUCRI,         AtlasCognitiveMemoryFabricSchemaEvolutionService::class],
        [self::FIELD_ASCB_EX, 'Self-Construction Scaffold Staging Executor', self::FIELD_SELF_CONSTRUCTION, AtlasSelfConstructionScaffoldStagingExecutorService::class],
        [self::FIELD_ASCB_PP, 'Self-Construction Promotion Plan',          self::FIELD_SELF_CONSTRUCTION, AtlasSelfConstructionPromotionPlanService::class],
        [self::FIELD_ACTG,    'Cartography Truth Guard',                   self::FIELD_CARTOGRAPHY,       CartographyTruthGuardService::class],
        [self::FIELD_AGPF,    'Atlas Gateway Preflight (TEOS-I4)',         self::FIELD_ATLAS_DECIDE,      AtlasGatewayPreflightService::class],
        [self::FIELD_ACVS,    'Constitutional Vault Service',              self::FIELD_GOVERNANCE,        AtlasConstitutionalVaultService::class],
        [self::FIELD_ATBS,    'Trust Budget Service',                      self::FIELD_GOVERNANCE,        AtlasTrustBudgetService::class],
        [self::FIELD_ANCF,    'Nightly Counterfactuals',                   self::FIELD_PATAMAR_4,         AtlasNightlyCounterfactualsService::class],
        [self::FIELD_ASAR,    'Subsystem Auto-Rebalance',                  self::FIELD_PATAMAR_4,         AtlasSubsystemAutoRebalanceService::class],
        [self::FIELD_ASOS,    'Atlas Scheduler OS (Cron 24/7)',            self::FIELD_PATAMAR_4,         AtlasSchedulerHealthService::class],
        [self::FIELD_ASPR,    'Swarm Production Resolver (real provider)', self::FIELD_ATLAS_DECIDE,      AtlasSwarmProductionResolverService::class],
        [self::FIELD_ACFD,    'Cognitive Function Decomposer (6-axis)',    self::FIELD_COGNITION,         AtlasCognitiveFunctionDecomposerService::class],
        [self::FIELD_ASPD,    'Atlas Swarm Parallel Dispatcher',           self::FIELD_ATLAS_DECIDE,      AtlasSwarmParallelDispatchService::class],
        [self::FIELD_ACSR,    'Cognitive Function Swarm Router (P6 closure)', self::FIELD_ATLAS_DECIDE,   AtlasCognitiveFunctionSwarmRouterService::class],
        [self::FIELD_ASAF,    'Swarm Auto-Failover (A4)',                  self::FIELD_ATLAS_DECIDE,      AtlasSwarmAutoFailoverService::class],
        [self::FIELD_ARDS,    'Runtime Degradation Signal Ingress',        self::FIELD_PATAMAR_4,         AtlasRuntimeDegradationSignalService::class],
        [self::FIELD_ASDM,    'Self-Divergence Model (target vs current)', self::FIELD_SELF_CONSTRUCTION, AtlasSelfDivergenceModelService::class],
        [self::FIELD_AEMB,    'Embodiment Integration (P7 closure)',       self::FIELD_PATAMAR_4,         AtlasEmbodimentIntegrationService::class],

        // Patamar 1/2/3 closures — OCR confidence, Compounding L8/L9
        // COM-09: ACOP→ACRS bridge deleted — JSONL had 1 smoke signal (null value);
        // ARFL feedbackHint in ACRS already closes observability→ranking.
        [self::FIELD_AKIF_OCR, 'AKIF OCR Confidence-Scored Ingestion', self::FIELD_AUCRI,           AtlasKnowledgeIngestionFabricOcrConfidenceService::class],
        [self::FIELD_ACL8,    'Compounding Level 8/9 Distillation',   self::FIELD_COMPOUNDING,     AtlasCompoundingLevel8DistillationService::class],

        // Patamar 4 · intelligence boost
        [self::FIELD_ADTI4, 'Atlas Decide TEOS-I4 Lookahead',        self::FIELD_ATLAS_DECIDE,     AtlasDecideTeosI4LookaheadService::class],

        // Domain runtimes — Research (review-only, source-grounded)
        [self::FIELD_ARDR,  'Research Domain Runtime',               self::FIELD_RESEARCH_DOMAIN, ResearchRuntimeService::class],
        [self::FIELD_ABDD,  'BDD Acceptance Runtime',                self::FIELD_PROGRAMMING,     AtlasBddAcceptanceRuntimeService::class],
        [self::FIELD_ALMR,  'Learning Mutation Runtime',             self::FIELD_COMPOUNDING,     AtlasLearningMutationRuntimeService::class],
        [self::FIELD_APCP,  'Programming Cartography Publisher',     self::FIELD_PROGRAMMING,     AtlasProgrammingCartographyPublisherService::class],
    ];

    /**
     * Deep ACOS modules declared by the canonical architecture but historically
     * absent from the 73-row v3 facet inventory. They are emitted only in v4 so
     * v3 remains a compatibility surface while v4 becomes the truthful boundary.
     */
    public const V4_SUPPLEMENTAL_SUBSYSTEMS = [
        [self::FIELD_ACCCR, 'Context Cache Compiler Runtime', self::FIELD_CONTEXT_CACHE, AtlasContextCacheCompilerRuntimeService::class],
        [self::FIELD_ACIE, 'Context Intelligence Engine', self::FIELD_CONTEXT_INTELLIGENCE, AtlasContextOperationsRuntimeService::class],
        [self::FIELD_APCR, 'Persistent Context Runtime', self::FIELD_PERSISTENT_CONTEXT, AtlasPersistentContextRuntimeService::class],
        [self::FIELD_AEMOR_2, 'Execution Memory Outcome Runtime', self::FIELD_AEMOR, AtlasAemorCertificationService::class],
        [self::FIELD_TEOS_I1, 'Long-Horizon Intelligence Layer', self::FIELD_LONG_HORIZON, LongHorizonContinuityCertificationService::class],
        [self::FIELD_AVCEL, 'Verified Context Execution Loop', self::FIELD_VERIFIED_CONTEXT, AtlasVerifiedContextExecutionLoopService::class],
        [self::FIELD_ACQCG, 'Context Quality Certification Gate', self::FIELD_CONTEXT_QUALITY, AtlasContextQualityCertificationService::class],
        [self::FIELD_AOBG, 'Open Brain Gateway', self::FIELD_OPEN_BRAIN, AtlasOpenBrainMcpService::class],
        [self::FIELD_EVIDENCE_2, 'Evidence Ledger Memory Side', self::FIELD_EVIDENCE, AtlasEvidenceLedger::class],
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
