<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Foundry\FoundrySemanticGapFinderService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\CanonicalDocBacklogSection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\DeepFindingFactory;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\DeepFindingSupport;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\FactoryBacklogQualitySection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\FactoryRuntimeSection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\FindingSummarySection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\SemanticCapabilityGapSection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding\StrategicMultiplierSeeds;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\SelfTargetSelectorService;
use App\Services\Ai\SoftwareCompanyStewardship\Concerns\HasStewardshipStorageRoot;

/**
 * Software Company Stewardship Stack · Area Focus Loop ·
 * Area Focus Deep Finding Engine (Slice 11, AP-748).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, NOT a new OS.
 *
 * Purpose: the operator wants Atlas to run a 24h loop hunting bugs, gaps, risks,
 * improvements and missing implementations across the Atlas Agentic Engineering
 * OS — focused on the development flow (Atlas Dev + Forge). This engine is the
 * deep, focus-aware layer of the Area Focus Loop. Given
 * `area_id: agentic_engineering_os` and `focus: dev_forge` it:
 *
 *   1. COMPOSES the canonical structural scanner — the Agentic Engineering OS
 *      Finding Engine (AP-717) — so it never re-implements a parallel scanner.
 *   2. Adds focus-scoped deep checks the structural scanner does not own
 *      (focus owner-doc coverage, Dev/Forge handoff→executor wiring chain).
 *   3. Enriches every finding into a richer, decision-ready schema:
 *      kind / owner_candidate / why_it_matters / proposed_spec_title /
 *      proposed_next_action plus a Self-Directed-Evolution-compatible `spec_seed`
 *      ({@see atlas.evolution.gap_candidate.v1}) ready for the Spec Proposal
 *      Adapter — without ever drafting a spec here.
 *   4. Prioritises in-focus findings, dedupes by hash, caps to max_findings.
 *   5. Optionally records an append-only JSONL read-model (mode = record).
 *
 * Hard read-only invariants (mirror AP-716/AP-717/AP-720):
 *   - NEVER writes code/docs, NEVER opens a branch, NEVER drafts a spec/AP,
 *     NEVER invokes a provider, NEVER merges/deploys/touches secrets, NEVER
 *     mutates the target repo. The ONLY side effect is appending local runtime
 *     JSONL under storage/ when mode = record.
 *   - Self-Directed Evolution stays the gap/spec owner; Atlas Dev / Forge stay
 *     the executors. This engine only DETECTS and STRUCTURES findings + route
 *     hints; it never resolves them.
 *
 * Determinism: classification runs over normalized input. Production gathering
 * lives behind overridable seams (and a `base_report` override) so the core is
 * unit-testable with synthetic fixtures and the same input yields the same hash.
 *
 * GOD-DEBULK split: the single makeFinding pipeline lives in {@see DeepFindingFactory}
 * and the heavy deep-check families live in {@see SemanticCapabilityGapSection},
 * {@see CanonicalDocBacklogSection} and {@see FactoryRuntimeSection}. This façade
 * owns orchestration, structural composition, the smaller focus/wiring/inert
 * checks, persistence and the report envelope. Taxonomy constants stay here.
 */
