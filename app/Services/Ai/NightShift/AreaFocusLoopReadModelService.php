<?php

declare(strict_types=1);

namespace App\Services\Ai\NightShift;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedSpecProposalAdapter;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Night Shift · Area Focus Loop · Read-Only Read Model (slice 1).
 *
 * Canonical, single implementation of the FIRST mandated slice of the Area Focus
 * Loop inside the Atlas Software Company Stewardship Stack (Night Shift Product
 * Mode): a read-only "Area Internal Scout" for a canonical area, the priority
 * area being `agentic_engineering_os` (AP-712 / AP-715).
 *
 *   "Atlas Software Company Stewardship Stack é stack/capability family dentro do
 *    Atlas Autonomous Software Company Runtime, não OS novo."
 *
 * Canon (do not violate):
 *   - Owner docs: atlas-software-company-stewardship-stack.md (umbrella, AP-715),
 *     atlas-autonomous-software-company-night-shift-product-mode.md (schema owner),
 *     AP-712 (Area Focus Loop contract), atlas-area-stewardship-layer.md.
 *   - This is NOT a new OS, NOT a parallel runtime, NOT a parallel executor.
 *   - The Area Contract is owned by {@see AtlasNightShiftAreaFocusContractRegistry};
 *     this class never re-declares it inline.
 *   - Findings come from the canonical gap owner (Self-Directed Evolution); this
 *     class NEVER creates a parallel proposal/gap registry.
 *
 * Hard read-only invariants enforced here:
 *   - NEVER writes state, NEVER invokes a provider, NEVER executes work.
 *   - NEVER opens a branch/worktree, NEVER merges, deploys, touches secrets or
 *     performs a destructive change.
 *   - Routing to Atlas Dev / Forge / Self-Directed Evolution is an ADVISORY
 *     classification only — nothing is dispatched. Real dispatch is a future
 *     slice owned by Night Shift Product Mode under operator review.
 *   - Emits `atlas.night_shift.area_focus_loop.v1` with a deterministic hash so
 *     the same input always yields the same report.
 */
class AreaFocusLoopReadModelService
{
    public const REPORT_SCHEMA = 'atlas.night_shift.area_focus_loop.v1';

    public const FINDING_SCHEMA = 'atlas.night_shift.finding.v1';

    public const MORNING_INBOX_SCHEMA = 'atlas.night_shift.morning_inbox.v1';

    public const AP_CONTRACT = 'AP-712';

    public const STACK_NOTE = 'Atlas Software Company Stewardship Stack é stack/capability family dentro do Atlas Autonomous Software Company Runtime, não OS novo.';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const PRIORITY_AREA = AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS;

    public const REPO_ID = 'atlas-server';

    public const ROUTE_SELF_DIRECTED_EVOLUTION = 'self_directed_evolution';

    public const ROUTE_ATLAS_DEV = 'atlas_dev';

    public const ROUTE_ATLAS_FORGE = 'atlas_forge';

    public const ROUTE_QUEUED = 'queued';

    public const ROUTE_INBOX_ONLY = 'inbox_only';

    public const SOURCE_SDE = 'self_directed_evolution';

    public const SOURCE_OWNER_DOCS = 'area_owner_docs';

    /** Routes that would consume an execution WIP slot (i.e. open a branch). */
    private const EXECUTION_ROUTES = [self::ROUTE_ATLAS_DEV, self::ROUTE_ATLAS_FORGE];

    /** Self-Construction gap kinds that must get a reviewable spec before any code. */
    private const SPEC_FIRST_GAP_KINDS = [
        'missing_service_class', 'partial_canon', 'pipeline_not_proven', 'coverage_drift',
    ];

    /** @var array<string,int> */
    private const RISK_RANK = [
        'critical' => 4,
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        'unknown' => 0,
    ];

    /**
     * Triage keywords that force `inbox_only` (mirrors the Night Shift risk
     * triage: auth, billing, secrets, production, deploy, destructive migration,
     * architecture redesign, large refactor are operator-decision only).
     *
     * @var list<string>
     */
    private const INBOX_ONLY_KEYWORDS = [
        'auth', 'billing', 'secret', 'deploy', 'production', 'payment',
        'credential', 'destructive', 'migration', 'architecture', 'redesign',
        'security', 'compliance',
    ];

