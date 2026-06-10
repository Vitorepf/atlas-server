<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Throwable;

/**
 * Software Company Stewardship Stack · Area Focus Loop ·
 * Agentic Engineering OS Area Finding Engine (Slice 2, AP-717).
 *
 * Read-only scanner that feeds the Area Focus Loop (AP-712) with findings for
 * `area_id: agentic_engineering_os`: stale docs, missing tests, failing-gate
 * hints, weak handoffs, duplicate runtime risk, missing evidence, replay gaps,
 * desktop surface gaps, Dev/Forge routing gaps and uncontracted spec gaps.
 *
 * Canon (do not violate):
 *   "Atlas Software Company Stewardship Stack é stack/capability family dentro
 *    do Atlas Autonomous Software Company Runtime, não OS novo."
 *
 * Hard read-only invariants:
 *   - NEVER writes code/docs, NEVER opens a branch, NEVER creates a spec/AP/doc,
 *     NEVER calls a provider, NEVER merges/deploys/touches secrets, NEVER runs
 *     heavy global commands.
 *   - Self-Directed Evolution stays the gap/spec owner; Atlas Dev / Forge stay
 *     the executors. This engine only proposes findings (route hints).
 *
 * Determinism: classification runs over a normalized `$input`. Production
 * gathering lives behind overridable seams so the core is unit-testable with
 * synthetic fixtures and the same input always yields the same hash.
 */