class AreaFocusDeepFindingEngineService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_deep_scan.v1';

    public const FINDING_SCHEMA = 'atlas.software_company_stewardship.area_focus_deep_finding.v1';

    public const SPEC_SEED_SCHEMA = 'atlas.evolution.gap_candidate.v1';

    /** Canonical Doc Backlog Miner source + origin types. */
    public const SOURCE_CANONICAL_DOC_BACKLOG = 'canonical_doc_backlog';

    public const ORIGIN_TYPE_DOC_NEXT_ACTION = 'doc_next_action';

    public const ORIGIN_TYPE_DOC_ALLOWED_CHANGE = 'doc_allowed_change';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_RECORD = 'record';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_FOCUS = 'dev_forge';

    /** Canonical owner_candidate taxonomy. */
    public const OWNER_ATLAS_DEV = 'atlas_dev';

    public const OWNER_FORGE = 'forge';

    public const OWNER_AAEOS = 'aaeos';

    public const OWNER_SELF_DIRECTED_EVOLUTION = 'self_directed_evolution';

    public const OWNER_EVIDENCE = 'evidence';

    public const OWNER_PRODUCT_MODE = 'product_mode';

    /** @var list<string> */
    public const OWNER_CANDIDATES = [
        self::OWNER_ATLAS_DEV,
        self::OWNER_FORGE,
        self::OWNER_AAEOS,
        self::OWNER_SELF_DIRECTED_EVOLUTION,
        self::OWNER_EVIDENCE,
        self::OWNER_PRODUCT_MODE,
    ];

    /** Canonical kind taxonomy. */
    public const KIND_BUG = 'bug';

    public const KIND_GAP = 'gap';

    public const KIND_RISK = 'risk';

    public const KIND_IMPROVEMENT = 'improvement';

    public const KIND_IMPLEMENTATION = 'implementation';

    public const KIND_TEST = 'test';

    public const KIND_DOC = 'doc';

    public const KIND_RUNTIME = 'runtime';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_BUG,
        self::KIND_GAP,
        self::KIND_RISK,
        self::KIND_IMPROVEMENT,
        self::KIND_IMPLEMENTATION,
        self::KIND_TEST,
        self::KIND_DOC,
        self::KIND_RUNTIME,
    ];

    /**
     * Map AP-717 structural finding_type -> (kind, owner_candidate) for this
     * engine's richer taxonomy. owner_candidate is refined later by route_hint.
     *
     * @var array<string,array{kind:string,owner:string}>
     */
    private const STRUCTURAL_TYPE_MAP = [
        'docs_stale' => ['kind' => self::KIND_DOC, 'owner' => self::OWNER_SELF_DIRECTED_EVOLUTION],
        'missing_test' => ['kind' => self::KIND_TEST, 'owner' => self::OWNER_ATLAS_DEV],
        'failing_gate_hint' => ['kind' => self::KIND_BUG, 'owner' => self::OWNER_ATLAS_DEV],
        'weak_handoff' => ['kind' => self::KIND_GAP, 'owner' => self::OWNER_FORGE],
        'duplicate_runtime_risk' => ['kind' => self::KIND_RISK, 'owner' => self::OWNER_FORGE],
        'missing_evidence' => ['kind' => self::KIND_RISK, 'owner' => self::OWNER_EVIDENCE],
        'replay_gap' => ['kind' => self::KIND_GAP, 'owner' => self::OWNER_FORGE],
        'desktop_surface_gap' => ['kind' => self::KIND_GAP, 'owner' => self::OWNER_PRODUCT_MODE],
        'dev_forge_routing_gap' => ['kind' => self::KIND_GAP, 'owner' => self::OWNER_FORGE],
        'self_directed_spec_gap' => ['kind' => self::KIND_IMPLEMENTATION, 'owner' => self::OWNER_SELF_DIRECTED_EVOLUTION],
    ];

    /** route_hint (AP-717) -> owner_candidate fallback. */
    private const ROUTE_OWNER_MAP = [
        'self_directed_evolution' => self::OWNER_SELF_DIRECTED_EVOLUTION,
        'atlas_dev' => self::OWNER_ATLAS_DEV,
        'forge' => self::OWNER_FORGE,
        'operator_review' => self::OWNER_PRODUCT_MODE,
    ];

    /** @var array<string,int> */
    public const SEVERITY_RANK = [
        'critical' => 4,
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        'unknown' => 0,
    ];

    public const DOCS_ROOT = 'docs/engineering-knowledge-base/';

    public const STEWARDSHIP_ROOT = 'app/Services/Ai/SoftwareCompanyStewardship/';

    /**
     * Per-focus configuration. A focus narrows the loop to one slice of the area.
     * `dev_forge` is the operator's primary focus: the whole development flow that
     * runs through Atlas Dev (small/local) and Forge (long-horizon).
     *
     * @var array<string,array<string,mixed>>
     */
    private const FOCUS_REGISTRY = [
        self::DEFAULT_FOCUS => [
            'label' => 'Atlas Dev + Forge development flow',
            'owner_docs' => [
                self::DOCS_ROOT.'atlas-dev-efficient-programming-flow-v1.md',
                self::DOCS_ROOT.'atlas-forge-operating-system.md',
                self::DOCS_ROOT.'atlas-agentic-engineering-os.md',
                self::DOCS_ROOT.'atlas-agentic-software-engineering-authority-map.md',
            ],
            // The Dev/Forge handoff -> executor chain the Area Focus Loop must wire
            // end-to-end. A missing link is a real `handoff_executor_wiring_gap`.
            'wiring_chain' => [
                'release_queue' => self::STEWARDSHIP_ROOT.'AreaFocusLoop/AreaFocusDevForgeReleaseService.php',
                'consumption_gate' => self::STEWARDSHIP_ROOT.'AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php',
                'owner_runtime_execution' => self::STEWARDSHIP_ROOT.'StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php',
                'owner_sandbox_run' => self::STEWARDSHIP_ROOT.'StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php',
                'result_bridge' => self::STEWARDSHIP_ROOT.'StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php',
            ],
            // Tokens that mark a finding as "in focus" for dev_forge prioritisation.
            'tokens' => [
                'dev', 'forge', 'aaeos', 'agentic', 'programming', 'handoff',
                'router', 'release', 'obra', 'branch', 'sandbox', 'gate', 'spec',
            ],
        ],
    ];

    use HasStewardshipStorageRoot;

    private const STORAGE_SUBPATH = 'atlas/software_company_stewardship/area_focus_deep_scans';

    private readonly DeepFindingFactory $findingFactory;

    private readonly SemanticCapabilityGapSection $semanticCapabilityGapSection;

    private readonly CanonicalDocBacklogSection $canonicalDocBacklogSection;

    private readonly FactoryRuntimeSection $factoryRuntimeSection;

    public function __construct(
        private readonly AgenticEngineeringOsFindingEngineService $structuralEngine,
        private readonly DeepFindingSupport $deepFindingSupport = new DeepFindingSupport,
        private readonly FactoryBacklogQualitySection $factoryBacklogQualitySection = new FactoryBacklogQualitySection(new DeepFindingSupport),
        private readonly FindingSummarySection $findingSummarySection = new FindingSummarySection,
        ?CanonicalDocFrontmatterReader $canonicalDocReader = null,
        ?FoundrySemanticGapFinderService $semanticGapFinder = null,
        ?SelfTargetSelectorService $selfTargetSelector = null,
    ) {
        $this->findingFactory = new DeepFindingFactory($this->deepFindingSupport);
        $this->semanticCapabilityGapSection = new SemanticCapabilityGapSection(
            $this->findingFactory,
            $this->deepFindingSupport,
            $semanticGapFinder,
            $selfTargetSelector,
        );
        $this->canonicalDocBacklogSection = new CanonicalDocBacklogSection(
            $this->findingFactory,
            $canonicalDocReader,
        );
        $this->factoryRuntimeSection = new FactoryRuntimeSection(
            $this->findingFactory,
            $this->deepFindingSupport,
            $this->factoryBacklogQualitySection,
        );
    }

    /**
     * Run a deep, focus-aware scan of the area.
     *
     * Recognised `$input` keys:
     *   - area_id:        string  (default: agentic_engineering_os)
     *   - focus:          string  (default: dev_forge)
     *   - max_findings:   int     cap on emitted findings
     *   - mode:           'dry_run'|'record'  (default: dry_run)
     *   - record:         bool    convenience alias for mode = record
     *
     * Deterministic test seams (each bypasses a read-only gathering seam):
     *   - base_report:        array  full AP-717 report (bypasses the structural engine)
     *   - structural_input:   array  forwarded to the AP-717 engine
     *   - focus_owner_docs:   array<string,bool>  owner-doc presence override
     *   - wiring_chain:       array<string,bool>  chain-link presence override
     *   - terminal_backlog_state_hash: string  AP-790 terminal starvation state hash
     *   - terminal_backlog_rejection_reasons: list<string>  rejection reasons from factory_max
     *   - skip_atlas_dev_factory_runtime_bottlenecks: bool  skip Atlas Dev/factory bottleneck scan
     *   - atlas_dev_factory_bottleneck_signals: array<string,list<string>>  per-source signal override
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function scan(array $input = []): array
    {
        $areaId = $this->resolveAreaId($input);
        $focus = $this->resolveFocus($input);
        $mode = $this->resolveMode($input);

        if ($areaId !== self::DEFAULT_AREA_ID) {
            return $this->finalize($this->blocked($areaId, $focus, $mode, 'unsupported_area',
                "This deep engine only scans '".self::DEFAULT_AREA_ID."'."));
        }
        if (! array_key_exists($focus, self::FOCUS_REGISTRY)) {
            return $this->finalize($this->blocked($areaId, $focus, $mode, 'unsupported_focus',
                'Registered focuses: '.implode(', ', array_keys(self::FOCUS_REGISTRY)).'.'));
        }

        $focusConfig = self::FOCUS_REGISTRY[$focus];
        $blockers = [];
        $sources = [];

        // 1. Structural findings (AP-717), composed not duplicated.
        [$structural, $structuralSource, $structuralBlocker] = $this->resolveStructuralReport($areaId, $input);
        $sources['structural_engine'] = $structuralSource;
        if ($structuralBlocker !== null) {
            $blockers[] = $structuralBlocker;
        }

        $findings = [];
        $suppressedInterfaceMissingTests = 0;
        foreach (is_array($structural['findings'] ?? null) ? $structural['findings'] : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $enriched = $this->fromStructural($areaId, $focus, $raw, $focusConfig);
            if ($this->factoryBacklogQualitySection->isInterfaceOnlyFalsePositive($enriched, $this->factoryBacklogQualitySection->resolveAllowedFilesForFinding($enriched))) {
                $suppressedInterfaceMissingTests++;

                continue;
            }
            $findings[] = $enriched;
        }
        if ($suppressedInterfaceMissingTests > 0) {
            $sources['structural_engine']['suppressed_interface_missing_test_count'] = $suppressedInterfaceMissingTests;
        }

        // 2. Focus-scoped deep checks the structural engine does not own.
        [$docFindings, $docSource] = $this->checkFocusOwnerDocs($areaId, $focus, $focusConfig, $input);
        $sources['focus_owner_docs'] = $docSource;
        $findings = array_merge($findings, $docFindings);

        [$wiringFindings, $wiringSource] = $this->checkWiringChain($areaId, $focus, $focusConfig, $input);
        $sources['wiring_chain'] = $wiringSource;
        $findings = array_merge($findings, $wiringFindings);

        [$bottleneckFindings, $bottleneckSource] = $this->factoryRuntimeSection->checkAtlasDevFactoryRuntimeBottlenecks($areaId, $focus, $focusConfig, $input);
        $sources['atlas_dev_factory_runtime_bottlenecks'] = $bottleneckSource;
        $findings = array_merge($findings, $bottleneckFindings);

        [$runtimeCoverageFindings, $runtimeCoverageSource] = $this->factoryRuntimeSection->checkFactoryRuntimeCoverage($areaId, $focus, $focusConfig, $input);
        $sources['factory_runtime_coverage'] = $runtimeCoverageSource;
        $findings = array_merge($findings, $runtimeCoverageFindings);

        [$multiplierFindings, $multiplierSource] = $this->strategicMultiplierBacklog($areaId, $focus, $focusConfig, $input);
        $sources['strategic_multiplier_backlog'] = $multiplierSource;
        $findings = array_merge($findings, $multiplierFindings);

        [$inertWiringFindings, $inertWiringSource] = $this->checkInertWiringDebt($areaId, $focus, $focusConfig, $input);
        $sources['inert_wiring_debt'] = $inertWiringSource;
        $findings = array_merge($findings, $inertWiringFindings);

        [$docBacklogFindings, $docBacklogSource] = $this->canonicalDocBacklogSection->checkCanonicalDocBacklog($areaId, $focus, $focusConfig, $input);
        $sources['canonical_doc_backlog'] = $docBacklogSource;
        $findings = array_merge($findings, $docBacklogFindings);

        [$semanticGapFindings, $semanticGapSource] = $this->semanticCapabilityGapSection->checkDocumentedVsRuntimeCapabilityGaps($areaId, $focus, $focusConfig, $input);
        $sources['semantic_capability_gaps'] = $semanticGapSource;
        $findings = array_merge($findings, $semanticGapFindings);

        // 3. Dedupe, factory backlog quality (dev_forge), prioritise, cap.
        $findings = $this->findingSummarySection->dedupe($findings);
        $factoryRejections = [];
        $autonomousDocExec = ($input['autonomous_doc_backlog_execution'] ?? null) === true
            || (function_exists('config') && (bool) config('atlas.software_company_stewardship.autonomous_doc_backlog_execution', false) === true);
        if ($focus === self::DEFAULT_FOCUS && ($input['skip_factory_backlog_quality'] ?? false) !== true) {
            [$findings, $factoryRejections] = $this->factoryBacklogQualitySection->applyFactoryBacklogQuality($findings, $autonomousDocExec);
        }
        $findings = $this->findingSummarySection->sortFindings($findings);
        $maxFindings = $this->resolveMaxFindings($input);
        $capped = $maxFindings !== null && count($findings) > $maxFindings;
        if ($maxFindings !== null) {
            $findings = array_slice($findings, 0, $maxFindings);
        }

        $status = match (true) {
            ($structuralSource['available'] ?? false) === false => self::STATUS_BLOCKED,
            $blockers !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $scanId = $this->scanId($areaId, $focus, $findings);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'scan_id' => $scanId,
            'status' => $status,
            'mode' => $mode,
            'area_id' => $areaId,
            'focus' => $focus,
            'focus_label' => (string) ($focusConfig['label'] ?? $focus),
            'stewardship_stack' => $this->stewardshipStack(),
            'finding_count' => count($findings),
            'capped' => $capped,
            'findings' => $findings,
            'kind_summary' => $this->findingSummarySection->kindSummary($findings),
            'owner_summary' => $this->findingSummarySection->ownerSummary($findings),
            'severity_summary' => $this->findingSummarySection->severitySummary($findings),
            'focus_summary' => $this->findingSummarySection->focusSummary($findings),
            'factory_backlog_quality' => [
                'enabled' => $focus === self::DEFAULT_FOCUS && ($input['skip_factory_backlog_quality'] ?? false) !== true,
                'accepted_count' => count($findings),
                'rejected_count' => count($factoryRejections),
                'rejections' => $factoryRejections,
            ],
            'source_summary' => $sources,
            'blockers' => $blockers,
            'next_actions' => $this->findingSummarySection->nextActions($findings),
            'claim_policy' => $this->claimPolicy($mode),
        ];
        $payload = $this->finalize($payload);

        if ($mode === self::MODE_RECORD && $status !== self::STATUS_BLOCKED) {
            $payload['record'] = $this->recordScan($payload);
        }

        return $payload;
    }

    /**
     * List recorded deep scans for an area (newest last), corruption-tolerant.
     *
     * @return array<string,mixed>
     */
    public function listScans(string $areaId): array
    {
        [$records, $corrupted] = $this->readScans($this->scanFilePath($areaId));

        $summaries = [];
        foreach ($records as $record) {
            $summaries[] = [
                'scan_id' => (string) ($record['scan_id'] ?? ''),
                'focus' => (string) ($record['focus'] ?? ''),
                'status' => (string) ($record['status'] ?? 'unknown'),
                'finding_count' => (int) ($record['finding_count'] ?? 0),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'scan_hash' => (string) ($record['scan_hash'] ?? ''),
            ];
        }

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'area_id' => $areaId,
            'scan_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'scans' => $summaries,
        ];
    }

    /**
     * Replay a recorded deep scan by id (searches every area file).
     *
     * @return array<string,mixed>|null
     */
    public function replay(string $scanId): ?array
    {
        foreach ($this->areaFiles() as $file) {
            [$records] = $this->readScans($file);
            foreach ($records as $record) {
                if ((string) ($record['scan_id'] ?? '') === $scanId) {
                    return $record;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function registeredFocuses(): array
    {
        return array_keys(self::FOCUS_REGISTRY);
    }

    // ---------- structural composition ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function resolveStructuralReport(string $areaId, array $input): array
    {
        if (is_array($input['base_report'] ?? null)) {
            $report = $input['base_report'];

            return [$report, ['available' => true, 'source' => 'override', 'finding_count' => count((array) ($report['findings'] ?? []))], null];
        }

        $structuralInput = is_array($input['structural_input'] ?? null) ? $input['structural_input'] : [];
        $structuralInput['area_id'] = $areaId;
        $report = $this->structuralEngine->scan($structuralInput);

        $available = (string) ($report['status'] ?? '') !== AgenticEngineeringOsFindingEngineService::STATUS_BLOCKED
            || ($report['finding_count'] ?? 0) > 0;
        $blocker = null;
        if (($report['status'] ?? '') === AgenticEngineeringOsFindingEngineService::STATUS_BLOCKED
            && (int) ($report['finding_count'] ?? 0) === 0) {
            $available = false;
            $blocker = [
                'source' => 'structural_engine',
                'reason' => 'structural_scan_blocked',
                'detail' => 'AP-717 structural scan returned blocked; deep enrichment has no base findings.',
            ];
        }

        return [$report, [
            'available' => $available,
            'source' => 'ap717_structural_engine',
            'status' => (string) ($report['status'] ?? 'unknown'),
            'finding_count' => (int) ($report['finding_count'] ?? 0),
        ], $blocker];
    }

    /**
     * Enrich one AP-717 structural finding into the deep, decision-ready schema.
     *
     * @param  array<string,mixed>  $structural
     * @param  array<string,mixed>  $focusConfig
     * @return array<string,mixed>
     */
    private function fromStructural(string $areaId, string $focus, array $structural, array $focusConfig): array
    {
        $type = (string) ($structural['finding_type'] ?? 'unknown');
        $map = self::STRUCTURAL_TYPE_MAP[$type] ?? ['kind' => self::KIND_RISK, 'owner' => self::OWNER_PRODUCT_MODE];
        $route = (string) ($structural['route_hint'] ?? '');

        $owner = $this->ownerFromTypeAndRoute($type, $route, $map['owner']);
        $severity = AreaFocusScalarNormalizer::severityOrMedium((string) ($structural['severity'] ?? 'medium'));
        $confidence = $this->findingFactory->normalizeConfidence((string) ($structural['confidence'] ?? 'medium'));

        $affectedPaths = AreaFocusStringListNormalizer::coercedStringValues($structural['affected_paths'] ?? []);
        $evidence = AreaFocusStringListNormalizer::coercedStringValues($structural['evidence_refs'] ?? []);

        return $this->findingFactory->makeFinding([
            'area_id' => $areaId,
            'focus' => $focus,
            'origin' => 'structural_ap717',
            'origin_type' => $type,
            'source_ref' => (string) ($structural['finding_hash'] ?? ($type.':'.implode(',', $affectedPaths))),
            'title' => (string) ($structural['title'] ?? $type),
            'detail' => (string) ($structural['detail'] ?? ''),
            'kind' => $map['kind'],
            'owner_candidate' => $owner,
            'severity' => $severity,
            'confidence' => $confidence,
            'evidence_refs' => $evidence,
            'affected_paths' => $affectedPaths,
            'why_it_matters' => $this->findingFactory->whyItMatters($map['kind'], $owner, (string) ($structural['detail'] ?? '')),
            'proposed_next_action' => (string) ($structural['recommended_action'] ?? 'Operator review required.'),
        ], $focusConfig);
    }

    private function ownerFromTypeAndRoute(string $type, string $route, string $fallback): string
    {
        if ($type === 'missing_evidence') {
            return self::OWNER_EVIDENCE;
        }
        if ($route !== '' && isset(self::ROUTE_OWNER_MAP[$route])) {
            return self::ROUTE_OWNER_MAP[$route];
        }

        return $fallback;
    }

    // ---------- focus-scoped deep checks ----------

    /**
     * Focus owner-doc coverage: a missing canonical owner doc for the focus is a
     * concrete, evidence-backed `doc` finding routed to Self-Directed Evolution.
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private function checkFocusOwnerDocs(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        $ownerDocs = AreaFocusStringListNormalizer::coercedStringValues($focusConfig['owner_docs'] ?? []);
        $override = is_array($input['focus_owner_docs'] ?? null) ? $input['focus_owner_docs'] : null;

        $findings = [];
        $present = 0;
        foreach ($ownerDocs as $doc) {
            $exists = $override !== null && array_key_exists($doc, $override)
                ? (bool) $override[$doc]
                : $this->deepFindingSupport->pathExists($doc);
            if ($exists) {
                $present++;

                continue;
            }
            $findings[] = $this->findingFactory->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => 'deep_focus_owner_doc',
                'origin_type' => 'focus_owner_doc_missing',
                'source_ref' => 'focus_owner_doc_missing:'.$doc,
                'title' => 'Focus owner doc missing: '.basename($doc),
                'detail' => "The {$focus} focus declares this canonical owner doc but it is not present.",
                'kind' => self::KIND_DOC,
                'owner_candidate' => self::OWNER_SELF_DIRECTED_EVOLUTION,
                'severity' => 'high',
                'confidence' => 'high',
                'evidence_refs' => ['focus:'.$focus, 'missing_owner_doc:'.$doc],
                'affected_paths' => [$doc],
                'why_it_matters' => 'A missing focus owner doc means the area has no canonical source of truth to govern the development flow; every downstream finding loses its anchor.',
                'proposed_next_action' => 'Restore or author the canonical owner doc, then re-run the deep scan.',
            ], $focusConfig);
        }

        return [$findings, [
            'available' => true,
            'owner_doc_total' => count($ownerDocs),
            'owner_doc_present' => $present,
        ]];
    }

    /**
     * Dev/Forge handoff -> executor wiring chain: every link must exist for the
     * Area Focus Loop to actually deliver work to Atlas Dev / Forge. A missing
     * link is a real `handoff_executor_wiring_gap`.
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private function checkWiringChain(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        $chain = is_array($focusConfig['wiring_chain'] ?? null) ? $focusConfig['wiring_chain'] : [];
        $override = is_array($input['wiring_chain'] ?? null) ? $input['wiring_chain'] : null;

        $missing = [];
        $present = 0;
        foreach ($chain as $link => $path) {
            if (! is_string($path)) {
                continue;
            }
            $exists = $override !== null && array_key_exists($link, $override)
                ? (bool) $override[$link]
                : $this->deepFindingSupport->pathExists($path);
            if ($exists) {
                $present++;

                continue;
            }
            $missing[$link] = $path;
        }

        if ($missing === []) {
            return [[], ['available' => true, 'chain_total' => count($chain), 'chain_present' => $present, 'chain_complete' => true]];
        }

        $finding = $this->findingFactory->makeFinding([
            'area_id' => $areaId,
            'focus' => $focus,
            'origin' => 'deep_wiring_chain',
            'origin_type' => 'handoff_executor_wiring_gap',
            'source_ref' => 'handoff_executor_wiring_gap:'.implode(',', array_keys($missing)),
            'title' => 'Dev/Forge handoff→executor wiring gap',
            'detail' => count($missing).' link(s) of the Area Focus Dev/Forge handoff→executor chain are missing: '.implode(', ', array_keys($missing)).'.',
            'kind' => self::KIND_GAP,
            'owner_candidate' => self::OWNER_FORGE,
            'severity' => 'high',
            'confidence' => 'high',
            'evidence_refs' => array_map(static fn (string $l, string $p): string => 'missing_link:'.$l.':'.$p, array_keys($missing), array_values($missing)),
            'affected_paths' => array_values($missing),
            'why_it_matters' => 'Findings can be detected and routed, but without a complete handoff→executor chain nothing the loop produces actually reaches Atlas Dev or Forge for governed execution.',
            'proposed_next_action' => 'Implement the missing chain link(s) under operator review so released work orders are consumed by the owner runtime.',
        ], $focusConfig);

        return [[$finding], ['available' => true, 'chain_total' => count($chain), 'chain_present' => $present, 'chain_complete' => false]];
    }

    /**
     * Anti-inertia, the SAFE shape (operator mandate, 2026-05-29).
     *
     * The loop merges shape-only contracts (e.g. TheAdmissionDeficitReasonContract)
     * whose public accessor on the consumer service is NEVER called by any real
     * decision path — tested, merged, inert ("progress theater"). The wrong fix is
     * a per-cycle merge gate: it false-blocks legitimate TDD slices (method + test
     * now, caller next cycle). The RIGHT fix is a FINDING SOURCE: detect the inert
     * accessor and EMIT a "consume contract X in its decision" finding so the loop
     * does the wiring and actually delivers the promised behavior. A finding source
     * can never false-block; worst case it proposes wiring that already exists and
     * the cycle no-ops/blocks honestly.
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private function checkInertWiringDebt(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        // Default OFF (like the quality gate) so direct scan() callers / the test
        // suite stay byte-identical; the loop opts in via scan_inert_wiring_debt.
        $enabled = ($input['scan_inert_wiring_debt'] ?? false) === true
            || array_key_exists('inert_wiring_candidates', $input);
        if (! $enabled) {
            return [[], ['available' => true, 'enabled' => false, 'candidate_count' => 0, 'emitted_count' => 0]];
        }
        if (($input['skip_inert_wiring_debt'] ?? false) === true) {
            return [[], ['available' => true, 'skipped' => true, 'candidate_count' => 0, 'emitted_count' => 0]];
        }

        // Test seam: inject candidates to keep unit tests off the filesystem.
        $candidates = is_array($input['inert_wiring_candidates'] ?? null)
            ? $input['inert_wiring_candidates']
            : $this->discoverInertWiringDebt();

        $findings = $this->inertWiringFindings($candidates, $areaId, $focus, $focusConfig);

        return [$findings, [
            'available' => true,
            'candidate_count' => count($candidates),
            'emitted_count' => count($findings),
        ]];
    }

    /**
     * Pure emitter: a candidate with caller_count === 0 is inert → emit a wiring
     * finding. Deterministic, filesystem-free (unit-tested with injected candidates).
     *
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $focusConfig
     * @return list<array<string,mixed>>
     */
    private function inertWiringFindings(array $candidates, string $areaId, string $focus, array $focusConfig): array
    {
        $findings = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $accessor = trim((string) ($candidate['accessor'] ?? ''));
            $consumerClass = trim((string) ($candidate['consumer_class'] ?? ''));
            $consumerFile = trim((string) ($candidate['consumer_file'] ?? ''));
            $contract = trim((string) ($candidate['contract'] ?? ''));
            $contractFile = trim((string) ($candidate['contract_file'] ?? ''));
            $callerCount = (int) ($candidate['caller_count'] ?? 0);

            if ($accessor === '' || $consumerClass === '' || $contract === '' || $callerCount > 0) {
                continue; // wired (or already has a caller) => not inert => no finding
            }

            $findings[] = $this->findingFactory->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => 'deep_inert_wiring_debt',
                'origin_type' => 'inert_contract_no_caller',
                'source_ref' => 'inert_wiring:'.$consumerClass.'::'.$accessor,
                // NB: deliberately NOT a strategic capability verb ("wire"/"implement")
                // so the planner keeps this as a bounded single slice, not a big split.
                'title' => 'Consume inert contract '.$contract.' in '.$consumerClass.' decision path',
                'detail' => $consumerClass.'::'.$accessor.'() materializes '.$contract.' but has ZERO non-test callers — the contract was merged yet never wired into a real decision (inert / progress theater). Replace the inline logic in the consumer\'s decision so it actually consumes '.$contract.', proven by a test that exercises the decision (not just the contract shape).',
                'kind' => self::KIND_GAP,
                'owner_candidate' => self::OWNER_ATLAS_DEV,
                'severity' => 'medium',
                'confidence' => 'high',
                'evidence_refs' => ['inert_accessor:'.$consumerClass.'::'.$accessor.':zero_callers'],
                'affected_paths' => array_values(array_filter([$consumerFile, $contractFile], static fn (string $p): bool => $p !== '')),
                'why_it_matters' => 'Merged-but-unwired contracts are real, tested code that changes no behavior — the exact "shape without value" the operator flagged. Wiring delivers the promised capability and stops the loop from accreting inert backlog.',
                'proposed_next_action' => 'Wire '.$contract.' into '.$consumerClass.'\'s decision (replace the inline branch), add a test asserting the decision consumes the contract, so '.$accessor.'() gains a real caller.',
            ], $focusConfig);
        }

        return $findings;
    }

    /**
     * Best-effort discovery of inert contract accessors in the stewardship tree:
     * a public method whose body materializes a *Contract (::fromArray/::defaults)
     * but has zero `->method(` / `::method(` callers anywhere in the scanned tree
     * (excluding its own file and tests). Detector only — never blocks; imprecision
     * at worst proposes an already-wired contract (harmless).
     *
     * @return list<array<string,mixed>>
     */
    private function discoverInertWiringDebt(): array
    {
        $base = function_exists('base_path') ? base_path() : getcwd();
        $root = rtrim((string) $base, '/').'/app/Services/Ai/SoftwareCompanyStewardship';
        if (! is_dir($root)) {
            return [];
        }

        // 1. Read the stewardship PHP tree once (bounded), separating product vs test.
        $product = [];
        $callerBlob = '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $abs = $file->getPathname();
            $contents = @file_get_contents($abs);
            if (! is_string($contents)) {
                continue;
            }
            $rel = ltrim(str_replace((string) $base, '', $abs), '/');
            $callerBlob .= "\n".$contents; // callers may live in product OR test code
            if (! str_contains($rel, '/tests/') && ! str_ends_with($rel, 'Test.php') && ! str_contains($abs, '/tests/')) {
                $product[$rel] = $contents;
            }
        }

        // 2. For each product file, find public methods that materialize a *Contract.
        $candidates = [];
        foreach ($product as $rel => $contents) {
            if (! preg_match('/class\s+(\w+)/', $contents, $cm)) {
                continue;
            }
            $consumerClass = $cm[1];
            if (str_ends_with($consumerClass, 'Contract')) {
                continue; // the contract itself is not the consumer
            }
            if (! preg_match_all('/public\s+function\s+(\w+)\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s', $contents, $mm, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($mm as $m) {
                $accessor = $m[1];
                $body = $m[2];
                if (! preg_match('/(\w+Contract)::(?:fromArray|defaults)\s*\(/', $body, $cmatch)) {
                    continue;
                }
                $contract = $cmatch[1];
                // caller count: `->accessor(` or `::accessor(` outside this file.
                $selfCalls = substr_count($contents, '->'.$accessor.'(') + substr_count($contents, '::'.$accessor.'(');
                $allCalls = substr_count($callerBlob, '->'.$accessor.'(') + substr_count($callerBlob, '::'.$accessor.'(');
                $external = max(0, $allCalls - $selfCalls);
                if ($external > 0) {
                    continue; // has a real caller somewhere => wired
                }
                $contractFile = $this->locateContractFile($contract, $product);
                $candidates[] = [
                    'consumer_class' => $consumerClass,
                    'consumer_file' => $rel,
                    'accessor' => $accessor,
                    'contract' => $contract,
                    'contract_file' => $contractFile,
                    'caller_count' => 0,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string,string>  $product  rel path => contents
     */
    private function locateContractFile(string $contract, array $product): string
    {
        foreach ($product as $rel => $contents) {
            if (str_ends_with($rel, '/'.$contract.'.php') || str_ends_with($rel, $contract.'.php')) {
                return $rel;
            }
        }

        return '';
    }

    /**
     * Operator-authored multiplier material, expressed as bounded runtime/test
     * work so the loop has an explicit high-ROI order after ordinary findings.
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private function strategicMultiplierBacklog(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        if (($input['skip_strategic_multiplier_backlog'] ?? false) === true) {
            return [[], ['available' => true, 'skipped' => true, 'seed_count' => 0]];
        }

        $findings = [];
        $missingSource = [];
        foreach (StrategicMultiplierSeeds::ALL as $index => $seed) {
            $source = $seed['source'];
            if (! $this->deepFindingSupport->pathExists($source)) {
                $missingSource[] = $source;

                continue;
            }

            $testPath = $this->factoryBacklogQualitySection->expectedTestPath($seed['test'], [$source]);
            $finding = $this->findingFactory->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => 'strategic_multiplier_backlog',
                'origin_type' => $seed['id'],
                'source_ref' => 'strategic_multiplier_backlog:'.$seed['id'],
                'title' => $seed['title'],
                'detail' => $seed['detail'].' Jump: '.$seed['jump'].' Attention: '.$seed['attention'],
                'kind' => $seed['kind'],
                'owner_candidate' => $seed['owner'],
                'severity' => $seed['severity'],
                'confidence' => 'high',
                'evidence_refs' => [
                    'strategic_multiplier:'.$seed['tier'],
                    'impl:'.$source,
                    'expected_test:'.$seed['test'],
                ],
                'affected_paths' => [$source],
                'why_it_matters' => $seed['jump'].' '.$seed['detail'],
                'proposed_next_action' => 'Implement the next bounded slice for '.$seed['tier'].' in '.$source.' and prove it with php artisan test '.$testPath.'.',
            ], $focusConfig);

            $finding['multiplier_tier'] = $seed['tier'];
            $finding['multiplier_order'] = $index + 1;
            $finding['multiplier_jump'] = $seed['jump'];
            $finding['multiplier_attention'] = $seed['attention'];
            $findings[] = $finding;
        }

        return [$findings, [
            'available' => true,
            'seed_count' => count(StrategicMultiplierSeeds::ALL),
            'emitted_count' => count($findings),
            'missing_source_count' => count($missingSource),
            'missing_sources' => $missingSource,
            'order' => array_map(static fn (array $seed): string => $seed['tier'].': '.$seed['title'], StrategicMultiplierSeeds::ALL),
        ]];
    }

    // ---------- persistence (record mode) ----------

    /**
     * Append the deep scan as an idempotent JSONL read-model record.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function recordScan(array $payload): array
    {
        $areaId = (string) $payload['area_id'];
        $scanId = (string) $payload['scan_id'];
        $path = $this->scanFilePath($areaId);

        $existing = $this->findInFile($path, $scanId);
        if ($existing !== null) {
            return [
                'recorded' => false,
                'idempotent' => true,
                'scan_id' => $scanId,
                'path' => $path,
                'recorded_at' => (string) ($existing['recorded_at'] ?? ''),
            ];
        }

        $record = $payload;
        unset($record['record']);
        $record['recorded_at'] = AreaFocusUtcClock::atomNow();
        AreaFocusAppendOnlyJsonlRecorder::append($path, $record);

        return [
            'recorded' => true,
            'idempotent' => false,
            'scan_id' => $scanId,
            'path' => $path,
            'recorded_at' => $record['recorded_at'],
        ];
    }

    public function scanFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerUnderscoreToken(
            $areaId,
            'unknown_area',
            trimInput: false,
            trimBoundaryUnderscores: false,
        ).'.jsonl';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $scanId): ?array
    {
        [$records] = $this->readScans($path);
        foreach ($records as $record) {
            if ((string) ($record['scan_id'] ?? '') === $scanId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readScans(string $path): array
    {
        return AreaFocusJsonlReader::rowsWithStringKey($path, 'scan_id');
    }

    /**
     * @return list<string>
     */
    private function areaFiles(): array
    {
        $dir = $this->storageDir();
        if (! is_dir($dir)) {
            return [];
        }

        return AreaFocusStringListNormalizer::coercedStringValues(glob($dir.DIRECTORY_SEPARATOR.'*.jsonl'));
    }

    // ---------- input resolution / read-only seam ----------

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveAreaId(array $input): string
    {
        $areaId = is_string($input['area_id'] ?? null) ? trim((string) $input['area_id']) : '';

        return $areaId !== '' ? $areaId : self::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveFocus(array $input): string
    {
        $focus = is_string($input['focus'] ?? null) ? trim((string) $input['focus']) : '';

        return $focus !== '' ? $focus : self::DEFAULT_FOCUS;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveMode(array $input): string
    {
        if (($input['record'] ?? false) === true) {
            return self::MODE_RECORD;
        }
        $mode = is_string($input['mode'] ?? null) ? strtolower(trim((string) $input['mode'])) : '';

        return $mode === self::MODE_RECORD ? self::MODE_RECORD : self::MODE_DRY_RUN;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveMaxFindings(array $input): ?int
    {
        foreach (['max_findings', 'limit'] as $key) {
            if (array_key_exists($key, $input) && is_numeric($input[$key])) {
                return max(1, (int) $input[$key]);
            }
        }

        return null;
    }

    // ---------- envelope / policy ----------

    /**
     * @param  list<array<string,mixed>>  $findings
     */
    private function scanId(string $areaId, string $focus, array $findings): string
    {
        $hashes = array_map(static fn (array $f): string => (string) ($f['finding_hash'] ?? ''), $findings);
        $raw = hash('sha256', implode('|', array_merge([$areaId, $focus], $hashes)));

        return 'afds_'.substr($raw, 0, 16);
    }

    /**
     * @return array<string,string>
     */
    private function stewardshipStack(): array
    {
        return [
            'stack' => 'Atlas Software Company Stewardship Stack',
            'level' => 'Area Focus Loop',
            'capability' => 'area_focus_deep_finding_engine',
            'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
            'composes' => 'AgenticEngineeringOsFindingEngineService (AP-717)',
            'note' => 'Atlas Software Company Stewardship Stack is a stack/capability family inside the Atlas Autonomous Software Company Runtime, not a new OS.',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['scan_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $focus, string $mode, string $reason, string $detail): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'scan_id' => $this->scanId($areaId, $focus, []),
            'status' => self::STATUS_BLOCKED,
            'mode' => $mode,
            'area_id' => $areaId,
            'focus' => $focus,
            'finding_count' => 0,
            'findings' => [],
            'kind_summary' => array_fill_keys(self::KINDS, 0),
            'owner_summary' => array_fill_keys(self::OWNER_CANDIDATES, 0),
            'severity_summary' => [],
            'focus_summary' => ['in_focus' => 0, 'out_of_focus' => 0],
            'source_summary' => [],
            'blockers' => [['source' => 'area_focus_deep_finding_engine', 'reason' => $reason, 'detail' => $detail]],
            'next_actions' => ['Resolve the blocker before running the deep scan.'],
            'claim_policy' => $this->claimPolicy($mode),
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(string $mode): array
    {
        return [
            'read_only_over_repo' => true,
            'writes_repo' => false,
            'writes_code' => false,
            'mutates_target_repo' => false,
            'writes_local_state' => $mode === self::MODE_RECORD,
            'persistence' => $mode === self::MODE_RECORD ? 'jsonl_append_only' : 'none',
            'provider_invoked' => false,
            'opens_branch' => false,
            'drafts_spec' => false,
            'creates_doc' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'runs_heavy_commands' => false,
            'autoapproval_allowed' => false,
            'auto_execution_allowed' => false,
            'external_side_effect_allowed' => false,
            'parallel_runtime_created' => false,
            'parallel_finding_detector_created' => false,
            'composes_ap717_structural_engine' => true,
            'is_new_os' => false,
            'self_directed_evolution_remains_gap_owner' => true,
            'operator_review_required' => true,
        ];
    }
}