    public function __construct(
        private readonly SelfDirectedEvolutionGapReadModelService $gapReadModel,
        private readonly AtlasNightShiftAreaFocusContractRegistry $registry,
    ) {}

    /**
     * Project the read-only Area Focus Loop report.
     *
     * Optional `$input` overrides keep the projection deterministic and
     * side-effect free for tests:
     *   - area_id:          string   area to focus (default agentic_engineering_os)
     *   - gap_read_model:   array    bypass the Self-Directed Evolution call
     *   - owner_doc_status: array<string,bool>  bypass filesystem existence check
     *                       (keyed by the contract's full owner-doc paths)
     *   - hours:            int      Self-Directed Evolution window (default 24)
     *   - limit:            int      cap the number of findings
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = is_string($input['area_id'] ?? null) && $input['area_id'] !== ''
            ? (string) $input['area_id']
            : self::PRIORITY_AREA;

        $area = $this->registry->resolve($areaId);
        if ($area === null) {
            return $this->finalize($this->blockedEnvelope($areaId));
        }

        $blockers = [];
        $sourceSummary = [];
        $findings = [];

        // Source 1 — owner-doc presence (local, read-only filesystem signal).
        [$docFindings, $docSummary, $areaMap] = $this->collectOwnerDocs($area, $input);
        $findings = array_merge($findings, $docFindings);
        $sourceSummary[self::SOURCE_OWNER_DOCS] = $docSummary;

        // Source 2 — canonical gap owner (Self-Directed Evolution). Reused, never duplicated.
        [$gapFindings, $gapSummary, $gapBlocker, $scan] = $this->collectGaps($area, $input);
        $findings = array_merge($findings, $gapFindings);
        $sourceSummary[self::SOURCE_SDE] = $gapSummary;
        if ($gapBlocker !== null) {
            $blockers[] = $gapBlocker;
        }

        $findings = $this->sortFindings($findings);
        $limit = isset($input['limit']) ? max(1, (int) $input['limit']) : null;
        if ($limit !== null) {
            $findings = array_slice($findings, 0, $limit);
        }

        // Advisory routing under the WIP envelope (nothing is dispatched).
        $findings = $this->routeFindings($findings, $this->executionWipLimit($area));
        $routingSummary = $this->routingSummary($findings);

        $ownerDocsHealthy = ($docSummary['missing_count'] ?? 0) === 0;
        $sdeAvailable = ($gapSummary['available'] ?? false) === true;
        $status = match (true) {
            ! $sdeAvailable && ! $ownerDocsHealthy => self::STATUS_BLOCKED,
            $blockers !== [] || ! $ownerDocsHealthy => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $report = $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'mode' => 'read_only',
            'ap_contract' => self::AP_CONTRACT,
            'stack_member' => 'area_focus_loop',
            'stack_family' => 'atlas_software_company_stewardship_stack',
            'runtime_home' => 'atlas_autonomous_software_company_runtime',
            'stewardship_stack_note' => self::STACK_NOTE,
            'area_id' => $area['area_id'],
            'area' => $area,
            'area_map' => array_merge($areaMap, [
                'scan_source' => SelfDirectedEvolutionGapReadModelService::class,
                'scan_schema_version' => (string) ($scan['schema_version'] ?? ''),
                'scan_status' => (string) ($scan['status'] ?? 'unknown'),
            ]),
            'finding_count' => count($findings),
            'findings' => $findings,
            'routing_summary' => $routingSummary,
            'governance' => $this->governanceEnvelope($area),
            'budget_state' => $this->budgetState($area, $routingSummary),
            'evidence_requirement' => $this->evidenceRequirement(),
            'morning_inbox' => $this->morningInbox($findings, $area),
            'source_summary' => $sourceSummary,
            'blockers' => $blockers,
            'owner_reuse_matrix' => $this->ownerReuseMatrix(),
            'claim_policy' => $this->claimPolicy(),
        ]);

        return $this->attachAreaFindingEngine($report, (string) $area['area_id'], $input);
    }

    /**
     * Source 3 (optional, AP-717): attach the Agentic Engineering OS Area Finding
     * Engine excerpt under a dedicated key. OFF by default so the report stays
     * byte-identical; opt in with `include_area_findings=true` or an
     * `area_findings` override (used for deterministic tests). Degrades cleanly if
     * the engine class is absent. The engine is an additional read-only signal and
     * NEVER replaces Self-Directed Evolution as the canonical gap owner.
     *
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function attachAreaFindingEngine(array $report, string $areaId, array $input): array
    {
        $explicit = array_key_exists('area_findings', $input);
        if (! $explicit && ($input['include_area_findings'] ?? false) !== true) {
            return $report;
        }

        try {
            if ($explicit) {
                $engineReport = is_array($input['area_findings']) ? $input['area_findings'] : ['findings' => []];
            } else {
                $engineClass = 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AgenticEngineeringOsFindingEngineService';
                if (! class_exists($engineClass)) {
                    $report['area_finding_engine'] = ['available' => false, 'reason' => 'engine_absent'];

                    return $report;
                }
                $engineReport = app($engineClass)->scan(['area_id' => $areaId]);
            }
        } catch (Throwable $e) {
            $report['area_finding_engine'] = ['available' => false, 'reason' => 'source_unavailable', 'detail' => $e->getMessage()];

            return $report;
        }

        $findings = is_array($engineReport['findings'] ?? null) ? $engineReport['findings'] : [];
        $report['area_finding_engine'] = [
            'available' => true,
            'schema_version' => (string) ($engineReport['schema_version'] ?? ''),
            'engine_status' => (string) ($engineReport['status'] ?? 'unknown'),
            'finding_count' => count($findings),
            'findings' => $findings,
            'type_summary' => $engineReport['type_summary'] ?? [],
            'route_summary' => $engineReport['route_summary'] ?? [],
            'report_hash' => (string) ($engineReport['report_hash'] ?? ''),
            'self_directed_evolution_remains_gap_owner' => true,
        ];

        return $report;
    }

    // ---------- read-only seams (overridable for tests) ----------

    /**
     * Read-only projection of the canonical gap owner (Self-Directed Evolution).
     * Only non-volatile fields are consumed so the report hash stays deterministic.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function fetchGapReadModel(array $input): array
    {
        $hours = max(1, (int) ($input['hours'] ?? 24));

        return $this->gapReadModel->project(['hours' => $hours]);
    }

    /**
     * Read-only filesystem existence check for an owner doc. The contract stores
     * full repo-relative paths (docs/engineering-knowledge-base/...).
     */
    protected function ownerDocExists(string $relativePath): bool
    {
        return is_file(base_path($relativePath));
    }