class AgenticEngineeringOsFindingEngineService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_finding_report.v1';

    public const FINDING_SCHEMA = 'atlas.software_company_stewardship.area_finding.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const AREA_ID = 'agentic_engineering_os';

    public const ROUTE_SELF_DIRECTED_EVOLUTION = 'self_directed_evolution';

    public const ROUTE_ATLAS_DEV = 'atlas_dev';

    public const ROUTE_FORGE = 'forge';

    public const ROUTE_OPERATOR_REVIEW = 'operator_review';

    private const DOCS_ROOT = 'docs/engineering-knowledge-base/';

    /** @var list<string> */
    public const FINDING_TYPES = [
        'docs_stale',
        'missing_test',
        'failing_gate_hint',
        'weak_handoff',
        'duplicate_runtime_risk',
        'missing_evidence',
        'replay_gap',
        'desktop_surface_gap',
        'dev_forge_routing_gap',
        'self_directed_spec_gap',
    ];

    /** @var array<string,array{severity:string,route:string,confidence:string}> */
    private const TYPE_META = [
        'docs_stale' => ['severity' => 'medium', 'route' => self::ROUTE_SELF_DIRECTED_EVOLUTION, 'confidence' => 'high'],
        'missing_test' => ['severity' => 'medium', 'route' => self::ROUTE_ATLAS_DEV, 'confidence' => 'high'],
        'failing_gate_hint' => ['severity' => 'high', 'route' => self::ROUTE_ATLAS_DEV, 'confidence' => 'medium'],
        'weak_handoff' => ['severity' => 'medium', 'route' => self::ROUTE_FORGE, 'confidence' => 'medium'],
        'duplicate_runtime_risk' => ['severity' => 'high', 'route' => self::ROUTE_FORGE, 'confidence' => 'high'],
        'missing_evidence' => ['severity' => 'high', 'route' => self::ROUTE_OPERATOR_REVIEW, 'confidence' => 'medium'],
        'replay_gap' => ['severity' => 'medium', 'route' => self::ROUTE_FORGE, 'confidence' => 'low'],
        'desktop_surface_gap' => ['severity' => 'low', 'route' => self::ROUTE_OPERATOR_REVIEW, 'confidence' => 'low'],
        'dev_forge_routing_gap' => ['severity' => 'medium', 'route' => self::ROUTE_FORGE, 'confidence' => 'medium'],
        'self_directed_spec_gap' => ['severity' => 'medium', 'route' => self::ROUTE_SELF_DIRECTED_EVOLUTION, 'confidence' => 'medium'],
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

    /** Keywords that escalate any finding straight to operator review. */
    private const SENSITIVE_KEYWORDS = [
        'auth', 'billing', 'secret', 'deploy', 'production', 'payment',
        'credential', 'destructive', 'migration', 'security', 'compliance',
    ];

    /** Statuses considered "uncontracted / not-yet-built" for spec-gap detection. */
    private const SPEC_GAP_STATUSES = ['future', 'building', 'planned', 'draft'];

    /**
     * Canonical area docs scanned in production (bounded, read-only). Kept small
     * on purpose — no heavy global crawl.
     *
     * @var list<string>
     */
    private const AREA_DOCS = [
        self::DOCS_ROOT.'atlas-agentic-engineering-os.md',
        self::DOCS_ROOT.'atlas-agentic-software-engineering-authority-map.md',
        self::DOCS_ROOT.'atlas-autonomous-software-company-runtime.md',
        self::DOCS_ROOT.'atlas-dev-efficient-programming-flow-v1.md',
        self::DOCS_ROOT.'atlas-forge-operating-system.md',
        self::DOCS_ROOT.'atlas-software-company-stewardship-stack.md',
        self::DOCS_ROOT.'atlas-autonomous-software-company-night-shift.md',
        self::DOCS_ROOT.'atlas-autonomous-software-company-night-shift-product-mode.md',
        self::DOCS_ROOT.'atlas-area-stewardship-layer.md',
        self::DOCS_ROOT.'atlas-evidence-certification-runtime.md',
        'docs/ap/AP-712-night-shift-area-focus-loop-contract.md',
        'docs/ap/AP-715-software-company-stewardship-stack-contract.md',
        'docs/ap/AP-717-agentic-engineering-os-area-finding-engine-contract.md',
    ];

    /** @var list<string> bounded glob patterns for service files in area scope */
    private const SERVICE_GLOBS = [
        'app/Services/Ai/AgenticEngineeringOs/*Service.php',
        'app/Services/Ai/AtlasForge/*Service.php',
        'app/Services/Ai/Programming/*Service.php',
        'app/Services/Ai/ProgrammingRuntime/*Service.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/*Service.php',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/*Service.php',
        'app/Services/Ai/*/*AreaFocus*.php',
        'app/Services/Ai/*/*/*AreaFocus*.php',
        'app/Services/Ai/*/*FindingEngine*.php',
        'app/Services/Ai/*/*/*FindingEngine*.php',
        'app/Services/Ai/*/*NightShift*.php',
    ];

    /**
     * Scan the area and return a deterministic finding report.
     *
     * Optional `$input` overrides keep the projection deterministic and
     * side-effect free for tests (each bypasses the matching read-only seam):
     *   - area_id:        string
     *   - docs:           list<array>  normalized doc descriptors
     *   - existing_paths: list<string> paths considered to exist
     *   - service_files:  list<string>
     *   - test_files:     list<string>
     *   - limit:          int          cap the number of findings
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function scan(array $input = []): array
    {
        $areaId = is_string($input['area_id'] ?? null) && $input['area_id'] !== ''
            ? (string) $input['area_id']
            : self::AREA_ID;

        if ($areaId !== self::AREA_ID) {
            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'mode' => 'read_only',
                'area_id' => $areaId,
                'finding_count' => 0,
                'findings' => [],
                'type_summary' => $this->emptyTypeSummary(),
                'route_summary' => $this->emptyRouteSummary(),
                'severity_summary' => [],
                'source_summary' => [],
                'blockers' => [[
                    'source' => 'area_registry',
                    'reason' => 'unsupported_area',
                    'detail' => "This engine only scans '".self::AREA_ID."'.",
                ]],
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        $blockers = [];
        $sourceSummary = [];
        $findings = [];

        [$docs, $docsSummary, $docsBlocker] = $this->resolveDocs($input);
        $sourceSummary['docs'] = $docsSummary;
        if ($docsBlocker !== null) {
            $blockers[] = $docsBlocker;
        }

        [$serviceFiles, $svcSummary] = $this->resolveServiceFiles($input);
        $sourceSummary['service_files'] = $svcSummary;

        [$testFiles, $testSummary] = $this->resolveTestFiles($input);
        $sourceSummary['test_files'] = $testSummary;

        // Doc-driven checks.
        foreach ($docs as $doc) {
            if (! is_array($doc)) {
                continue;
            }
            $findings = array_merge($findings, $this->checkDoc($areaId, $doc, $input));
        }

        // File-driven checks.
        $findings = array_merge($findings, $this->checkDuplicateRuntime($areaId, $serviceFiles));
        $findings = array_merge($findings, $this->checkMissingTests($areaId, $serviceFiles, $testFiles));

        $findings = $this->dedupe($findings);
        $findings = $this->sortFindings($findings);
        $limit = isset($input['limit']) ? max(1, (int) $input['limit']) : null;
        if ($limit !== null) {
            $findings = array_slice($findings, 0, $limit);
        }

        $status = match (true) {
            ($docsSummary['available'] ?? false) === false => self::STATUS_BLOCKED,
            $blockers !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'mode' => 'read_only',
            'area_id' => $areaId,
            'finding_count' => count($findings),
            'findings' => $findings,
            'type_summary' => $this->typeSummary($findings),
            'route_summary' => $this->routeSummary($findings),
            'severity_summary' => $this->severitySummary($findings),
            'source_summary' => $sourceSummary,
            'blockers' => $blockers,
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    // ---------- read-only seams (overridable for tests) ----------

    /**
     * Real, bounded gathering of normalized doc descriptors from the area docs.
     *
     * @return list<array<string,mixed>>
     */
    protected function gatherDocs(): array
    {
        $docs = [];
        foreach (self::AREA_DOCS as $relative) {
            $absolute = base_path($relative);
            if (! is_file($absolute)) {
                continue;
            }
            $raw = (string) file_get_contents($absolute);
            $docs[] = $this->normalizeDocFromRaw($relative, $raw);
        }

        return $docs;
    }

    /**
     * Real, bounded gathering of in-scope service file paths.
     *
     * @return list<string>
     */
    protected function gatherServiceFiles(): array
    {
        $files = [];
        foreach (self::SERVICE_GLOBS as $pattern) {
            foreach ((array) glob(base_path($pattern)) as $match) {
                $files[] = AreaFocusPathNormalizer::relativeToBasePath((string) $match);
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($files);
    }

    /**
     * Real, bounded gathering of in-scope test file paths.
     *
     * @return list<string>
     */
    protected function gatherTestFiles(): array
    {
        $files = [];
        foreach (['tests/Unit/Ai/*/*.php', 'tests/Unit/Ai/*/*/*.php', 'tests/Feature/Ai/*.php', 'tests/Feature/Ai/*/*.php'] as $pattern) {
            foreach ((array) glob(base_path($pattern)) as $match) {
                $files[] = AreaFocusPathNormalizer::relativeToBasePath((string) $match);
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($files);
    }

    /**
     * Read-only existence resolver. Uses the `existing_paths` override when
     * present, otherwise hits the filesystem.
     *
     * @param  list<string>|null  $existingPaths
     */
    protected function refExists(string $ref, ?array $existingPaths): bool
    {
        if ($existingPaths !== null) {
            return in_array($ref, $existingPaths, true);
        }

        return file_exists(base_path($ref));
    }

    // ---------- source resolution ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function resolveDocs(array $input): array
    {
        try {
            $docs = array_key_exists('docs', $input) && is_array($input['docs'])
                ? AreaFocusLoopPayloadNormalizer::listOfArrays($input['docs'])
                : $this->gatherDocs();
        } catch (Throwable $e) {
            return [[], ['available' => false, 'doc_count' => 0], [
                'source' => 'docs',
                'reason' => 'source_unavailable',
                'detail' => $e->getMessage(),
            ]];
        }

        return [$docs, ['available' => true, 'doc_count' => count($docs)], null];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function resolveServiceFiles(array $input): array
    {
        try {
            $files = array_key_exists('service_files', $input) && is_array($input['service_files'])
                ? AreaFocusStringListNormalizer::coercedStringValues($input['service_files'])
                : $this->gatherServiceFiles();
        } catch (Throwable) {
            $files = [];
        }

        return [$files, ['available' => true, 'file_count' => count($files)]];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function resolveTestFiles(array $input): array
    {
        try {
            $files = array_key_exists('test_files', $input) && is_array($input['test_files'])
                ? AreaFocusStringListNormalizer::coercedStringValues($input['test_files'])
                : $this->gatherTestFiles();
        } catch (Throwable) {
            $files = [];
        }

        return [$files, ['available' => true, 'file_count' => count($files)]];
    }

    // ---------- doc-driven checks ----------

    /**
     * @param  array<string,mixed>  $doc
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function checkDoc(string $areaId, array $doc, array $input): array
    {
        /** @var list<string>|null $existing */
        $existing = is_array($input['existing_paths'] ?? null)
            ? AreaFocusStringListNormalizer::coercedStringValues($input['existing_paths'])
            : null;

        $path = (string) ($doc['path'] ?? 'unknown');
        $findings = [];

        // docs_stale — related/repo path references that no longer exist.
        $refs = array_merge(
            $this->fileLikeRefs($doc['related_paths'] ?? []),
            $this->fileLikeRefs($doc['repo_paths'] ?? []),
        );
        $missingRefs = array_values(array_filter($refs, fn (string $r): bool => ! $this->refExists($r, $existing)));
        if ($missingRefs !== []) {
            $findings[] = $this->makeFinding('docs_stale', $areaId, 'docs_stale:'.$path, [
                'title' => 'Stale references in '.$path,
                'detail' => count($missingRefs).' related/repo path(s) referenced by the doc do not exist.',
                'evidence_refs' => array_map(fn (string $r): string => 'missing_ref:'.$r, array_slice($missingRefs, 0, 8)),
                'affected_paths' => array_merge([$path], array_slice($missingRefs, 0, 8)),
                'recommended_action' => 'Update or remove the dead references in the canonical doc.',
            ]);
        }

        // missing_evidence — requires_evidence but no live evidence.
        if (($doc['requires_evidence'] ?? false) === true) {
            $evidence = $this->fileLikeRefs($doc['evidence'] ?? []);
            $liveEvidence = array_filter($evidence, fn (string $r): bool => $this->refExists($r, $existing));
            if ($evidence === [] || $liveEvidence === []) {
                $findings[] = $this->makeFinding('missing_evidence', $areaId, 'missing_evidence:'.$path, [
                    'title' => 'Missing evidence for '.$path,
                    'detail' => 'Doc declares requires_evidence=true but no referenced evidence file exists.',
                    'evidence_refs' => ['requires_evidence:true', 'live_evidence:'.count($liveEvidence)],
                    'affected_paths' => [$path],
                    'recommended_action' => 'Attach an evidence pack / runtime proof before claiming this doc.',
                ]);
            }
        }

        // failing_gate_hint — declared test files that do not exist.
        $missingTests = array_values(array_filter(
            $this->testFileRefs($doc['required_tests'] ?? []),
            fn (string $r): bool => ! $this->refExists($r, $existing),
        ));
        if ($missingTests !== []) {
            $findings[] = $this->makeFinding('failing_gate_hint', $areaId, 'failing_gate_hint:'.$path, [
                'title' => 'Required test files missing for '.$path,
                'detail' => count($missingTests).' required_tests file path(s) do not exist; the gate likely fails.',
                'evidence_refs' => array_map(fn (string $r): string => 'missing_test_file:'.$r, $missingTests),
                'affected_paths' => array_merge([$path], $missingTests),
                'recommended_action' => 'Restore or write the declared test(s), then re-run the gate.',
            ]);
        }

        // weak_handoff — depends_on / flows_to targets without an owner doc.
        $danglingHandoffs = $this->danglingHandoffs($doc, $existing);
        if ($danglingHandoffs !== []) {
            $findings[] = $this->makeFinding('weak_handoff', $areaId, 'weak_handoff:'.$path, [
                'title' => 'Weak handoff from '.$path,
                'detail' => count($danglingHandoffs).' depends_on/flows_to target(s) have no resolvable owner doc.',
                'evidence_refs' => array_map(fn (string $r): string => 'unresolved_handoff:'.$r, array_slice($danglingHandoffs, 0, 8)),
                'affected_paths' => [$path],
                'recommended_action' => 'Point the handoff at a real owner doc or create the missing contract.',
            ]);
        }

        // replay_gap — replay expected but no replay reference.
        if (($doc['replay_expected'] ?? false) === true && ! $this->hasKeywordPath($doc, $existing, 'replay')) {
            $findings[] = $this->makeFinding('replay_gap', $areaId, 'replay_gap:'.$path, [
                'title' => 'Replay gap in '.$path,
                'detail' => 'Doc implies replay but references no replay evidence/test.',
                'evidence_refs' => ['replay_expected:true'],
                'affected_paths' => [$path],
                'recommended_action' => 'Add or link a replay verification path for this flow.',
            ]);
        }

        // desktop_surface_gap — desktop surface expected but none referenced.
        if (($doc['desktop_expected'] ?? false) === true && ! $this->hasKeywordPath($doc, $existing, 'desktop')) {
            $findings[] = $this->makeFinding('desktop_surface_gap', $areaId, 'desktop_surface_gap:'.$path, [
                'title' => 'Desktop surface gap in '.$path,
                'detail' => 'Capability implies a desktop surface but none is referenced.',
                'evidence_refs' => ['desktop_expected:true'],
                'affected_paths' => [$path],
                'recommended_action' => 'Add a desktop surface reference or record it as out of scope.',
            ]);
        }

        // dev_forge_routing_gap — routing declared but no routing implementation.
        if (($doc['dev_forge_routing_expected'] ?? false) === true && ! $this->hasKeywordPath($doc, $existing, 'rout')) {
            $findings[] = $this->makeFinding('dev_forge_routing_gap', $areaId, 'dev_forge_routing_gap:'.$path, [
                'title' => 'Dev/Forge routing gap in '.$path,
                'detail' => 'Doc declares Dev/Forge routing but references no routing implementation.',
                'evidence_refs' => ['dev_forge_routing_expected:true'],
                'affected_paths' => [$path],
                'recommended_action' => 'Implement or link the Dev/Forge routing path (future Area Focus Loop slice).',
            ]);
        }

        // self_directed_spec_gap — uncontracted future/building doc with no AP.
        $status = strtolower((string) ($doc['status'] ?? ''));
        if (in_array($status, self::SPEC_GAP_STATUSES, true) && ($doc['has_ap'] ?? false) !== true) {
            $findings[] = $this->makeFinding('self_directed_spec_gap', $areaId, 'self_directed_spec_gap:'.$path, [
                'title' => 'Uncontracted spec gap in '.$path,
                'detail' => "Doc is '{$status}' but has no AP/spec contract; needs a reviewable spec draft.",
                'evidence_refs' => ['status:'.$status, 'has_ap:false'],
                'affected_paths' => [$path],
                'recommended_action' => 'Route to Self-Directed Evolution for a proposal-only spec draft; operator curates.',
            ]);
        }

        return $findings;
    }

    // ---------- file-driven checks ----------

    /**
     * @param  list<string>  $serviceFiles
     * @return list<array<string,mixed>>
     */
    private function checkDuplicateRuntime(string $areaId, array $serviceFiles): array
    {
        $groups = [];
        foreach ($serviceFiles as $file) {
            $stem = $this->capabilityStem($file);
            if ($stem === '') {
                continue;
            }
            $groups[$stem][] = $file;
        }

        $findings = [];
        foreach ($groups as $stem => $files) {
            $dirs = array_unique(array_map(static fn (string $f): string => dirname($f), $files));
            if (count($files) < 2 || count($dirs) < 2) {
                continue;
            }
            sort($files);
            $findings[] = $this->makeFinding('duplicate_runtime_risk', $areaId, 'duplicate_runtime_risk:'.$stem, [
                'title' => 'Duplicate runtime risk · '.$stem,
                'detail' => count($files).' service files implement the same capability across '.count($dirs).' namespaces.',
                'evidence_refs' => array_map(static fn (string $f): string => 'impl:'.$f, $files),
                'affected_paths' => $files,
                'recommended_action' => 'Operator must reconcile to one canonical implementation; remove the duplicates.',
            ]);
        }

        return $findings;
    }

    /**
     * @param  list<string>  $serviceFiles
     * @param  list<string>  $testFiles
     * @return list<array<string,mixed>>
     */
    private function checkMissingTests(string $areaId, array $serviceFiles, array $testFiles): array
    {
        $testBasenames = array_map(static fn (string $t): string => basename($t), $testFiles);
        $findings = [];
        foreach ($serviceFiles as $file) {
            $class = basename($file, '.php');
            if ($class === '') {
                continue;
            }
            if (str_contains($class, 'Rivals')) {
                continue;
            }
            $expected = $class.'Test.php';
            if ($this->hasTestForClass($class, $testBasenames)) {
                continue;
            }
            $findings[] = $this->makeFinding('missing_test', $areaId, 'missing_test:'.$file, [
                'title' => 'Missing test for '.$class,
                'detail' => 'No '.$expected.' found for this service.',
                'evidence_refs' => ['expected_test:'.$expected],
                'affected_paths' => [$file],
                'recommended_action' => 'Add a unit test pinning this service before further changes.',
            ]);
        }

        return $findings;
    }

    /**
     * @param  list<string>  $testBasenames
     */
    private function hasTestForClass(string $class, array $testBasenames): bool
    {
        $expected = $class.'Test.php';
        if (in_array($expected, $testBasenames, true)) {
            return true;
        }

        $stem = preg_replace('/Service$/', '', $class) ?: $class;
        foreach ($testBasenames as $basename) {
            if ((str_starts_with($basename, $class) || str_starts_with($basename, $stem)) && str_ends_with($basename, 'Test.php')) {
                return true;
            }
        }

        return false;
    }

    // ---------- finding construction ----------

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function makeFinding(string $type, string $areaId, string $sourceRef, array $base): array
    {
        $meta = self::TYPE_META[$type] ?? ['severity' => 'medium', 'route' => self::ROUTE_OPERATOR_REVIEW, 'confidence' => 'medium'];
        $severity = is_string($base['severity'] ?? null) ? (string) $base['severity'] : $meta['severity'];
        $confidence = is_string($base['confidence'] ?? null) ? (string) $base['confidence'] : $meta['confidence'];

        $title = (string) ($base['title'] ?? $type);
        $detail = (string) ($base['detail'] ?? '');
        $route = $this->escalateRoute($meta['route'], $severity, $title.' '.$detail);

        $finding = [
            'schema_version' => self::FINDING_SCHEMA,
            'area_id' => $areaId,
            'finding_type' => $type,
            'title' => $title,
            'detail' => $detail,
            'severity' => $severity,
            'risk_level' => $severity,
            'confidence' => $confidence,
            'confidence_score' => self::CONFIDENCE_SCORE[$confidence] ?? 0.6,
            'route_hint' => $route,
            'evidence_refs' => AreaFocusStringListNormalizer::coercedStringValues($base['evidence_refs'] ?? []),
            'affected_paths' => AreaFocusStringListNormalizer::coercedStringValues($base['affected_paths'] ?? []),
            'recommended_action' => (string) ($base['recommended_action'] ?? 'Operator review required.'),
            'source' => $type,
            'safe_to_autofix' => false,
            'requires_operator_review' => true,
        ];

        $raw = hash('sha256', implode('|', [$areaId, $type, $sourceRef]));
        $finding['finding_id'] = 'aef_'.substr($raw, 0, 16);
        $finding['finding_hash'] = 'sha256:'.$raw;
        $finding['priority_score'] = (self::SEVERITY_RANK[$severity] ?? 0) * 100
            + (int) round(($finding['confidence_score']) * 10);

        return $finding;
    }

    private function escalateRoute(string $route, string $severity, string $text): string
    {
        if ($severity === 'critical') {
            return self::ROUTE_OPERATOR_REVIEW;
        }
        $haystack = strtolower($text);
        foreach (self::SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return self::ROUTE_OPERATOR_REVIEW;
            }
        }

        return $route;
    }

    // ---------- helpers ----------

    /**
     * @return list<string>
     */
    private function fileLikeRefs(mixed $value): array
    {
        $refs = [];
        foreach ((array) $value as $ref) {
            if (! is_string($ref) || $ref === '') {
                continue;
            }
            if (str_starts_with($ref, 'app/') || str_starts_with($ref, 'docs/')
                || str_starts_with($ref, 'tests/') || str_starts_with($ref, 'config/')
                || str_starts_with($ref, 'routes/') || str_starts_with($ref, 'database/')) {
                $refs[] = $ref;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($refs);
    }

    /**
     * @return list<string>
     */
    private function testFileRefs(mixed $value): array
    {
        $refs = [];
        foreach ((array) $value as $ref) {
            if (is_string($ref) && str_contains($ref, 'tests/') && str_ends_with($ref, '.php')) {
                $refs[] = $ref;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($refs);
    }

    /**
     * @param  array<string,mixed>  $doc
     * @param  list<string>|null  $existing
     * @return list<string>
     */
    private function danglingHandoffs(array $doc, ?array $existing): array
    {
        $dangling = [];
        foreach (['depends_on', 'flows_to'] as $key) {
            foreach ((array) ($doc[$key] ?? []) as $target) {
                if (! is_string($target) || $target === '') {
                    continue;
                }
                // Only resolve slug-like doc handoffs (kebab-case ids), not flow tokens.
                if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/', $target)) {
                    continue;
                }
                $candidate = self::DOCS_ROOT.$target.'.md';
                if (! $this->refExists($candidate, $existing)) {
                    $dangling[] = $target;
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($dangling);
    }

    /**
     * Whether a keyword appears in any evidence/related path of the doc OR in an
     * existing path the doc owns.
     *
     * @param  array<string,mixed>  $doc
     * @param  list<string>|null  $existing
     */
    private function hasKeywordPath(array $doc, ?array $existing, string $keyword): bool
    {
        $candidates = array_merge(
            (array) ($doc['evidence'] ?? []),
            (array) ($doc['related_paths'] ?? []),
            (array) ($doc['repo_paths'] ?? []),
        );
        foreach ($candidates as $ref) {
            if (is_string($ref) && str_contains(strtolower($ref), $keyword)) {
                return true;
            }
        }
        // Also honor an explicit override list of present keyword paths.
        if ($existing !== null) {
            foreach ($existing as $ref) {
                if (str_contains(strtolower($ref), $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize a service file path to a capability stem so that the same
     * capability implemented under different namespaces collides.
     */
    private function capabilityStem(string $file): string
    {
        $stem = strtolower(basename($file, '.php'));
        foreach (['atlas', 'nightshift', 'readmodel', 'service', 'command', 'registry', 'contract'] as $noise) {
            $stem = str_replace($noise, '', $stem);
        }

        return preg_replace('/[^a-z0-9]/', '', $stem) ?? '';
    }

    /**
     * Minimal, tolerant frontmatter reader (production only). Findings are
     * operator-reviewed hints, so a pragmatic extractor is sufficient; no
     * external YAML dependency is assumed.
     *
     * @return array<string,mixed>
     */
    private function normalizeDocFromRaw(string $path, string $raw): array
    {
        $front = '';
        if (preg_match('/^---\R(.*?)\R---/s', $raw, $m) === 1) {
            $front = $m[1];
        }

        $scalar = function (string $key) use ($front): ?string {
            if (preg_match('/^'.preg_quote($key, '/').':\s*(.+)$/m', $front, $mm) === 1) {
                return trim($mm[1], " \t\"'");
            }

            return null;
        };
        $list = function (string $key) use ($front): array {
            $items = [];
            if (preg_match('/^'.preg_quote($key, '/').':\s*\R((?:\s*-\s*.+\R?)+)/m', $front, $mm) === 1) {
                foreach (preg_split('/\R/', $mm[1]) ?: [] as $line) {
                    if (preg_match('/^\s*-\s*(.+)$/', $line, $li) === 1) {
                        $items[] = trim($li[1], " \t\"'");
                    }
                }
            }

            return array_values(array_filter($items, static fn (string $s): bool => $s !== ''));
        };

        $relatedPaths = $list('related_paths');
        $requiresEvidence = strtolower((string) $scalar('requires_evidence')) === 'true';
        $lowerRaw = strtolower($raw);

        $hasAp = false;
        foreach ($relatedPaths as $ref) {
            if (str_contains($ref, 'docs/ap/AP-')) {
                $hasAp = true;
                break;
            }
        }

        return [
            'path' => $path,
            'status' => (string) ($scalar('status') ?? ''),
            'implementation_state' => (string) ($scalar('implementation_state') ?? ''),
            'requires_evidence' => $requiresEvidence,
            'evidence' => $list('evidence'),
            'related_paths' => $relatedPaths,
            'repo_paths' => $list('repo_paths'),
            'depends_on' => $list('depends_on'),
            'flows_to' => $list('flows_to'),
            'required_tests' => $list('required_tests'),
            'quality_gates' => $list('quality_gates'),
            'has_ap' => $hasAp,
            'replay_expected' => str_contains($lowerRaw, 'replay'),
            'desktop_expected' => str_contains($lowerRaw, 'desktop'),
            'dev_forge_routing_expected' => str_contains($lowerRaw, 'dev/forge') || str_contains($lowerRaw, 'dev_forge_routing'),
        ];
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
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function sortFindings(array $findings): array
    {
        usort($findings, function (array $a, array $b): int {
            return ($b['priority_score'] ?? 0) <=> ($a['priority_score'] ?? 0)
                ?: ((string) ($a['finding_type'] ?? '') <=> (string) ($b['finding_type'] ?? ''))
                ?: ((string) ($a['finding_hash'] ?? '') <=> (string) ($b['finding_hash'] ?? ''));
        });

        return array_values($findings);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function typeSummary(array $findings): array
    {
        $summary = $this->emptyTypeSummary();
        foreach ($findings as $finding) {
            $type = (string) ($finding['finding_type'] ?? '');
            if (array_key_exists($type, $summary)) {
                $summary[$type]++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string,int>
     */
    private function emptyTypeSummary(): array
    {
        return array_fill_keys(self::FINDING_TYPES, 0);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function routeSummary(array $findings): array
    {
        $summary = $this->emptyRouteSummary();
        foreach ($findings as $finding) {
            $route = (string) ($finding['route_hint'] ?? '');
            if (array_key_exists($route, $summary)) {
                $summary[$route]++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string,int>
     */
    private function emptyRouteSummary(): array
    {
        return [
            self::ROUTE_SELF_DIRECTED_EVOLUTION => 0,
            self::ROUTE_ATLAS_DEV => 0,
            self::ROUTE_FORGE => 0,
            self::ROUTE_OPERATOR_REVIEW => 0,
        ];
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
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'writes_code' => false,
            'provider_invoked' => false,
            'opens_branch' => false,
            'creates_spec' => false,
            'creates_doc' => false,
            'runs_heavy_commands' => false,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
            'parallel_runtime_created' => false,
            'is_new_os' => false,
            'self_directed_evolution_remains_gap_owner' => true,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }
}
