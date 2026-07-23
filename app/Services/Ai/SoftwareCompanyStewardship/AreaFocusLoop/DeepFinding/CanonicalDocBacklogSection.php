<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CanonicalDocFrontmatterReader;

/**
 * Canonical Doc Backlog Miner section — extracted verbatim from
 * AreaFocusDeepFindingEngineService by the GOD-DEBULK split. READ-ONLY finding
 * source: each emitted finding maps 1:1 onto a REAL directive line already
 * written in a canonical doc's YAML frontmatter. NEVER invents, paraphrases,
 * executes or auto-approves. All finding construction routes through the shared
 * {@see DeepFindingFactory}; taxonomy constants live on the façade and are
 * referenced qualified.
 */
class CanonicalDocBacklogSection
{
    public function __construct(
        private readonly DeepFindingFactory $findingFactory,
        private readonly ?CanonicalDocFrontmatterReader $canonicalDocReader = null,
    ) {}

    private function canonicalDocReader(): CanonicalDocFrontmatterReader
    {
        return $this->canonicalDocReader ?? new CanonicalDocFrontmatterReader;
    }

    /**
     * Canonical Doc Backlog Miner. READ-ONLY finding source: each emitted finding
     * maps 1:1 onto a REAL directive line already written in a canonical doc's YAML
     * frontmatter (`next_actions` -> doc_next_action, `allowed_changes` ->
     * doc_allowed_change). `forbidden_changes` lines are DROPPED at source — never
     * converted into work. NEVER invents, paraphrases, executes or auto-approves.
     *
     * Default OFF so direct scan() callers and the existing test suite stay
     * byte-identical; the loop opts in via scan_canonical_doc_backlog OR by
     * injecting canonical_doc_backlog_lines (deterministic, filesystem-free).
     *
     * Recognised $input keys:
     *   - scan_canonical_doc_backlog: bool   enable a real docs-root scan
     *   - canonical_doc_backlog_lines: list<directiveLine>  injected directives (test seam)
     *   - canonical_doc_backlog_docs_root: string  override docs root (default DOCS_ROOT)
     *   - canonical_doc_backlog_max_docs: int  cap docs scanned
     *   - existing_self_improvement_candidate_hashes: list<string>  real SDE candidate_hash set for cross-layer dedup
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    public function checkCanonicalDocBacklog(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        $injected = array_key_exists('canonical_doc_backlog_lines', $input);
        $enabled = ($input['scan_canonical_doc_backlog'] ?? false) === true || $injected;
        if (! $enabled) {
            return [[], ['available' => true, 'enabled' => false, 'directive_count' => 0, 'emitted_count' => 0]];
        }

        // Gather REAL directive lines: injected (test) OR a read-only docs scan.
        $directives = [];
        $forbiddenDropped = 0;
        $docsScanned = 0;
        if ($injected) {
            $candidates = is_array($input['canonical_doc_backlog_lines']) ? $input['canonical_doc_backlog_lines'] : [];
            $docsRoot = (string) ($input['canonical_doc_backlog_docs_root'] ?? AreaFocusDeepFindingEngineService::DOCS_ROOT);
        } else {
            $reader = $this->canonicalDocReader();
            $docsRoot = (string) ($input['canonical_doc_backlog_docs_root'] ?? AreaFocusDeepFindingEngineService::DOCS_ROOT);
            $base = function_exists('base_path') ? base_path() : getcwd();
            $absRoot = rtrim((string) $base, '/').'/'.ltrim($docsRoot, '/');
            $maxDocs = (int) ($input['canonical_doc_backlog_max_docs'] ?? 500);
            $candidates = [];
            foreach ($reader->discoverDocs($absRoot, $maxDocs) as $absPath) {
                $docsScanned++;
                $risk = $reader->extractRiskLevel($absPath);
                foreach ($reader->extractDirectives($absPath) as $directive) {
                    $directive['risk_level'] = $risk;
                    $candidates[] = $directive;
                }
            }
        }

        // Forbidden lines are dropped at source; only actionable directives flow on.
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $kind = (string) ($candidate['directive_kind'] ?? '');
            if ($kind === 'forbidden_change') {
                $forbiddenDropped++;

                continue;
            }
            if (! in_array($kind, ['next_action', 'allowed_change'], true)) {
                continue;
            }
            $directives[] = $candidate;
        }

        $existingHashes = [];
        $sdeSupplied = array_key_exists('existing_self_improvement_candidate_hashes', $input);
        if ($sdeSupplied) {
            $existingHashes = array_values(array_filter(
                (array) $input['existing_self_improvement_candidate_hashes'],
                'is_string'
            ));
        }

        // POINT 3 — autonomous execution of the doc backlog is OFF by default and only
        // turns on via the explicit operator flag (input override or config). Off => the
        // doc-mined findings stay operator-review-gated (byte-identical to before).
        $autonomousExec = ($input['autonomous_doc_backlog_execution'] ?? null) === true
            || (function_exists('config') && (bool) config('atlas.software_company_stewardship.autonomous_doc_backlog_execution', false) === true);

        [$findings, $sdeSuppressed] = $this->canonicalDocBacklogFindings(
            $directives, $areaId, $focus, $focusConfig, $existingHashes, $docsRoot, $autonomousExec
        );

        return [$findings, [
            'available' => true,
            'enabled' => true,
            'source' => $injected ? 'injected' : 'docs_scan',
            'docs_root' => $docsRoot,
            'docs_scanned' => $docsScanned,
            'directive_count' => count($directives),
            'forbidden_dropped_count' => $forbiddenDropped,
            'emitted_count' => count($findings),
            'self_improvement_dedup' => $sdeSupplied ? 'supplied' : 'not_supplied',
            'self_improvement_suppressed_count' => $sdeSuppressed,
        ]];
    }

    /**
     * Pure emitter: turn REAL frontmatter directive lines into deep findings, one
     * finding per directive line. The title/proposed_next_action is the verbatim
     * trimmed YAML item; the FULL raw line lives in evidence_refs[1]='text:'+line.
     * No fabrication, no paraphrase. owner_candidate is always atlas_dev (local /
     * branch-allowed); routing stays downstream. THREE honest dedup stages start
     * here: (1) intra-source seen-set; (2) cross-layer SDE suppression when the
     * caller supplies the real self_improvement candidate_hash set.
     *
     * @param  list<array<string,mixed>>  $directives
     * @param  array<string,mixed>  $focusConfig
     * @param  list<string>  $existingSelfImprovementHashes
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function canonicalDocBacklogFindings(
        array $directives,
        string $areaId,
        string $focus,
        array $focusConfig,
        array $existingSelfImprovementHashes = [],
        string $docsRoot = AreaFocusDeepFindingEngineService::DOCS_ROOT,
        bool $autonomousExec = false
    ): array {
        $existingTokenSet = [];
        foreach ($existingSelfImprovementHashes as $hash) {
            $existingTokenSet[$hash] = true;
        }

        // POINT 2 — resolve file scope: a doc's `allowed_changes` directives ARE the
        // operator-declared file scope for that doc's `next_actions`. Group them by doc
        // path so each next_action finding inherits its doc's allowed files (the operator
        // wrote them; no path is ever guessed). A doc with no allowed_changes contributes
        // no scope and its next_actions block honestly at the SDD gate.
        $allowedByDoc = [];
        foreach ($directives as $directive) {
            if (! is_array($directive) || (string) ($directive['directive_kind'] ?? '') !== 'allowed_change') {
                continue;
            }
            $docPath = (string) ($directive['path'] ?? '');
            foreach ($this->resolveDirectivePaths((string) ($directive['text'] ?? ''), $docsRoot) as $p) {
                $allowedByDoc[$docPath][$p] = true;
            }
        }

        $findings = [];
        $seen = [];
        $sdeSuppressed = 0;

        foreach ($directives as $directive) {
            if (! is_array($directive)) {
                continue;
            }
            $rawLine = trim((string) ($directive['text'] ?? ''));
            $line = (int) ($directive['line'] ?? 0);
            $absPath = (string) ($directive['path'] ?? '');
            $directiveKind = (string) ($directive['directive_kind'] ?? '');
            if ($rawLine === '' || $line < 1 || $absPath === '' || ! in_array($directiveKind, ['next_action', 'allowed_change'], true)) {
                continue;
            }

            $relPath = $this->canonicalRelPath($absPath, $docsRoot);

            // Deterministic source-ref token over the normalized text + location.
            $normalized = strtolower(preg_replace('/\s+/', ' ', $rawLine) ?? $rawLine);
            $token = substr(MissionCanonicalHash::sha256($normalized), 0, 12);
            $sourceRef = 'canonical_doc:'.$relPath.':line:'.$line.':'.$token;

            // (2) Cross-layer SDE suppression: a doc line whose computed token
            // matches an existing self_improvement candidate is deduped, not
            // double-counted. Honest: only when the real set was supplied.
            if ($existingTokenSet !== [] && (isset($existingTokenSet[$token]) || isset($existingTokenSet[$sourceRef]))) {
                $sdeSuppressed++;

                continue;
            }

            $isAllowedChange = $directiveKind === 'allowed_change';
            $isMaintenance = preg_match('/^(Manter|Rodar|Atualizar|Separar)/i', $rawLine) === 1;

            $kind = $isAllowedChange
                ? AreaFocusDeepFindingEngineService::KIND_IMPROVEMENT
                : ($isMaintenance ? AreaFocusDeepFindingEngineService::KIND_DOC : AreaFocusDeepFindingEngineService::KIND_IMPLEMENTATION);

            // Severity from doc risk_level, defaulting to medium.
            $severity = AreaFocusScalarNormalizer::severityOrMedium((string) ($directive['risk_level'] ?? 'medium'));
            if ($isMaintenance) {
                $severity = 'low';
            } elseif (preg_match('/missing|blocked|broken|required|must/i', $rawLine) === 1) {
                $severity = 'high';
            }

            $multiSystem = preg_match('/multi-system|cross-department|todos os|provider topology|new (sub)?system|\bOS\b/i', $rawLine) === 1;
            $detail = 'Mined verbatim from the canonical doc frontmatter '
                .($isAllowedChange ? 'allowed_changes' : 'next_actions').' block at '.$relPath.':'.$line.'.';
            if ($multiSystem && ! $isMaintenance) {
                $severity = $this->bumpSeverity($severity);
                $detail .= ' multi_system_route_hint: this directive reads as multi-system / cross-department work — downstream routing must treat it as honestly large, never fake-small.';
            }

            $finding = $this->findingFactory->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => AreaFocusDeepFindingEngineService::SOURCE_CANONICAL_DOC_BACKLOG,
                'origin_type' => $isAllowedChange ? AreaFocusDeepFindingEngineService::ORIGIN_TYPE_DOC_ALLOWED_CHANGE : AreaFocusDeepFindingEngineService::ORIGIN_TYPE_DOC_NEXT_ACTION,
                'source_ref' => $sourceRef,
                'title' => $this->truncate($rawLine, 120),
                'detail' => $detail,
                'kind' => $kind,
                'owner_candidate' => AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV,
                'severity' => $severity,
                'confidence' => 'high',
                'evidence_refs' => [
                    'doc:'.$relPath.':line:'.$line,
                    'text:'.$rawLine,
                ],
                // POINT 2 — file scope from the operator's own frontmatter: the doc's
                // allowed_changes + any explicit repo path the directive text names. For an
                // allowed_change directive its own text IS the scope. Never a guessed path.
                'affected_paths' => $this->resolveDocBacklogScope(
                    $rawLine,
                    (array) ($allowedByDoc[$absPath] ?? []),
                    $isAllowedChange,
                    $docsRoot,
                ),
                'why_it_matters' => 'A directive the operator already wrote into canonical doc frontmatter is real, governed backlog. Mining it surfaces committed intent without inventing work.'
                    .($autonomousExec ? ' Operator authorized autonomous execution of the doc backlog (atlas.software_company_stewardship.autonomous_doc_backlog_execution).' : ' It inherits full operator-review governance and is never auto-executed.')
                    .($multiSystem ? ' multi_system_route_hint' : ''),
                'proposed_next_action' => $rawLine,
            ], $focusConfig);

            // POINT 3 — execution governance: ONLY when the operator's explicit, default-off
            // flag is on, mark the doc-mined finding auto-executable (same authorization
            // model as operator_authorized_plan_execution). Otherwise it stays operator-
            // review-gated. The no-scaffold / provider-proof / merge gates still protect main.
            if ($autonomousExec) {
                $finding['auto_execution_allowed'] = true;
                $finding['operator_review_required'] = false;
                $finding['autonomous_execution_reason'] = 'operator_authorized_doc_backlog_execution';
            }

            // (1) Intra-source seen-set: identical doc lines collapse to one.
            $hash = (string) ($finding['finding_hash'] ?? '');
            if ($hash !== '' && isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $findings[] = $finding;
        }

        return [$findings, $sdeSuppressed];
    }

    /**
     * Extract repo-relative file/dir paths a directive text names EXPLICITLY (an
     * allowed_changes entry or a path token inside a next_action). Never guesses: returns
     * only tokens that look like real repo paths/globs. Repo-relative is preserved verbatim.
     *
     * @return list<string>
     */
    private function resolveDirectivePaths(string $text, string $docsRoot): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $paths = [];
        // Path/glob tokens under known repo roots, with or without a file extension
        // (e.g. config/atlas.php, app/Services/Ai/Foundry/, app/Services/**/X.php).
        if (preg_match_all('#(?:app|tests|config|routes|database|resources|docs)/[A-Za-z0-9_./*\\\\-]+#', $text, $m) >= 1) {
            foreach ($m[0] as $token) {
                $clean = $this->canonicalRelPath(trim($token, " \t\n\r\0\x0B,.:;\"'`"), $docsRoot);
                if ($clean !== '') {
                    $paths[] = $clean;
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($paths);
    }

    /**
     * Resolve the executable file scope for a doc-backlog directive (POINT 2). An
     * allowed_change directive's own text is the scope; a next_action inherits its doc's
     * allowed_changes plus any explicit path it names. Empty => honest block downstream.
     *
     * @param  array<string,bool>  $allowedDocPaths  doc's allowed_changes (path => true)
     * @return list<string>
     */
    private function resolveDocBacklogScope(string $rawLine, array $allowedDocPaths, bool $isAllowedChange, string $docsRoot): array
    {
        if ($isAllowedChange) {
            $paths = $this->resolveDirectivePaths($rawLine, $docsRoot);
            foreach ($this->resolveClassPaths($rawLine) as $p) {
                $paths[] = $p;
            }

            return AreaFocusStringListNormalizer::uniqueStringValues($paths);
        }

        $scope = array_keys(array_filter($allowedDocPaths));
        foreach ($this->resolveDirectivePaths($rawLine, $docsRoot) as $p) {
            $scope[] = $p;
        }
        // Yield multiplier: most directives name a CLASS (e.g. "AtlasAaeosHttpPathFacadeService"),
        // not a path. Resolve each PascalCase class token the directive cites to its REAL file
        // under app/ via the class index. Honest — only files that actually exist are added; a
        // class with no file on disk (a to-be-created service) is skipped (no guessed path).
        foreach ($this->resolveClassPaths($rawLine) as $p) {
            $scope[] = $p;
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($scope);
    }

    /**
     * Resolve PascalCase class tokens named in a directive to their real repo file paths via a
     * lazily-built basename->path index of app/. Never guesses: a token with no matching file
     * on disk is dropped. This turns "Implementar X em FooService" into a concrete file scope.
     *
     * @return list<string>
     */
    private function resolveClassPaths(string $text): array
    {
        if (! preg_match_all('/\b([A-Z][A-Za-z0-9]{3,}(?:Service|Contract|Gate|Runner|Bridge|Executor|Adapter|Manager|Controller|Repository|Resolver|Planner|Projector|Builder|Engine|Orchestrator|Governor|Coordinator|Registry|Validator|Compiler|Handler|Dispatcher))\b/', $text, $m)) {
            return [];
        }

        $index = $this->classBasenameIndex();
        $paths = [];
        foreach (array_unique($m[1]) as $class) {
            if (isset($index[$class])) {
                $paths[] = $index[$class];
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($paths);
    }

    /**
     * @var array<string,string>|null basename(without .php) => first repo-relative path under app/
     */
    private ?array $classBasenameIndex = null;

    /**
     * @return array<string,string>
     */
    private function classBasenameIndex(): array
    {
        if ($this->classBasenameIndex !== null) {
            return $this->classBasenameIndex;
        }

        $index = [];
        $base = function_exists('base_path') ? base_path() : getcwd();
        $appDir = rtrim((string) $base, '/').'/app';
        if (is_dir($appDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $name = $file->getBasename('.php');
                if (! isset($index[$name])) {
                    $index[$name] = $this->canonicalRelPath($file->getPathname(), '');
                }
            }
        }

        return $this->classBasenameIndex = $index;
    }

    private function canonicalRelPath(string $absPath, string $docsRoot): string
    {
        $base = function_exists('base_path') ? base_path() : getcwd();
        $prefix = rtrim((string) $base, '/').'/';
        if (str_starts_with($absPath, $prefix)) {
            return substr($absPath, strlen($prefix));
        }

        // Injected paths may already be repo-relative; keep them verbatim.
        return $absPath;
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim($text);

        return strlen($text) <= $max ? $text : rtrim(substr($text, 0, $max));
    }

    private function bumpSeverity(string $severity): string
    {
        return match ($severity) {
            'low' => 'medium',
            'medium' => 'high',
            'high', 'critical' => 'critical',
            default => 'high',
        };
    }
}