    // ---------- source collectors ----------

    /**
     * @param  array<string,mixed>  $area
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function collectOwnerDocs(array $area, array $input): array
    {
        /** @var array<string,bool>|null $override */
        $override = is_array($input['owner_doc_status'] ?? null) ? $input['owner_doc_status'] : null;

        $docs = [];
        $findings = [];
        $missing = 0;

        foreach ((array) ($area['area_owner_docs'] ?? []) as $doc) {
            $doc = (string) $doc;
            $exists = $override !== null
                ? (bool) ($override[$doc] ?? false)
                : $this->ownerDocExists($doc);
            $docs[] = ['path' => $doc, 'exists' => $exists];

            if (! $exists) {
                $missing++;
                $findings[] = $this->makeFinding([
                    'area_id' => $area['area_id'],
                    'repo_id' => self::REPO_ID,
                    'source' => self::SOURCE_OWNER_DOCS,
                    'source_ref' => 'owner_doc:'.$doc,
                    'gap_kind' => 'missing_owner_doc',
                    'title' => 'Area owner doc missing · '.$doc,
                    'severity' => 'high',
                    'confidence' => 'high',
                    'evidence_refs' => ['expected_path:'.$doc],
                    'affected_paths' => [$doc],
                    'recommended_action' => 'Restore or recreate the canonical owner doc before stewarding this area.',
                    'capability' => 'area_owner_docs',
                ]);
            }
        }

        $areaMap = [
            'owner_docs' => $docs,
            'owner_docs_present' => count($docs) - $missing,
            'owner_docs_missing' => $missing,
            'owned_systems' => array_values((array) ($area['owned_systems'] ?? [])),
        ];

        $summary = [
            'available' => true,
            'checked_count' => count($docs),
            'missing_count' => $missing,
            'finding_count' => count($findings),
        ];

