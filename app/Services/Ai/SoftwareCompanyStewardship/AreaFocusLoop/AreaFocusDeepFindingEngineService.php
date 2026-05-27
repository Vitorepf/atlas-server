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

        // 3. Dedupe, prioritise (focus first, then severity), cap.
        $findings = $this->dedupe($findings);
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
            return ((int) ($b['priority_score'] ?? 0) <=> (int) ($a['priority_score'] ?? 0))
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
