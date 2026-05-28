<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

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
 */
class AreaFocusDeepFindingEngineService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_deep_scan.v1';

    public const FINDING_SCHEMA = 'atlas.software_company_stewardship.area_focus_deep_finding.v1';

    public const SPEC_SEED_SCHEMA = 'atlas.evolution.gap_candidate.v1';

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

    /** owner_candidate -> Self-Directed-Evolution spec_seed gap_kind. */
    private const OWNER_SPEC_GAP_KIND = [
        self::OWNER_ATLAS_DEV => 'pipeline_not_proven',
        self::OWNER_FORGE => 'partial_canon',
        self::OWNER_AAEOS => 'partial_canon',
        self::OWNER_SELF_DIRECTED_EVOLUTION => 'missing_service_class',
        self::OWNER_EVIDENCE => 'pipeline_not_proven',
        self::OWNER_PRODUCT_MODE => 'partial_canon',
    ];

    /** @var array<string,int> */
    private const SEVERITY_RANK = [
        'critical' => 4,
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        'unknown' => 0,
    ];

    /** @var array<string,float> */
    private const CONFIDENCE_SCORE = [
        'high' => 0.9,
        'medium' => 0.6,
        'low' => 0.3,
    ];

    private const DOCS_ROOT = 'docs/engineering-knowledge-base/';

    private const STEWARDSHIP_ROOT = 'app/Services/Ai/SoftwareCompanyStewardship/';

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

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AgenticEngineeringOsFindingEngineService $structuralEngine,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
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
        foreach (is_array($structural['findings'] ?? null) ? $structural['findings'] : [] as $raw) {
            if (is_array($raw)) {
                $findings[] = $this->fromStructural($areaId, $focus, $raw, $focusConfig);
            }
        }

        // 2. Focus-scoped deep checks the structural engine does not own.
        [$docFindings, $docSource] = $this->checkFocusOwnerDocs($areaId, $focus, $focusConfig, $input);
        $sources['focus_owner_docs'] = $docSource;
        $findings = array_merge($findings, $docFindings);

        [$wiringFindings, $wiringSource] = $this->checkWiringChain($areaId, $focus, $focusConfig, $input);
        $sources['wiring_chain'] = $wiringSource;
        $findings = array_merge($findings, $wiringFindings);

        [$runtimeCoverageFindings, $runtimeCoverageSource] = $this->checkFactoryRuntimeCoverage($areaId, $focus, $focusConfig, $input);
        $sources['factory_runtime_coverage'] = $runtimeCoverageSource;
        $findings = array_merge($findings, $runtimeCoverageFindings);

        [$multiplierFindings, $multiplierSource] = $this->strategicMultiplierBacklog($areaId, $focus, $focusConfig, $input);
        $sources['strategic_multiplier_backlog'] = $multiplierSource;
        $findings = array_merge($findings, $multiplierFindings);

        // 3. Dedupe, factory backlog quality (dev_forge), prioritise, cap.
        $findings = $this->dedupe($findings);
        $factoryRejections = [];
        if ($focus === self::DEFAULT_FOCUS && ($input['skip_factory_backlog_quality'] ?? false) !== true) {
            [$findings, $factoryRejections] = $this->applyFactoryBacklogQuality($findings);
        }
        $findings = $this->sortFindings($findings);
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
            'kind_summary' => $this->kindSummary($findings),
            'owner_summary' => $this->ownerSummary($findings),
            'severity_summary' => $this->severitySummary($findings),
            'focus_summary' => $this->focusSummary($findings),
            'factory_backlog_quality' => [
                'enabled' => $focus === self::DEFAULT_FOCUS && ($input['skip_factory_backlog_quality'] ?? false) !== true,
                'accepted_count' => count($findings),
                'rejected_count' => count($factoryRejections),
                'rejections' => $factoryRejections,
            ],
            'source_summary' => $sources,
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($findings),
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
        $severity = $this->normalizeSeverity((string) ($structural['severity'] ?? 'medium'));
        $confidence = $this->normalizeConfidence((string) ($structural['confidence'] ?? 'medium'));

        $affectedPaths = array_values(array_filter((array) ($structural['affected_paths'] ?? []), 'is_string'));
        $evidence = array_values(array_filter((array) ($structural['evidence_refs'] ?? []), 'is_string'));

        return $this->makeFinding([
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
            'why_it_matters' => $this->whyItMatters($map['kind'], $owner, (string) ($structural['detail'] ?? '')),
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
        $ownerDocs = array_values(array_filter((array) ($focusConfig['owner_docs'] ?? []), 'is_string'));
        $override = is_array($input['focus_owner_docs'] ?? null) ? $input['focus_owner_docs'] : null;

        $findings = [];
        $present = 0;
        foreach ($ownerDocs as $doc) {
            $exists = $override !== null && array_key_exists($doc, $override)
                ? (bool) $override[$doc]
                : $this->pathExists($doc);
            if ($exists) {
                $present++;

                continue;
            }
            $findings[] = $this->makeFinding([
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
                : $this->pathExists($path);
            if ($exists) {
                $present++;

                continue;
            }
            $missing[$link] = $path;
        }

        if ($missing === []) {
            return [[], ['available' => true, 'chain_total' => count($chain), 'chain_present' => $present, 'chain_complete' => true]];
        }

        $finding = $this->makeFinding([
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
     * AP-790 needs a deep backlog, not a tiny curated list. This read-only sweep
     * turns existing high-leverage factory runtime classes without same-name
     * tests into executable missing-test findings. It deliberately excludes DTOs,
     * contracts, interfaces, traits and abstract classes so the loop does not
     * burn provider cycles on structural false positives.
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private function checkFactoryRuntimeCoverage(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        if (($input['skip_factory_runtime_coverage'] ?? false) === true) {
            return [[], ['available' => true, 'skipped' => true, 'candidate_count' => 0]];
        }

        $files = is_array($input['factory_runtime_coverage_files'] ?? null)
            ? $this->stringList($input['factory_runtime_coverage_files'])
            : $this->discoverFactoryRuntimeCoverageFiles();

        $findings = [];
        $skippedCovered = 0;
        $skippedNonRuntime = 0;
        foreach ($files as $file) {
            if (! $this->isFactoryRuntimeCoverageCandidate($file)) {
                $skippedNonRuntime++;

                continue;
            }
            $test = $this->expectedTestPath(basename($file, '.php').'Test.php', [$file]);
            if ($test === '' || $this->pathExists($test)) {
                $skippedCovered++;

                continue;
            }

            $class = basename($file, '.php');
            $findings[] = $this->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => 'factory_runtime_coverage_sweep',
                'origin_type' => 'missing_test',
                'source_ref' => 'factory_runtime_coverage_sweep:'.$file,
                'title' => 'Missing test for '.$class,
                'detail' => $class.' is a factory-critical runtime class in the AAEOS / Atlas Dev / Forge flow without same-name focused coverage.',
                'kind' => self::KIND_TEST,
                'owner_candidate' => self::OWNER_ATLAS_DEV,
                'severity' => 'medium',
                'confidence' => 'high',
                'evidence_refs' => [
                    'factory_runtime_coverage_sweep:'.$file,
                    'impl:'.$file,
                    'expected_test:'.basename($test),
                ],
                'affected_paths' => [$file],
                'why_it_matters' => 'The autonomous software factory cannot run safely for many cycles if core Dev/Forge/stewardship runtimes lack focused regression coverage.',
                'proposed_next_action' => 'Add or harden '.$test.' for '.$file.' and prove it with php artisan test '.$test.'.',
            ], $focusConfig);
        }

        return [$findings, [
            'available' => true,
            'root_count' => count(self::FACTORY_RUNTIME_COVERAGE_ROOTS),
            'candidate_count' => count($files),
            'emitted_count' => count($findings),
            'skipped_already_covered_count' => $skippedCovered,
            'skipped_non_runtime_count' => $skippedNonRuntime,
        ]];
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
        foreach (self::STRATEGIC_MULTIPLIER_SEEDS as $index => $seed) {
            $source = $seed['source'];
            if (! $this->pathExists($source)) {
                $missingSource[] = $source;

                continue;
            }

            $testPath = $this->expectedTestPath($seed['test'], [$source]);
            $finding = $this->makeFinding([
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
            'seed_count' => count(self::STRATEGIC_MULTIPLIER_SEEDS),
            'emitted_count' => count($findings),
            'missing_source_count' => count($missingSource),
            'missing_sources' => $missingSource,
            'order' => array_map(static fn (array $seed): string => $seed['tier'].': '.$seed['title'], self::STRATEGIC_MULTIPLIER_SEEDS),
        ]];
    }

    // ---------- finding construction ----------

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $focusConfig
     * @return array<string,mixed>
     */
    private function makeFinding(array $base, array $focusConfig): array
    {
        $areaId = (string) $base['area_id'];
        $focus = (string) $base['focus'];
        $kind = (string) $base['kind'];
        $owner = (string) $base['owner_candidate'];
        $severity = $this->normalizeSeverity((string) $base['severity']);
        $confidence = $this->normalizeConfidence((string) ($base['confidence'] ?? 'medium'));
        $title = (string) $base['title'];

        $affectedPaths = array_values(array_filter((array) ($base['affected_paths'] ?? []), 'is_string'));
        $affectedFiles = array_values(array_filter($affectedPaths, $this->isCodePath(...)));
        $affectedDocs = array_values(array_filter($affectedPaths, static fn (string $p): bool => str_starts_with($p, 'docs/')));

        $sourceRef = (string) ($base['source_ref'] ?? ($kind.':'.$title));
        $raw = hash('sha256', implode('|', [$areaId, $focus, $kind, $owner, $sourceRef]));
        $findingId = 'afdf_'.substr($raw, 0, 16);
        $findingHash = 'sha256:'.$raw;

        $inFocus = $this->isInFocus($owner, $affectedPaths, $title.' '.(string) ($base['detail'] ?? ''), $focusConfig);
        $confidenceScore = self::CONFIDENCE_SCORE[$confidence] ?? 0.6;
        $priorityScore = (self::SEVERITY_RANK[$severity] ?? 0) * 100
            + ($inFocus ? 50 : 0)
            + (int) round($confidenceScore * 10);

        $finding = [
            'schema_version' => self::FINDING_SCHEMA,
            'finding_id' => $findingId,
            'finding_hash' => $findingHash,
            'area_id' => $areaId,
            'focus' => $focus,
            'title' => $title,
            'detail' => (string) ($base['detail'] ?? ''),
            'kind' => $kind,
            'severity' => $severity,
            'confidence' => $confidence,
            'confidence_score' => $confidenceScore,
            'owner_candidate' => $owner,
            'evidence_refs' => array_values(array_filter((array) ($base['evidence_refs'] ?? []), 'is_string')),
            'affected_files' => $affectedFiles,
            'affected_docs' => $affectedDocs,
            'why_it_matters' => (string) ($base['why_it_matters'] ?? ''),
            'proposed_spec_title' => $this->proposedSpecTitle($kind, $title),
            'proposed_next_action' => (string) ($base['proposed_next_action'] ?? 'Operator review required.'),
            'in_focus' => $inFocus,
            'priority_score' => $priorityScore,
            'origin' => (string) ($base['origin'] ?? 'deep'),
            'origin_type' => (string) ($base['origin_type'] ?? $kind),
            'auto_execution_allowed' => false,
            'operator_review_required' => true,
        ];

        $finding['spec_seed'] = $this->specSeed($finding);

        return $finding;
    }

    /**
     * Build a Self-Directed-Evolution-compatible gap candidate from a finding.
     * Shape matches {@see SelfDirectedEvolutionGapReadModelService} candidates so
     * it can flow straight into the Spec Proposal Adapter — drafted by SDE, never
     * here.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function specSeed(array $finding): array
    {
        $owner = (string) $finding['owner_candidate'];
        $rawHash = (string) $finding['finding_hash'];

        return [
            'schema_version' => self::SPEC_SEED_SCHEMA,
            'candidate_id' => 'gapc_'.substr(hash('sha256', 'deep_seed|'.$rawHash), 0, 16),
            'candidate_hash' => $rawHash,
            'source_owner' => $owner,
            'gap_kind' => self::OWNER_SPEC_GAP_KIND[$owner] ?? 'partial_canon',
            'title' => (string) $finding['title'],
            'rationale' => (string) $finding['why_it_matters'],
            'capability' => 'area_focus_'.(string) $finding['focus'],
            'risk_level' => (string) $finding['severity'],
            'evidence_refs' => $finding['evidence_refs'],
            'owner_doc_refs' => $finding['affected_docs'],
            'route_hint_owner' => $owner,
            'proposal_only' => true,
            'operator_review_required' => true,
        ];
    }

    private function proposedSpecTitle(string $kind, string $title): string
    {
        $prefix = match ($kind) {
            self::KIND_BUG => 'Fix',
            self::KIND_TEST => 'Pin with tests',
            self::KIND_DOC => 'Restore canon for',
            self::KIND_IMPLEMENTATION => 'Implement',
            self::KIND_RUNTIME => 'Wire runtime for',
            self::KIND_IMPROVEMENT => 'Improve',
            default => 'Resolve',
        };

        return $prefix.': '.$title;
    }

    private function whyItMatters(string $kind, string $owner, string $detail): string
    {
        $base = match ($kind) {
            self::KIND_TEST => 'Untested runtime in the development flow can regress silently and break the governed loop.',
            self::KIND_DOC => 'Stale or missing canon lets the development flow drift from its source of truth.',
            self::KIND_RISK => 'An unmanaged risk in the development flow can corrupt evidence or duplicate runtime authority.',
            self::KIND_GAP => 'A gap in the development flow blocks work from reaching governed Dev/Forge execution.',
            self::KIND_IMPLEMENTATION => 'An uncontracted capability has no reviewable spec, so the operator cannot safely authorise it.',
            self::KIND_BUG => 'A failing gate hint signals the development flow may not be provably green.',
            default => 'This finding affects the integrity of the Atlas development flow.',
        };

        return $detail !== '' ? $base.' '.$detail : $base;
    }

    private function isInFocus(string $owner, array $affectedPaths, string $text, array $focusConfig): bool
    {
        if (in_array($owner, [self::OWNER_ATLAS_DEV, self::OWNER_FORGE, self::OWNER_AAEOS], true)) {
            return true;
        }
        $tokens = array_values(array_filter((array) ($focusConfig['tokens'] ?? []), 'is_string'));
        $haystack = strtolower($text.' '.implode(' ', $affectedPaths));
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    private function isCodePath(string $path): bool
    {
        foreach (['app/', 'tests/', 'config/', 'routes/', 'database/', 'packages/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // ---------- factory backlog quality (AP-790 / factory_max) ----------

    /** @var list<string> */
    private const FACTORY_REJECTED_ORIGIN_TYPES = [
        'docs_stale',
        'focus_owner_doc_missing',
        'missing_evidence',
    ];

    /** @var list<string> */
    private const FACTORY_RUNTIME_PREFIXES = [
        'app/Services/Ai/AgenticEngineeringOs/',
        'app/Services/Ai/AtlasDecide/',
        'app/Services/Ai/AgenticWorkcell/',
        'app/Services/Ai/AtlasForge/',
        'app/Services/Ai/Cartography/',
        'app/Services/Ai/Cognition/',
        'app/Services/Ai/Compounding/',
        'app/Services/Ai/Context/',
        'app/Services/Ai/LongHorizon/',
        'app/Services/Ai/Programming/',
        'app/Services/Ai/ProgrammingRuntime/',
        'app/Services/Ai/Product/',
        'app/Services/Ai/Provider/',
        'app/Services/Ai/Reality/',
        'app/Services/Ai/RealitySandbox/',
        'app/Services/Ai/StrategicReality/',
        'app/Services/Ai/VerifiedExecution/',
        'app/Services/Ai/VerifiedContextExecution/',
        'app/Services/Ai/Kernel/',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/',
    ];

    /** @var list<string> */
    private const FACTORY_LEVERAGE_TERMS = [
        'ap786', 'ap790', 'autonomous', 'evolution', 'sandbox', 'materializer',
        'merge', 'governor', 'owner_runtime', 'senior_loop', 'provider', 'cursor',
        'dev_forge', 'priority', 'deep_finding', 'reliable24h', 'inbox', 'read_model',
        'evidence', 'worktree', 'stewardship', 'atlas_dev', 'forge', 'handoff',
        'decide', 'topology', 'quality_bar', 'cross_department', 'choreography',
        'mission_control', 'learning', 'compounding', 'self_construction', 'replay',
        'universal_gates', 'architect',
    ];

    /** @var list<string> */
    private const FACTORY_RUNTIME_COVERAGE_ROOTS = [
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/',
        'app/Services/Ai/Programming/',
        'app/Services/Ai/AgenticEngineeringOs/',
        'app/Services/Ai/AtlasDecide/',
    ];

    /** @var list<string> */
    private const FACTORY_RUNTIME_COVERAGE_NAME_TERMS = [
        'Service', 'Runtime', 'Runner', 'Engine', 'Coordinator', 'Dispatcher',
        'Router', 'Evaluator', 'Builder', 'Adapter', 'Driver', 'Governor',
        'Guard', 'Projector', 'Bridge', 'Planner', 'Registry', 'Certification',
        'Policy', 'Store', 'Executor', 'Classifier', 'Orchestrator',
    ];

    /** @var list<string> */
    private const FACTORY_RUNTIME_COVERAGE_EXCLUDED_NAME_TERMS = [
        'Interface', 'Contract', 'Dto', 'DTO', 'Data', 'Enum', 'Exception', 'Trait',
        'Value',
    ];

    /**
     * Highest-leverage roadmap order for the loop. These are not free-form docs:
     * each seed points at an existing runtime file + focused test path so AP-790
     * can turn the idea into a bounded Dev/Forge improvement.
     *
     * @var list<array{id:string,title:string,detail:string,source:string,test:string,owner:string,kind:string,severity:string,tier:string,jump:string,attention:string}>
     */
    private const STRATEGIC_MULTIPLIER_SEEDS = [
        [
            'id' => 's3_decide_upstream_every_packet',
            'title' => 'Make Atlas Decide upstream mandatory for owner execution packets',
            'detail' => 'Route every autonomous Dev/Forge packet through provider/topology choice before owner execution so Cursor, Codex, Claude, Gemini or another real provider can be swapped by policy instead of hardcoded loops.',
            'source' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'test' => 'AutonomousEvolutionSessionServiceTest.php',
            'owner' => self::OWNER_ATLAS_DEV,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'S3',
            'jump' => 'Provider antifragility: one provider hitting limit should not stop the factory loop.',
            'attention' => 'Must use real AtlasDecide/provider receipts; no mock topology in runtime.',
        ],
        [
            'id' => 's4_reliable_24h_loop_recovery',
            'title' => 'Strengthen 24h stewardship recovery until consecutive merged cycles are normal',
            'detail' => 'Improve reliable loop resume, blocker quarantine, candidate refill and merge continuation so AP-790 keeps cycling after blocked or provider-timeout runs.',
            'source' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
            'test' => 'Reliable24hLoopRunnerServiceTest.php',
            'owner' => self::OWNER_ATLAS_DEV,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'S4',
            'jump' => 'Turns the loop from demo cycles into a day-long software factory runner.',
            'attention' => 'Must count only ledger outcome=merged and merge_performed=true.',
        ],
        [
            'id' => 's1_compounding_learning_feedback',
            'title' => 'Close P16 learning feedback into next-cycle defaults',
            'detail' => 'Feed learning capsules and owner runtime outcomes back into priority, topology, validation and prompt defaults so every successful cycle improves the next one.',
            'source' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
            'test' => 'StewardshipPriorityEngineServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_IMPROVEMENT,
            'severity' => 'high',
            'tier' => 'S1',
            'jump' => 'Compounding: the same class of work should get cheaper and safer over time.',
            'attention' => 'Must record evidence refs and avoid hidden prompt drift.',
        ],
        [
            'id' => 's2_self_construction_default_for_atlas',
            'title' => 'Route Atlas-on-Atlas gaps through Self-Construction by default',
            'detail' => 'When the selected area is Atlas itself, convert validated gaps into governed Self-Construction packets instead of loose provider tasks.',
            'source' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'test' => 'AutonomousEvolutionSessionServiceTest.php',
            'owner' => self::OWNER_ATLAS_DEV,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'S2',
            'jump' => 'Atlas becomes its own governed build queue, not a collection of ad-hoc provider calls.',
            'attention' => 'Must preserve allowed_files, branch sandbox and owner receipts.',
        ],
        [
            'id' => 'm1_architect_agent_spec_pack',
            'title' => 'Introduce Architect-agent spec pack gate for R4 autonomous work',
            'detail' => 'Before high-risk autonomous work executes, require a spec pack with acceptance, rollback and breaking-change matrix generated by the architecture lane.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/DepartmentContractRuntime.php',
            'test' => 'DepartmentContractRuntimeTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'M1',
            'jump' => 'Lets the loop eat larger engineering work without turning the operator into the spec writer.',
            'attention' => 'Do not allow R5/professional-risk work without operator signature.',
        ],
        [
            'id' => 'm5_quality_bar_auto_block',
            'title' => 'Wire quality-bar telemetry as an auto-blocking immune gate',
            'detail' => 'Turn quality-bar breaches into machine blockers that pause the loop before degraded work accumulates.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluator.php',
            'test' => 'AtlasUniversalGatesEvaluatorTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'M5',
            'jump' => 'Prevents silent quality drift during 24h/24-7 autonomous operation.',
            'attention' => 'Must emit gate evidence, not boolean-only pass/fail.',
        ],
        [
            'id' => 'm6_unified_evidence_replay_refs',
            'title' => 'Unify Dev, Forge and Stewardship evidence refs for replay',
            'detail' => 'Bridge local Dev receipts, Forge evidence and Stewardship cycle receipts into replayable evidence refs.',
            'source' => 'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php',
            'test' => 'StewardshipOwnerRuntimeResultBridgeServiceTest.php',
            'owner' => self::OWNER_EVIDENCE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'M6',
            'jump' => 'Makes a full night/day of work auditable as a deterministic timeline.',
            'attention' => 'Must not claim replay completeness without concrete receipt paths.',
        ],
        [
            'id' => 's5_cross_department_choreography_runtime',
            'title' => 'Materialize cross-department choreography for Dev/Forge repair loops',
            'detail' => 'Route security, review, QA and architecture veto/repair signals through the cross-department handoff state machine instead of isolated blockers.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/RunbookOrchestrator.php',
            'test' => 'RunbookOrchestratorTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'S5',
            'jump' => 'Turns 11 departments into a coordinated runtime instead of separate services.',
            'attention' => 'Repair loop must stay bounded and veto propagation must be explicit.',
        ],
        [
            'id' => 'a3_mission_control_loop_visibility',
            'title' => 'Make Mission Control show live stewardship loop state',
            'detail' => 'Expose phases, blockers, evidence, branch, inbox and merge status for current area-focus cycles in Mission Control.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/AtlasMissionControlCockpitService.php',
            'test' => 'AtlasMissionControlCockpitServiceTest.php',
            'owner' => self::OWNER_PRODUCT_MODE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'A3',
            'jump' => 'Lets one operator govern autonomous engineering at a glance.',
            'attention' => 'Surface must project receipts; it must not become a parallel source of truth.',
        ],
        [
            'id' => 'a2_universal_gates_single_evaluator',
            'title' => 'Consolidate universal gate runs into a single evaluator receipt',
            'detail' => 'Make all AP-790 owner cycles pass through a canonical gate-run report with blockers, evidence refs and repair hints.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluator.php',
            'test' => 'AtlasUniversalGatesEvaluatorTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'A2',
            'jump' => 'Converts scattered quality checks into an immune system.',
            'attention' => 'Do not mark skipped phases green without signed skip reason.',
        ],
        [
            'id' => 'e1_context_twin_evolution_preflight',
            'title' => 'Require APCR plus Software Twin plus Verified Evolution before every code mutation',
            'detail' => 'Combine persistent context, software twin reality and verified evolution boundary before any owner flow writes code.',
            'source' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'test' => 'AutonomousEvolutionSessionServiceTest.php',
            'owner' => self::OWNER_ATLAS_DEV,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'E1',
            'jump' => 'Eliminates session-zero, stale-context and wrong-boundary writes before they happen.',
            'attention' => 'Must remain a preflight gate; never hide it as provider prompt text.',
        ],
        [
            'id' => 'e2_architecture_evolution_proposal_runtime',
            'title' => 'Let Atlas propose structural redesigns of its own engineering runtime',
            'detail' => 'Promote architecture evolution proposals for phases, departments and gates into bounded reviewable packets.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/RunbookOrchestrator.php',
            'test' => 'RunbookOrchestratorTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'E2',
            'jump' => 'Moves Atlas from feature self-construction to governed architectural self-evolution.',
            'attention' => 'Requires replay/regression evidence before any architecture promotion.',
        ],
        [
            'id' => 'e3_reality_sandbox_gate_for_r3_plus',
            'title' => 'Run Reality Sandbox counterfactuals before R3+ autonomous execution',
            'detail' => 'Use AARS and counterfactual simulation to explore outcomes before high-risk Dev/Forge work enters execution.',
            'source' => 'app/Services/Ai/RealitySandbox/AtlasAutonomousRealitySandboxService.php',
            'test' => 'AtlasAutonomousRealitySandboxServiceTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'E3',
            'jump' => 'Turns high-risk work from one-shot execution into simulated decision under uncertainty.',
            'attention' => 'Simulation output must be evidence, not authority; operator policy still governs sensitive work.',
        ],
        [
            'id' => 'e4_temporal_4d_replay_for_every_mutation',
            'title' => 'Attach temporal 4D replay references to every autonomous mutation',
            'detail' => 'Make each cycle replayable across context, decision, patch, evidence and learning state.',
            'source' => 'app/Services/Ai/Reality/AtlasUnifiedRealityGraphTemporalService.php',
            'test' => 'AtlasUnifiedRealityGraphTemporalServiceTest.php',
            'owner' => self::OWNER_EVIDENCE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'E4',
            'jump' => 'Makes long autonomous runs debuggable as timelines instead of opaque commit lists.',
            'attention' => 'No replay completeness claim without concrete receipt paths and hashes.',
        ],
        [
            'id' => 'e5_verified_context_immune_system',
            'title' => 'Make Verified Context Execution an immune gate for AP-790',
            'detail' => 'Block owner execution when context freshness, sufficiency or contradiction checks fail.',
            'source' => 'app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php',
            'test' => 'AtlasVerifiedContextExecutionLoopServiceTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'E5',
            'jump' => 'Prevents brilliant provider output on stale or incomplete context.',
            'attention' => 'Must expose exact missing context, not generic context_failed.',
        ],
        [
            'id' => 'e6_long_horizon_month_scale_continuity',
            'title' => 'Give long-horizon continuity authority over multi-day autonomous Obras',
            'detail' => 'Thread month-scale state, recovery plans and strategic forgetting into stewardship cycles.',
            'source' => 'app/Services/Ai/LongHorizon/LongHorizonContinuityCertificationService.php',
            'test' => 'LongHorizonContinuityCertificationServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'E6',
            'jump' => 'Lets Atlas carry large Obras over days or weeks without forgetting the shape of the work.',
            'attention' => 'Must distinguish useful forgetting from lost context.',
        ],
        [
            'id' => 'e7_swarm_specialists_per_slice',
            'title' => 'Dispatch specialist swarms per Dev/Forge slice with debate-and-judge',
            'detail' => 'Turn single-owner execution into specialist workcells for architecture, implementation, review, security and repair.',
            'source' => 'app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php',
            'test' => 'AtlasAgenticWorkcellRuntimeServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'E7',
            'jump' => 'Raises quality by forcing disagreement and specialist coverage before merge.',
            'attention' => 'Must avoid provider fanout without budget and reservation gates.',
        ],
        [
            'id' => 'e8_compounding_l8_distillation_metric',
            'title' => 'Measure and optimize the N x M compounding multiplier directly',
            'detail' => 'Distill learning across runs into measurable factory improvements in cost, quality, repair and provider choice.',
            'source' => 'app/Services/Ai/Compounding/AtlasCompoundingLevel8DistillationService.php',
            'test' => 'AtlasCompoundingLevel8DistillationServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_IMPROVEMENT,
            'severity' => 'medium',
            'tier' => 'E8',
            'jump' => 'Makes every month of Atlas work measurably stronger than the prior month.',
            'attention' => 'Metric must not reward easy cycles over meaningful factory power.',
        ],
        [
            'id' => 'e9_live_cartography_runtime_zoom',
            'title' => 'Expose live universe-to-component cartography for running loops',
            'detail' => 'Project branches, phases, owners, evidence, risks and learning into cartographic views during execution.',
            'source' => 'app/Services/Ai/Cartography/CartographyTruthGuardService.php',
            'test' => 'CartographyTruthGuardServiceTest.php',
            'owner' => self::OWNER_PRODUCT_MODE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'E9',
            'jump' => 'Lets the operator see the software factory as a live map rather than terminal noise.',
            'attention' => 'Cartography must derive from receipts; never become decorative state.',
        ],
        [
            'id' => 'e10_cognitive_immune_g0_g8_live',
            'title' => 'Wire cognitive immune checks into autonomous engineering decisions',
            'detail' => 'Detect drift, contradiction, bias, hallucinated authority and scope creep while the factory is operating.',
            'source' => 'app/Services/Ai/Cognition/AtlasCognitionScoreCardService.php',
            'test' => 'AtlasCognitionScoreCardServiceTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'high',
            'tier' => 'E10',
            'jump' => 'Turns cognition failure into a first-class blocker before bad work lands.',
            'attention' => 'Must produce actionable repair hints, not just “low confidence”.',
        ],
        [
            'id' => 'q1_reality_compiler',
            'title' => 'Introduce Reality Compiler slices for intent-to-system execution',
            'detail' => 'Compile human intent into governed AAEOS execution packets that traverse spec, simulation, swarm, evidence and review.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/AutonomousWorkExecutionOs.php',
            'test' => 'AutonomousWorkExecutionOsTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'Q1',
            'jump' => 'Turns feature creation from ticket choreography into governed compilation.',
            'attention' => 'Cannot bypass the 17-phase AAEOS runbook.',
        ],
        [
            'id' => 'q2_parallel_universes_execution',
            'title' => 'Evaluate best-of-N isolated implementation universes for R3+ work',
            'detail' => 'Run multiple isolated strategies and merge only the judged winner, preserving losing universes as learning evidence.',
            'source' => 'app/Services/Ai/AtlasDecide/AtlasSwarmParallelDispatchService.php',
            'test' => 'AtlasSwarmParallelDispatchServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'Q2',
            'jump' => 'Quality jumps from one chosen strategy to best-of-N governed execution.',
            'attention' => 'Requires strict worktree isolation and budget caps.',
        ],
        [
            'id' => 'q3_adversarial_self_testing',
            'title' => 'Add adversarial self-testing as a permanent factory lane',
            'detail' => 'Spawn safe adversarial checks that try to break gates, context, sandbox and merge assumptions, then convert failures into tests.',
            'source' => 'app/Services/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluator.php',
            'test' => 'AtlasUniversalGatesEvaluatorTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'Q3',
            'jump' => 'Robustness grows because Atlas continuously attacks its own weak points.',
            'attention' => 'Adversarial probes must be simulated and non-destructive.',
        ],
        [
            'id' => 'q4_probabilistic_programming_layer',
            'title' => 'Attach probability distributions to autonomous engineering decisions',
            'detail' => 'Estimate rollback, success, cost and risk probability for each candidate before execution.',
            'source' => 'app/Services/Ai/StrategicReality/AtlasStrategicRealityRuntimeService.php',
            'test' => 'AtlasStrategicRealityRuntimeServiceTest.php',
            'owner' => self::OWNER_AAEOS,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'Q4',
            'jump' => 'Replaces pass/fail intuition with quantified decision quality.',
            'attention' => 'Probabilities must expose evidence and confidence, not pretend certainty.',
        ],
        [
            'id' => 'q5_code_genome_evolution_engine',
            'title' => 'Model the codebase as an evolvable genome',
            'detail' => 'Identify genes, variants and mutation candidates, then let fitness gates propose better architecture over time.',
            'source' => 'app/Services/Ai/Compounding/AtlasLearningMutationRuntimeService.php',
            'test' => 'AtlasLearningMutationRuntimeServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_IMPROVEMENT,
            'severity' => 'medium',
            'tier' => 'Q5',
            'jump' => 'The codebase improves as an organism instead of only through requested features.',
            'attention' => 'Mutation must be proposal-first until gates prove safety.',
        ],
        [
            'id' => 'q6_cognitive_stack_trace',
            'title' => 'Generate cognitive stack traces for every important Atlas decision',
            'detail' => 'Expose evidence, alternatives, agent debate, gates and discarded options behind routing and merge decisions.',
            'source' => 'app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php',
            'test' => 'AtlasCognitiveFunctionDecomposerServiceTest.php',
            'owner' => self::OWNER_EVIDENCE,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'Q6',
            'jump' => 'Trust and auditability jump because every decision becomes debuggable.',
            'attention' => 'Do not expose secrets or provider-private traces.',
        ],
        [
            'id' => 'q7_emergent_specification_engine',
            'title' => 'Let telemetry and user behavior generate spec proposals',
            'detail' => 'Invert product planning by turning real usage gaps, workarounds and support patterns into spec seeds.',
            'source' => 'app/Services/Ai/Product/AtlasProductDeliveryOutcomeMemoryService.php',
            'test' => 'AtlasProductDeliveryOutcomeMemoryServiceTest.php',
            'owner' => self::OWNER_SELF_DIRECTED_EVOLUTION,
            'kind' => self::KIND_IMPLEMENTATION,
            'severity' => 'medium',
            'tier' => 'Q7',
            'jump' => 'The roadmap starts coming from reality, not speculation.',
            'attention' => 'Sensitive telemetry must pass consent and privacy gates.',
        ],
        [
            'id' => 'q8_inverse_engineering_layer',
            'title' => 'Translate desired outcomes into implementation constraints',
            'detail' => 'Let operators define p99, cost, reliability or compatibility outcomes and have Atlas derive candidate changes.',
            'source' => 'app/Services/Ai/Programming/ProgrammingPatchVerifier.php',
            'test' => 'ProgrammingPatchVerifierTest.php',
            'owner' => self::OWNER_ATLAS_DEV,
            'kind' => self::KIND_RUNTIME,
            'severity' => 'medium',
            'tier' => 'Q8',
            'jump' => 'Precision rises because Atlas optimizes for outcome constraints instead of code guesses.',
            'attention' => 'Constraints must be measurable, otherwise execution blocks.',
        ],
        [
            'id' => 'q9_code_singularity_engine',
            'title' => 'Continuously collapse duplicate semantics into reusable primitives',
            'detail' => 'Detect semantic duplication and propose consolidation plans across services, tests and callers.',
            'source' => 'app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php',
            'test' => 'ProgrammingSemanticCodeGraphServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_IMPROVEMENT,
            'severity' => 'medium',
            'tier' => 'Q9',
            'jump' => 'Maintenance burden shrinks over time instead of growing with every feature.',
            'attention' => 'Consolidation must prove caller parity before merge.',
        ],
        [
            'id' => 'q10_cross_project_federation',
            'title' => 'Federate engineering learning across Atlas projects without data leakage',
            'detail' => 'Share sanitized learning capsules across server, mobile, desktop and infra so each project benefits from the others.',
            'source' => 'app/Services/Ai/Compounding/AtlasCompoundingEngineeringIntelligenceService.php',
            'test' => 'AtlasCompoundingEngineeringIntelligenceServiceTest.php',
            'owner' => self::OWNER_FORGE,
            'kind' => self::KIND_IMPROVEMENT,
            'severity' => 'medium',
            'tier' => 'Q10',
            'jump' => 'Atlas becomes a galaxy of projects learning as one organism.',
            'attention' => 'Sovereignty and sanitization gates are mandatory.',
        ],
    ];

    /**
     * Filter low-ROI findings and enrich survivors for factory_max execution.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function applyFactoryBacklogQuality(array $findings): array
    {
        $accepted = [];
        $rejections = [];
        foreach ($findings as $finding) {
            $assessment = $this->assessFactoryCandidate($finding);
            if (($assessment['rejection_reason'] ?? '') !== '') {
                $rejections[] = [
                    'finding_id' => (string) ($finding['finding_id'] ?? ''),
                    'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                    'title' => (string) ($finding['title'] ?? ''),
                    'rejection_reason' => (string) $assessment['rejection_reason'],
                    'roi_score' => (int) ($assessment['roi_score'] ?? 0),
                    'execution_readiness_score' => (int) ($assessment['execution_readiness_score'] ?? 0),
                    'factory_leverage_score' => (int) ($assessment['factory_leverage_score'] ?? 0),
                    'risk_penalty' => (int) ($assessment['risk_penalty'] ?? 0),
                ];
                continue;
            }
            $accepted[] = $this->enrichFactoryExecutableFinding($finding, $assessment);
        }

        return [$accepted, $rejections];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function assessFactoryCandidate(array $finding): array
    {
        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        $kind = strtolower((string) ($finding['kind'] ?? ''));
        $allowedFiles = $this->resolveAllowedFilesForFinding($finding);
        $testsRequired = $this->resolveTestsRequiredForFinding($finding, $allowedFiles);
        $leverage = $this->factoryLeverageScore($finding, $allowedFiles);
        $readiness = $this->executionReadinessScore($finding, $allowedFiles, $testsRequired);
        $riskPenalty = $this->factoryRiskPenalty($finding, $allowedFiles);
        $roi = $this->clampScore((int) round(($leverage * 0.45) + ($readiness * 0.45) - ($riskPenalty * 0.35)));

        $rejection = '';
        if (in_array($originType, self::FACTORY_REJECTED_ORIGIN_TYPES, true) || $kind === self::KIND_DOC) {
            $rejection = 'factory_backlog_rejects_docs_or_low_leverage_evidence';
        } elseif ($this->allDocsOnlyPaths($allowedFiles)) {
            $rejection = 'factory_backlog_rejects_docs_only';
        } elseif ($this->isInterfaceOnlyFalsePositive($finding, $allowedFiles)) {
            $rejection = 'factory_backlog_rejects_interface_only_false_positive';
        } elseif ($originType === 'missing_test' && $this->isAlreadyCoveredByTest($finding, $testsRequired)) {
            $rejection = 'factory_backlog_rejects_already_covered_by_test';
        } elseif ($testsRequired === [] && ! in_array($originType, ['handoff_executor_wiring_gap'], true)) {
            $rejection = 'factory_backlog_rejects_no_verifiable_test';
        } elseif (! $this->hasExistingRuntimeSource($finding)) {
            $rejection = 'factory_backlog_rejects_missing_runtime_source';
        } elseif (! $this->touchesFactoryRuntime($allowedFiles)) {
            $rejection = 'factory_backlog_requires_factory_runtime_or_test_impact';
        }

        return [
            'rejection_reason' => $rejection,
            'roi_score' => $roi,
            'execution_readiness_score' => $readiness,
            'factory_leverage_score' => $leverage,
            'risk_penalty' => $riskPenalty,
            'allowed_files' => $allowedFiles,
            'tests_required' => $testsRequired,
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $assessment
     * @return array<string,mixed>
     */
    private function enrichFactoryExecutableFinding(array $finding, array $assessment): array
    {
        $allowedFiles = (array) ($assessment['allowed_files'] ?? []);
        $testsRequired = (array) ($assessment['tests_required'] ?? []);
        $owner = $this->normalizeFactoryOwner($finding, $allowedFiles);
        $severity = $this->normalizeFactorySeverity($finding, $owner);
        $title = trim((string) ($finding['title'] ?? ''));

        $finding['owner_candidate'] = $owner;
        $finding['severity'] = $severity;
        $finding['allowed_files'] = $allowedFiles;
        $finding['tests_required'] = $testsRequired;
        $finding['factory_execution_ready'] = true;
        $finding['auto_execution_allowed'] = true;
        $finding['operator_review_required'] = false;
        $finding['autonomous_execution_reason'] = 'factory_backlog_quality_accepted';
        $finding['roi_score'] = (int) ($assessment['roi_score'] ?? 0);
        $finding['execution_readiness_score'] = (int) ($assessment['execution_readiness_score'] ?? 0);
        $finding['factory_leverage_score'] = (int) ($assessment['factory_leverage_score'] ?? 0);
        $finding['risk_penalty'] = (int) ($assessment['risk_penalty'] ?? 0);
        $finding['rejection_reason'] = '';
        $finding['factory_priority_score'] = (int) ($finding['roi_score'] ?? 0) * 10
            + (self::SEVERITY_RANK[$severity] ?? 0) * 5
            + (($finding['in_focus'] ?? false) ? 40 : 0);
        if ((string) ($finding['origin'] ?? '') === 'strategic_multiplier_backlog') {
            $order = max(1, (int) ($finding['multiplier_order'] ?? 999));
            $finding['factory_priority_score'] = 20000 - ($order * 100) + (int) ($finding['roi_score'] ?? 0);
        }
        $finding['acceptance'] = $this->factoryAcceptance($title, $allowedFiles, $testsRequired);
        $finding['proposed_next_action'] = $this->factoryPatchNextAction($title, $allowedFiles, $testsRequired);

        $specSeed = is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : [];
        $specSeed['tests_required'] = $testsRequired;
        $specSeed['acceptance'] = $finding['acceptance'];
        $specSeed['route_hint_owner'] = $owner;
        $specSeed['proposal_only'] = false;
        $specSeed['operator_review_required'] = false;
        $finding['spec_seed'] = $specSeed;

        return $finding;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function resolveAllowedFilesForFinding(array $finding): array
    {
        if (is_array($finding['allowed_files'] ?? null) && $finding['allowed_files'] !== []) {
            return array_values(array_filter($finding['allowed_files'], 'is_string'));
        }

        $files = array_merge(
            $this->stringList($finding['affected_files'] ?? []),
            array_values(array_filter($this->stringList($finding['affected_paths'] ?? []), $this->isCodePath(...))),
        );
        foreach ($this->stringList($finding['evidence_refs'] ?? []) as $ref) {
            if (str_starts_with($ref, 'impl:')) {
                $files[] = substr($ref, 5);
            }
        }

        return array_values(array_unique(array_filter(
            $files,
            static fn (string $f): bool => $f !== '' && ! str_starts_with($f, 'docs/'),
        )));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function resolveTestsRequiredForFinding(array $finding, array $allowedFiles): array
    {
        if (is_array($finding['tests_required'] ?? null) && $finding['tests_required'] !== []) {
            return $this->stringList($finding['tests_required']);
        }
        $fromSeed = $this->stringList(data_get($finding, 'spec_seed.tests_required', []));
        if ($fromSeed !== []) {
            return $fromSeed;
        }

        $tests = [];
        foreach ($this->stringList($finding['evidence_refs'] ?? []) as $ref) {
            if (! str_starts_with($ref, 'expected_test:')) {
                continue;
            }
            $basename = trim(substr($ref, strlen('expected_test:')));
            $path = $this->expectedTestPath($basename, $allowedFiles);
            if ($path !== '') {
                $tests[] = $path;
            }
        }
        if ($tests === [] && $allowedFiles !== []) {
            $class = basename($allowedFiles[0], '.php');
            if ($class !== '') {
                $path = $this->expectedTestPath($class.'Test.php', $allowedFiles);
                if ($path !== '') {
                    $tests[] = $path;
                }
            }
        }

        return array_values(array_unique($tests));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function factoryLeverageScore(array $finding, array $allowedFiles): int
    {
        $haystack = strtolower(implode(' ', [
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['origin_type'] ?? ''),
            implode(' ', $allowedFiles),
        ]));
        $score = 28;
        foreach (self::FACTORY_LEVERAGE_TERMS as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                $score += 8;
            }
        }
        if ((string) ($finding['origin'] ?? '') === 'factory_max_seed') {
            $score += 24;
        }
        if (in_array((string) ($finding['origin_type'] ?? ''), ['missing_test', 'handoff_executor_wiring_gap'], true)) {
            $score += 16;
        }
        if ($this->touchesFactoryRuntime($allowedFiles)) {
            $score += 12;
        }

        return $this->clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $testsRequired
     */
    private function executionReadinessScore(array $finding, array $allowedFiles, array $testsRequired): int
    {
        $score = 10;
        if ($allowedFiles !== []) {
            $score += 28;
        }
        if ($testsRequired !== []) {
            $score += 28;
        }
        if ($this->hasExistingRuntimeSource($finding)) {
            $score += 22;
        }
        if ($this->stringList(data_get($finding, 'spec_seed.acceptance', [])) !== [] || is_array($finding['acceptance'] ?? null)) {
            $score += 12;
        }

        return $this->clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function factoryRiskPenalty(array $finding, array $allowedFiles): int
    {
        $penalty = 0;
        $owner = (string) ($finding['owner_candidate'] ?? '');
        if ($owner === self::OWNER_FORGE && in_array((string) ($finding['kind'] ?? ''), [self::KIND_TEST, self::KIND_BUG], true)) {
            $penalty += 18;
        }
        if (strtolower((string) ($finding['severity'] ?? '')) === 'critical' && (string) ($finding['kind'] ?? '') === self::KIND_TEST) {
            $penalty += 12;
        }
        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'routes/') || str_starts_with($file, 'config/')) {
                $penalty += 8;
            }
        }

        return $this->clampScore($penalty);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function normalizeFactoryOwner(array $finding, array $allowedFiles): string
    {
        $owner = (string) ($finding['owner_candidate'] ?? self::OWNER_ATLAS_DEV);
        $kind = (string) ($finding['kind'] ?? '');
        if ($owner === self::OWNER_FORGE && in_array($kind, [self::KIND_TEST, self::KIND_BUG], true) && count($allowedFiles) <= 3) {
            return self::OWNER_ATLAS_DEV;
        }
        if ($owner === self::OWNER_SELF_DIRECTED_EVOLUTION && $this->touchesFactoryRuntime($allowedFiles)) {
            return self::OWNER_ATLAS_DEV;
        }

        return $owner;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function normalizeFactorySeverity(array $finding, string $owner): string
    {
        $severity = $this->normalizeSeverity((string) ($finding['severity'] ?? 'medium'));
        if ($owner === self::OWNER_ATLAS_DEV && (string) ($finding['kind'] ?? '') === self::KIND_TEST && ($severity === 'critical' || $severity === 'high')) {
            return 'medium';
        }

        return $severity;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function isInterfaceOnlyFalsePositive(array $finding, array $allowedFiles): bool
    {
        if (strtolower((string) ($finding['origin_type'] ?? '')) !== 'missing_test') {
            return false;
        }
        foreach ($allowedFiles as $file) {
            if (! str_starts_with($file, 'app/') || ! str_ends_with($file, '.php')) {
                continue;
            }
            if (! $this->isPhpInterfaceFile($file)) {
                continue;
            }
            if ($this->hasSiblingImplementationTestCoverage($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $testsRequired
     */
    private function isAlreadyCoveredByTest(array $finding, array $testsRequired): bool
    {
        foreach ($testsRequired as $testPath) {
            if ($this->pathExists($testPath)) {
                return true;
            }
        }
        if (strtolower((string) ($finding['origin_type'] ?? '')) !== 'missing_test') {
            return false;
        }
        foreach ($this->stringList($finding['affected_files'] ?? []) as $file) {
            $basename = basename($file, '.php').'Test.php';
            $expected = $this->expectedTestPath($basename, [$file]);
            if ($expected !== '' && $this->pathExists($expected)) {
                return true;
            }
        }

        return false;
    }

    private function isPhpInterfaceFile(string $relativePath): bool
    {
        $absolute = $this->absolutePath($relativePath);
        if (! is_file($absolute)) {
            return false;
        }
        $head = (string) file_get_contents($absolute, false, null, 0, 4096);

        return preg_match('/\binterface\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1
            && preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*/', $head) !== 1;
    }

    private function hasSiblingImplementationTestCoverage(string $interfacePath): bool
    {
        $dir = dirname($interfacePath);
        $testDir = $this->expectedTestPath('XTest.php', [$interfacePath]);
        $testDir = $testDir !== '' ? dirname($testDir) : '';
        if ($testDir === '' || ! is_dir($this->absolutePath($testDir))) {
            return false;
        }
        $interfaceStem = basename($interfacePath, '.php');
        foreach (scandir($this->absolutePath($dir)) ?: [] as $entry) {
            if (! str_ends_with($entry, '.php') || $entry === basename($interfacePath)) {
                continue;
            }
            $candidate = $dir.'/'.$entry;
            if ($this->isPhpInterfaceFile($candidate)) {
                continue;
            }
            $class = basename($entry, '.php');
            if ($class === '' || str_contains(strtolower($class), 'interface')) {
                continue;
            }
            $testPath = $testDir.'/'.basename($candidate, '.php').'Test.php';
            if ($this->pathExists($testPath)) {
                return true;
            }
            if (str_contains(strtolower($interfaceStem), strtolower($class))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $finding */
    private function hasExistingRuntimeSource(array $finding): bool
    {
        foreach ($this->stringList($finding['affected_files'] ?? []) as $file) {
            if (str_starts_with($file, 'app/') && $this->pathExists($file)) {
                return true;
            }
        }
        foreach ($this->resolveAllowedFilesForFinding($finding) as $file) {
            if (str_starts_with($file, 'app/') && $this->pathExists($file)) {
                return true;
            }
        }

        return (string) ($finding['origin'] ?? '') === 'factory_max_seed';
    }

    /** @param list<string> $files */
    private function allDocsOnlyPaths(array $files): bool
    {
        if ($files === []) {
            return true;
        }

        foreach ($files as $file) {
            if (! str_starts_with($file, 'docs/') && ! str_ends_with($file, '.md')) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $files */
    private function touchesFactoryRuntime(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->factoryRuntimeFile($file)) {
                return true;
            }
            if (str_starts_with($file, 'tests/') && (
                str_contains($file, '/SoftwareCompanyStewardship/')
                || str_contains($file, '/Programming/')
                || str_contains($file, '/AtlasForge/')
                || str_contains($file, '/AgenticEngineeringOs/')
            )) {
                return true;
            }
        }

        return false;
    }

    private function factoryRuntimeFile(string $file): bool
    {
        foreach (self::FACTORY_RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function factoryAcceptance(string $title, array $allowedFiles, array $testsRequired): array
    {
        $lines = [];
        if ($title !== '') {
            $lines[] = 'Given the selected factory finding, the patch implements: '.$title.'.';
        }
        if ($allowedFiles !== []) {
            $lines[] = 'The diff stays inside allowed_files and changes runtime and/or focused tests, not documentation-only scope.';
        }
        if ($testsRequired !== []) {
            $lines[] = 'Focused verification passes: php artisan test '.$testsRequired[0].'.';
        }

        return $lines;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $testsRequired
     */
    private function factoryPatchNextAction(string $title, array $allowedFiles, array $testsRequired): string
    {
        $runtime = $allowedFiles[0] ?? 'selected runtime';
        $test = $testsRequired[0] ?? 'focused test';
        $label = $title !== '' ? $title : 'factory runtime improvement';

        return sprintf(
            'Implement "%s" with a minimal code patch in %s (not docs-only). Prove the change with: php artisan test %s.',
            $label,
            $runtime,
            $test,
        );
    }

    /**
     * @param  list<string>  $affectedFiles
     */
    private function expectedTestPath(string $basename, array $affectedFiles): string
    {
        if ($basename === '') {
            return '';
        }
        $source = $affectedFiles[0] ?? '';
        if (str_starts_with($source, 'app/Services/Ai/NightShift/')) {
            return 'tests/Unit/Ai/NightShift/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
            return 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/')) {
            $tail = substr($source, strlen('app/Services/Ai/'));
            $dir = trim(dirname($tail), '.');

            return 'tests/Unit/Ai/'.($dir !== '' ? $dir.'/' : '').$basename;
        }

        return 'tests/Unit/'.$basename;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $values), static fn (string $v): bool => $v !== ''));
    }

    private function absolutePath(string $relativePath): string
    {
        $base = function_exists('base_path') ? base_path() : getcwd();

        return rtrim((string) $base, '/').'/'.ltrim($relativePath, '/');
    }

    private function clampScore(int $value): int
    {
        return max(0, min(100, $value));
    }

    // ---------- normalization / dedupe / sort / summaries ----------

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return array_key_exists($severity, self::SEVERITY_RANK) && $severity !== 'unknown'
            ? $severity
            : 'medium';
    }

    private function normalizeConfidence(string $confidence): string
    {
        $confidence = strtolower(trim($confidence));

        return array_key_exists($confidence, self::CONFIDENCE_SCORE) ? $confidence : 'medium';
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function dedupe(array $findings): array
    {
        $seen = [];
        $unique = [];
        foreach ($findings as $finding) {
            $key = (string) ($finding['finding_hash'] ?? '');
            if ((string) ($finding['origin_type'] ?? '') === 'missing_test') {
                $files = $this->stringList($finding['affected_files'] ?? []);
                $key = 'missing_test:'.($files[0] ?? $key);
            }
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }

    /**
     * Focus-first, then severity, then confidence, then stable by hash.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function sortFindings(array $findings): array
    {
        usort($findings, static function (array $a, array $b): int {
            $aScore = (int) ($a['factory_priority_score'] ?? $a['priority_score'] ?? 0);
            $bScore = (int) ($b['factory_priority_score'] ?? $b['priority_score'] ?? 0);

            return $bScore <=> $aScore
                ?: ((int) ($b['roi_score'] ?? 0) <=> (int) ($a['roi_score'] ?? 0))
                ?: (((bool) ($b['in_focus'] ?? false)) <=> ((bool) ($a['in_focus'] ?? false)))
                ?: ((string) ($a['kind'] ?? '') <=> (string) ($b['kind'] ?? ''))
                ?: ((string) ($a['finding_hash'] ?? '') <=> (string) ($b['finding_hash'] ?? ''));
        });

        return array_values($findings);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function kindSummary(array $findings): array
    {
        $summary = array_fill_keys(self::KINDS, 0);
        foreach ($findings as $finding) {
            $kind = (string) ($finding['kind'] ?? '');
            if (array_key_exists($kind, $summary)) {
                $summary[$kind]++;
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function ownerSummary(array $findings): array
    {
        $summary = array_fill_keys(self::OWNER_CANDIDATES, 0);
        foreach ($findings as $finding) {
            $owner = (string) ($finding['owner_candidate'] ?? '');
            if (array_key_exists($owner, $summary)) {
                $summary[$owner]++;
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function severitySummary(array $findings): array
    {
        $summary = [];
        foreach ($findings as $finding) {
            $sev = (string) ($finding['severity'] ?? 'unknown');
            $summary[$sev] = ($summary[$sev] ?? 0) + 1;
        }
        ksort($summary);

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function focusSummary(array $findings): array
    {
        $in = 0;
        foreach ($findings as $finding) {
            if (($finding['in_focus'] ?? false) === true) {
                $in++;
            }
        }

        return ['in_focus' => $in, 'out_of_focus' => count($findings) - $in];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<string>
     */
    private function nextActions(array $findings): array
    {
        if ($findings === []) {
            return ['No findings in focus; nothing to route. Re-run on the next cycle.'];
        }

        return [
            'Surface findings in the Morning Inbox for operator decision; nothing auto-executes.',
            'Route accepted findings to Self-Directed Evolution via the per-finding spec_seed (proposal-only).',
            'Route execution-ready findings to Atlas Dev (small/local) or Forge (long-horizon) under governed review.',
        ];
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
        $record['recorded_at'] = $this->now();
        $this->appendJsonl($path, $record);

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
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->areaSlug($areaId).'.jsonl';
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/area_focus_deep_scans')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_deep_scans';
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
        if (! is_file($path)) {
            return [[], 0];
        }
        $records = [];
        $corrupted = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['scan_id']) && is_string($decoded['scan_id'])) {
                $records[] = $decoded;
            } else {
                $corrupted++;
            }
        }

        return [$records, $corrupted];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
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

        return array_values(array_filter((array) glob($dir.DIRECTORY_SEPARATOR.'*.jsonl'), 'is_string'));
    }

    private function areaSlug(string $areaId): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?? '';

        return $slug !== '' ? $slug : 'unknown_area';
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

    /**
     * @return list<string>
     */
    private function discoverFactoryRuntimeCoverageFiles(): array
    {
        $files = [];
        foreach (self::FACTORY_RUNTIME_COVERAGE_ROOTS as $root) {
            $absolute = $this->absolutePath($root);
            if (! is_dir($absolute)) {
                continue;
            }
            foreach (glob(rtrim($absolute, '/').'/*.php') ?: [] as $path) {
                if (! is_string($path)) {
                    continue;
                }
                $files[] = ltrim(str_replace(rtrim($this->absolutePath(''), '/').'/', '', $path), '/');
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function isFactoryRuntimeCoverageCandidate(string $file): bool
    {
        if (! $this->factoryRuntimeFile($file) || ! str_ends_with($file, '.php') || ! $this->pathExists($file)) {
            return false;
        }

        $class = basename($file, '.php');
        foreach (self::FACTORY_RUNTIME_COVERAGE_EXCLUDED_NAME_TERMS as $term) {
            if ($term !== '' && str_contains($class, $term)) {
                return false;
            }
        }

        $matchesName = false;
        foreach (self::FACTORY_RUNTIME_COVERAGE_NAME_TERMS as $term) {
            if ($term !== '' && str_ends_with($class, $term)) {
                $matchesName = true;
                break;
            }
        }
        if (! $matchesName) {
            return false;
        }

        $head = (string) file_get_contents($this->absolutePath($file), false, null, 0, 4096);
        if (preg_match('/\b(interface|trait)\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1) {
            return false;
        }
        if (preg_match('/\babstract\s+class\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1) {
            return false;
        }

        return preg_match('/\b(?:final\s+)?class\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1;
    }

    /**
     * Read-only existence seam (overridable in tests via the per-check overrides).
     */
    protected function pathExists(string $relativePath): bool
    {
        $base = function_exists('base_path') ? base_path() : getcwd();

        return file_exists(rtrim((string) $base, '/').'/'.ltrim($relativePath, '/'));
    }

    // ---------- envelope / policy ----------

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
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
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