        return [$findings, $summary, $areaMap];
    }

    /**
     * @param  array<string,mixed>  $area
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>|null,3:array<string,mixed>}
     */
    private function collectGaps(array $area, array $input): array
    {
        try {
            $report = array_key_exists('gap_read_model', $input) && is_array($input['gap_read_model'])
                ? $input['gap_read_model']
                : $this->fetchGapReadModel($input);
        } catch (Throwable $e) {
            return [
                [],
                ['available' => false, 'candidate_count' => 0, 'finding_count' => 0],
                [
                    'source' => self::SOURCE_SDE,
                    'reason' => 'source_unavailable',
                    'detail' => $e->getMessage(),
                ],
                [],
            ];
        }

        $candidates = is_array($report['candidates'] ?? null) ? $report['candidates'] : [];
        $findings = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $findings[] = $this->gapToFinding($area, $candidate);
        }

        $summary = [
            'available' => true,
            'gap_read_model_status' => (string) ($report['status'] ?? 'unknown'),
            'candidate_count' => count($candidates),
            'finding_count' => count($findings),
        ];

        return [$findings, $summary, null, $report];
    }

    // ---------- finding normalizers ----------

    /**
     * Normalize a Self-Directed Evolution gap candidate into a Night Shift
     * finding. The candidate's canonical owner is preserved; this never claims
     * ownership of the gap.
     *
     * @param  array<string,mixed>  $area
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function gapToFinding(array $area, array $candidate): array
    {
        $risk = $this->normalizeRisk($candidate['risk_level'] ?? null);
        $title = (string) ($candidate['title'] ?? 'Self-Directed Evolution gap');
        $capability = is_string($candidate['capability'] ?? null) ? (string) $candidate['capability'] : null;
        $gapKind = (string) ($candidate['gap_kind'] ?? 'gap');
        $sourceRef = (string) ($candidate['source_ref'] ?? ($candidate['candidate_hash'] ?? 'gap'));

        $evidence = array_values(array_filter(
            (array) ($candidate['evidence_refs'] ?? []),
            static fn ($ref): bool => is_string($ref) && $ref !== '',
        ));
        $evidence[] = 'self_directed_evolution_candidate:'.((string) ($candidate['candidate_hash'] ?? 'unknown'));

        return $this->makeFinding([
            'area_id' => $area['area_id'],
            'repo_id' => self::REPO_ID,
            'source' => self::SOURCE_SDE,
            'source_ref' => 'self_directed_evolution:'.$sourceRef,
            'source_owner' => is_string($candidate['source_owner'] ?? null) ? (string) $candidate['source_owner'] : null,
            'gap_kind' => $gapKind,
            'title' => $title,
            'severity' => $risk,
            'confidence' => $evidence !== [] ? 'medium' : 'low',
            'evidence_refs' => array_values(array_unique($evidence)),
            'affected_paths' => array_values(array_filter(
                (array) ($candidate['owner_doc_refs'] ?? []),
                static fn ($p): bool => is_string($p) && $p !== '',
            )),
            'recommended_action' => 'Route to Self-Directed Evolution for a reviewable spec draft (future slice); operator curates. Never auto-implemented.',
            'capability' => $capability,
        ]);
    }

    /**
     * Finalize a finding: stamp invariant flags, classify triage and derive a
     * deterministic id/hash. Routing is applied later under the WIP envelope.
     *
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function makeFinding(array $base): array
    {
        $finding = array_merge([
            'schema_version' => self::FINDING_SCHEMA,
            // Read-only slice: nothing is auto-fixable yet and everything needs review.
            'safe_to_autofix' => false,
            'requires_operator_review' => true,
        ], $base);

        $triage = $this->triageClass(
            (string) ($finding['severity'] ?? 'unknown'),
            (string) ($finding['title'] ?? ''),
            (string) ($finding['gap_kind'] ?? ''),
            is_string($finding['capability'] ?? null) ? (string) $finding['capability'] : '',
        );
        $finding['triage_class'] = $triage;
        $finding['blast_radius'] = $triage === 'inbox_only' ? 'wide' : 'local';
        $finding['priority_score'] = (self::RISK_RANK[$finding['severity']] ?? 0) * 10
            + ($triage === 'inbox_only' ? 1 : 0);

        $raw = hash('sha256', implode('|', [
            (string) $finding['area_id'],
            (string) $finding['source'],
            (string) $finding['source_ref'],
            (string) $finding['gap_kind'],
        ]));
        $finding['finding_id'] = 'nsf_'.substr($raw, 0, 16);
        $finding['finding_hash'] = 'sha256:'.$raw;

        return $finding;
    }

    // ---------- advisory routing (nothing is dispatched) ----------

    /**
     * Apply the advisory route to each finding, holding execution routes within
     * the WIP envelope (overflow is queued). Routing is a *recommendation* for
     * the operator; this slice dispatches nothing.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function routeFindings(array $findings, int $executionWipLimit): array
    {
        $executionWip = 0;
        $routed = [];
        foreach ($findings as $finding) {
            [$route, $reason] = $this->decideRoute($finding);

            if (in_array($route, self::EXECUTION_ROUTES, true)) {
                if ($executionWip >= $executionWipLimit) {
                    $route = self::ROUTE_QUEUED;
                    $reason = 'wip_limit_reached';
                } else {
                    $executionWip++;
                }
            }

            $isExecution = in_array($route, self::EXECUTION_ROUTES, true);
            $finding['route'] = $route;
            $finding['route_reason'] = $reason;
            $finding['routes_to_owner_service'] = $this->routeOwnerService($route);
            $finding['requires_branch_isolation'] = $isExecution;
            $finding['evidence_required'] = true;
            $finding['dispatched'] = false;
            $finding['operator_decision_required'] = $isExecution || $route === self::ROUTE_INBOX_ONLY;
            $routed[] = $finding;
        }

        return $routed;
    }

    /**
     * Deterministic, advisory routing. High-risk/sensitive and missing owner docs
     * are held for the operator (inbox_only). Self-Directed Evolution owns gap/spec
     * proposal. Long-horizon portfolio work points at Forge; small scoped local
     * work points at Atlas Dev.
     *
     * @param  array<string,mixed>  $finding
     * @return array{0:string,1:string}
     */
    private function decideRoute(array $finding): array
    {
        if (($finding['triage_class'] ?? '') === 'inbox_only') {
            return [self::ROUTE_INBOX_ONLY, 'high_risk_or_sensitive_requires_operator'];
        }
        if (($finding['source'] ?? '') === self::SOURCE_OWNER_DOCS) {
            return [self::ROUTE_INBOX_ONLY, 'missing_owner_doc_requires_operator'];
        }

        $owner = (string) ($finding['source_owner'] ?? '');
        $kind = (string) ($finding['gap_kind'] ?? '');

        if ($owner === SelfDirectedEvolutionGapReadModelService::SOURCE_AAEL) {
            return [self::ROUTE_ATLAS_FORGE, 'long_horizon_portfolio_to_forge'];
        }
        if ($owner === SelfDirectedEvolutionGapReadModelService::SOURCE_SELF_CONSTRUCTION
            && in_array($kind, self::SPEC_FIRST_GAP_KINDS, true)) {
            return [self::ROUTE_SELF_DIRECTED_EVOLUTION, 'needs_spec_proposal_first'];
        }

        return [self::ROUTE_ATLAS_DEV, 'small_scoped_local_to_dev'];
    }

    private function routeOwnerService(string $route): ?string
    {
        return match ($route) {
            self::ROUTE_ATLAS_DEV => AtlasDevRuntimeService::class,
            self::ROUTE_ATLAS_FORGE => AtlasForgeParallelDurableCoordinatorService::class,
            self::ROUTE_SELF_DIRECTED_EVOLUTION => SelfDirectedSpecProposalAdapter::class,
            default => null,
        };
    }

    private function triageClass(string $severity, string $title, string $gapKind, string $capability): string
    {
        if ($severity === 'critical') {
            return 'inbox_only';
        }
        $haystack = strtolower($title.' '.$gapKind.' '.$capability);
        foreach (self::INBOX_ONLY_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return 'inbox_only';
            }
        }

        return 'branch_allowed';
    }

    private function normalizeRisk(mixed $value): string
    {
        if (! is_string($value)) {
            return 'medium';
        }
        $value = strtolower(trim($value));

        return array_key_exists($value, self::RISK_RANK) && $value !== 'unknown' ? $value : 'medium';
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function sortFindings(array $findings): array
    {
        usort($findings, function (array $a, array $b): int {
            return ($b['priority_score'] ?? 0) <=> ($a['priority_score'] ?? 0)
                ?: ((self::RISK_RANK[$b['severity'] ?? 'unknown'] ?? 0) <=> (self::RISK_RANK[$a['severity'] ?? 'unknown'] ?? 0))
                ?: (((string) ($a['source'] ?? '')) <=> ((string) ($b['source'] ?? '')))
                ?: (((string) ($a['finding_hash'] ?? '')) <=> ((string) ($b['finding_hash'] ?? '')));
        });

        return array_values($findings);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function routingSummary(array $findings): array
    {
        $summary = $this->emptyRoutingSummary();
        foreach ($findings as $finding) {
            $route = (string) ($finding['route'] ?? self::ROUTE_INBOX_ONLY);
            if (array_key_exists($route, $summary)) {
                $summary[$route]++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string,int>
     */
    private function emptyRoutingSummary(): array
    {
        return [
            self::ROUTE_SELF_DIRECTED_EVOLUTION => 0,
            self::ROUTE_ATLAS_DEV => 0,
            self::ROUTE_ATLAS_FORGE => 0,
            self::ROUTE_QUEUED => 0,
            self::ROUTE_INBOX_ONLY => 0,
        ];
    }

    /**
     * Execution WIP ceiling derived from the contract. The registry models WIP as
     * {max_findings, max_spec_drafts, max_branches}; branch count caps execution.
     *
     * @param  array<string,mixed>  $area
     */
    private function executionWipLimit(array $area): int
    {
        $wip = $area['wip_limit'] ?? null;
        if (is_array($wip)) {
            return max(0, (int) ($wip['max_branches'] ?? 0));
        }

        return max(0, (int) $wip);
    }

    // ---------- governance + budget + evidence + inbox ----------

    /**
     * The `max_governed` safety envelope (AP-712). Slice 1 is read-only, so the
     * loop executes nothing; the envelope still declares the hard gates that any
     * future active slice must honour.
     *
     * @param  array<string,mixed>  $area
     * @return array<string,mixed>
     */
    private function governanceEnvelope(array $area): array
    {
        return [
            'mode' => 'read_only',
            'governed_mode' => 'max_governed',
            'governed_mode_meaning' => 'maximum useful throughput inside the canonical safety boundary, not permissionless autonomy',
            'autonomy_tier' => (int) ($area['autonomy_tier'] ?? 0),
            'max_tier_for_area' => (int) ($area['max_tier_for_area'] ?? 0),
            'executes_work' => false,
            'opens_branch' => false,
            'execution_enabled' => false,
            'no_merge_without_operator' => true,
            'no_deploy_without_operator' => true,
            'no_secrets' => true,
            'no_destructive_change' => true,
            'branch_isolation_required' => true,
            'budget_required' => true,
            'wip_required' => true,
            'kill_switch_required' => true,
            'evidence_pack_required' => true,
            'morning_inbox_required' => true,
        ];
    }

    /**
     * Honest budget projection. Slice 1 dispatches nothing, so nothing is
     * consumed; only the *advisory* WIP that routing would have used is reported.
     *
     * @param  array<string,mixed>  $area
     * @param  array<string,int>  $routing
     * @return array<string,mixed>
     */
    private function budgetState(array $area, array $routing): array
    {
        $wipUsed = ($routing[self::ROUTE_ATLAS_DEV] ?? 0) + ($routing[self::ROUTE_ATLAS_FORGE] ?? 0);

        return [
            'dev_budget' => $area['dev_budget'] ?? null,
            'forge_budget' => $area['forge_budget'] ?? null,
            'wip_limit' => $area['wip_limit'] ?? null,
            'execution_wip_limit' => $this->executionWipLimit($area),
            'wip_advised' => $wipUsed,
            'dev_routed' => $routing[self::ROUTE_ATLAS_DEV] ?? 0,
            'forge_routed' => $routing[self::ROUTE_ATLAS_FORGE] ?? 0,
            'queued' => $routing[self::ROUTE_QUEUED] ?? 0,
            'budget_consumed' => false,
            'execution_executed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceRequirement(): array
    {
        return [
            'evidence_pack_required' => true,
            'owner' => 'atlas-evidence-certification-runtime',
            'validations_required' => [
                'build',
                'tests',
                'docs-health',
                'architecture-validate',
                'evidence_pack',
            ],
            'no_claim_without_evidence' => true,
        ];
    }

    /**
     * Operator decision queue (advisory, never persisted). Collects findings that
     * need a human call: anything routed inbox_only or any execution route (which
     * the operator must approve before a future slice could dispatch it).
     *
     * @param  list<array<string,mixed>>  $findings
     * @param  array<string,mixed>  $area
     * @return array<string,mixed>
     */
    private function morningInbox(array $findings, array $area): array
    {
        $items = [];
        foreach ($findings as $finding) {
            if (($finding['operator_decision_required'] ?? false) !== true) {
                continue;
            }
            $items[] = [
                'finding_id' => (string) ($finding['finding_id'] ?? ''),
                'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                'title' => (string) ($finding['title'] ?? ''),
                'source' => (string) ($finding['source'] ?? ''),
                'route' => (string) ($finding['route'] ?? ''),
                'route_reason' => (string) ($finding['route_reason'] ?? ''),
                'risk_level' => (string) ($finding['severity'] ?? 'unknown'),
                'priority_score' => (int) ($finding['priority_score'] ?? 0),
                'decision_required' => true,
                'operator_actions' => ['approve', 'request_changes', 'reject', 'continue_in_sandbox'],
            ];
        }

        return [
            'schema_version' => self::MORNING_INBOX_SCHEMA,
            'destination' => (string) ($area['inbox_destination'] ?? 'morning_inbox'),
            'decision_count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function ownerReuseMatrix(): array
    {
        return [
            self::SOURCE_SDE => [
                'owner_service' => SelfDirectedEvolutionGapReadModelService::class,
                'reused_methods' => ['project'],
                'not_invoked_methods' => ['none — read-only projection only'],
                'role' => 'canonical gap owner (scan source)',
            ],
            'area_contract' => [
                'owner_service' => AtlasNightShiftAreaFocusContractRegistry::class,
                'reused_methods' => ['resolve'],
                'not_invoked_methods' => [],
                'role' => 'canonical Area Contract source (AP-712)',
            ],
            'spec_proposal' => [
                'owner_service' => SelfDirectedSpecProposalAdapter::class,
                'reused_methods' => [],
                'not_invoked_methods' => ['draft'],
                'role' => 'advisory route target only (future slice)',
            ],
            'atlas_dev' => [
                'owner_service' => AtlasDevRuntimeService::class,
                'reused_methods' => [],
                'not_invoked_methods' => ['*'],
                'role' => 'advisory route target only (future slice)',
            ],
            'atlas_forge' => [
                'owner_service' => AtlasForgeParallelDurableCoordinatorService::class,
                'reused_methods' => [],
                'not_invoked_methods' => ['*'],
                'role' => 'advisory route target only (future slice)',
            ],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'provider_invoked' => false,
            'executes_work' => false,
            'opens_branch' => false,
            'dispatches_to_dev_or_forge' => false,
            'routes_to_dev_or_forge' => false,
            'creates_spec' => false,
            'creates_branch_sandbox' => false,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
            'parallel_runtime_created' => false,
            'parallel_authority_created' => false,
            'is_new_os' => false,
            'reuses_self_directed_evolution_for_gaps' => true,
            'operator_review_required' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedEnvelope(string $areaId): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'mode' => 'read_only',
            'ap_contract' => self::AP_CONTRACT,
            'stack_member' => 'area_focus_loop',
            'stack_family' => 'atlas_software_company_stewardship_stack',
            'runtime_home' => 'atlas_autonomous_software_company_runtime',
            'stewardship_stack_note' => self::STACK_NOTE,
            'area_id' => $areaId,
            'area' => null,
            'area_map' => null,
            'finding_count' => 0,
            'findings' => [],
            'routing_summary' => $this->emptyRoutingSummary(),
            'governance' => [
                'mode' => 'read_only',
                'execution_enabled' => false,
            ],
            'budget_state' => null,
            'evidence_requirement' => $this->evidenceRequirement(),
            'morning_inbox' => [
                'schema_version' => self::MORNING_INBOX_SCHEMA,
                'decision_count' => 0,
                'items' => [],
            ],
            'source_summary' => [],
            'blockers' => [[
                'source' => 'area_registry',
                'reason' => 'unknown_area',
                'detail' => "No canonical Area Focus Loop contract for area_id '{$areaId}'. Priority area is '".self::PRIORITY_AREA."'.",
                'supported_areas' => $this->registry->registeredAreas(),
            ]],
            'owner_reuse_matrix' => $this->ownerReuseMatrix(),
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * Stamp the deterministic hash (excludes generated_at) and the timestamp.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }
}
