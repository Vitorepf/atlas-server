<?php

namespace App\Services\Engineering;

use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class EngineeringDocumentationHealthService
{
    private ?EngineeringDocumentationWarningCollector $warningCollectorInstance = null;

    private ?EngineeringDocumentationViolationRules $violationRulesInstance = null;

    private const CANONICAL_MODULE_SCHEMA = 'atlas_canonical_module_doc.v1';

    /**
     * Container key under which a computed report is cached scoped-to-the-request, keyed by the
     * resolved docs root, so the several callers one create orchestration runs (the session
     * bootstrap docs gate + the docs split plan) share ONE filesystem scan + analysis.
     * Service-private.
     */
    private const SHARED_REPORT_KEY = 'atlas.engineering.documentation_health.report';

    /**
     * Per-instance memo of the computed report, keyed by resolved docs root. Fast path for the
     * common single-instance case; the container scoped cache (SHARED_REPORT_KEY) is what shares
     * the result across the DIFFERENT instances the create path autowires.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $reportMemo = [];

    /**
     * Subtrees under the engineering KB that hold DERIVED / VISUAL artifacts
     * (Mermaid + AURC diagrams, compiled notes, human briefings) rather than
     * authored canonical module docs. They are carved out of the canonical
     * health rules exactly like /archive/ and /templates/: a diagram or a human
     * briefing is a Human Knowledge Surface, not a module contract, and must not
     * be forced into the canonical_module frontmatter + 12-section shape (the
     * self-learning briefing legitimately carries no frontmatter at all).
     *
     * Genuine canonical docs that merely LIVE under memory/ — memory/contracts.md,
     * memory/retrieval-and-context.md, memory/open-brain-mcp.md, memory/foundation-map.md —
     * stay fully in scope; only the memory/diagrams/ artifact subtree is excluded.
     *
     * The L0 write-gate (AtlasDocumentationRealityWriteGateService) needs no
     * matching prefix change: it asks THIS service for the violation truth, so a
     * doc that produces no canonical violations here also surfaces no gate
     * blockers — the corpus scope stays single-sourced.
     *
     * @var array<int,string>
     */
    private const NON_CANONICAL_ARTIFACT_PATH_MARKERS = [
        'memory/diagrams/',
        // Parking lot for unapplied .patch files plus its README — a recovery
        // artifact of the same nature as /archive/, never a module contract.
        '/_recovery/',
    ];

    /**
     * @var array<string,int|null>
     */
    private const REQUIRED_DOCS = [
        'docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md' => 520,
        'docs/engineering-knowledge-base/atlas-documentation-creation-gate.md' => 520,
        'docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md' => 520,
        'docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md' => 520,
        'docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md' => 520,
        'docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md' => 520,
        'docs/engineering-knowledge-base/START_HERE.md' => null,
        'docs/engineering-knowledge-base/README.md' => null,
    ];

    /**
     * @var array<int,string>
     */
    private const REQUIRED_FRONTMATTER = [
        'id',
        'type',
        'title',
        'status',
        'category',
        'priority',
        'summary',
        'tags',
        'capabilities',
        'decisions',
        'maintenance',
        'related_paths',
    ];

    /**
     * @var array<int,string>
     */
    private const CANONICAL_MODULE_REQUIRED_FRONTMATTER = [
        'doc_schema',
        'graph_id',
        'graph_title',
        'graph_world',
        'graph_layer',
        'graph_kind',
        'graph_parent',
        'graph_status',
        'graph_source',
        'owner',
        'repo_paths',
        'allowed_changes',
        'forbidden_changes',
        'depends_on',
        'flows_to',
        'unlocks',
        'governs',
        'evidence',
        'required_tests',
        'requires_evidence',
        'risk_level',
        'next_actions',
    ];

    /**
     * Required naming fields for macro structural docs. A macro doc is any
     * canonical module with macro_layer=true, or any doc that starts declaring
     * one of these fields. The explicit marker prevents retroactive failures on
     * legacy docs while making the rule enforceable for every new macro layer.
     *
     * @var array<int,string>
     */
    private const CANONICAL_MACRO_NAMING_FRONTMATTER = [
        'product_name',
        'runtime_acronym',
        'internal_product_name',
        'technical_runtime',
    ];

    /**
     * Fields required on the docs that define Atlas documentation reality and
     * human cartography. They are intentionally separate from generic
     * canonical_module fields because legacy docs can stay technical, while
     * these operator-facing docs must be readable by humans and safe for AI
     * bootstrap.
     *
     * @var array<int,string>
     */
    private const HUMAN_GOLD_FRONTMATTER = [
        'human_summary',
        'human_what',
        'human_purpose',
        'human_input',
        'human_output',
        'human_change_when',
        'human_block_when',
    ];

    /**
     * P0 documentation reality docs. Any degradation here means future agents
     * and the mobile Cartografia can receive context that is technically valid
     * but hard for humans to understand.
     *
     * @var array<int,string>
     */
    private const HUMAN_GOLD_GRAPH_IDS = [
        'atlas-ai-documentation-operating-system',
        'atlas-ai-knowledge-governance-system',
        'atlas-cartography-nomenclature-contract',
        'atlas-canonical-glossary-and-naming',
        'atlas-documentation-reality-system',
        'atlas-code-reality-usage-intelligence',
        'atlas-universal-reality-cartography',
        'atlas-unified-context-retrieval-intelligence',
        'atlas-aucri-continuous-optimization-protocol',
    ];

    /**
     * @var array<int,string>
     */
    private const CANONICAL_MODULE_OPTIONAL_LIST_FRONTMATTER = [
        'related_to',
        'influenced_by',
        'runtime_surfaces',
        'mcp_tools',
        'decision_receipts',
        'visual_tags',
        'ai_entrypoints',
        'ai_usage_notes',
        'quality_gates',
        'failure_modes',
        'observability_signals',
        'patamar_after',
        'versions',
    ];

    /**
     * @var array<int,string>
     */
    private const CANONICAL_MODULE_REQUIRED_SECTIONS = [
        'Resumo',
        'Papel no Atlas',
        'Onde Se Encaixa',
        'Contratos',
        'Fluxo',
        'Regras para IA',
        'Escopo de Implementacao',
        'Dependencias',
        'Evidencias',
        'Riscos',
        'Exemplos',
        'Proximas Acoes',
    ];

    /**
     * @var array<int,string>
     */
    private const CANONICAL_MODULE_ALLOWED_STATUS = [
        'planned',
        'future',
        'building',
        'active',
        'deprecated',
    ];

    /**
     * @var array<int,string>
     */
    private const CANONICAL_MODULE_ALLOWED_LAYERS = [
        'world',
        'system',
        'flow',
        'module',
        'gear',
        'subcomponent',
    ];

    /**
     * @var array<int,string>
     */
    private const CANONICAL_MODULE_ALLOWED_KINDS = [
        'contract',
        'system',
        'flow',
        'module',
        'policy',
        'runbook',
        'adr',
        'index',
        'surface',
        'screen',
        'step',
    ];

    /**
     * @var array<int,string>
     */
    private const CANONICAL_MODULE_ALLOWED_RISK = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    /**
     * Non-canonical status values tolerated on canonical_module docs without
     * raising a warning. Anything outside this list AND outside
     * CANONICAL_MODULE_ALLOWED_STATUS triggers the status-value warning.
     *
     * @var array<int,string>
     */
    private const STATUS_VALUE_TOLERATED_LEGACY = [
        'archived',
        'source_material',
        'template',     // self-declared scaffolding (e.g. self-construction/*), not a lifecycle module doc — no canonical status applies
    ];

    /**
     * Ambiguous naming clusters surfaced by the Atlas canonical glossary.
     * A canonical_module doc whose title/body mentions one of these terms
     * without referencing the glossary doc triggers a warning.
     *
     * @var array<int,string>
     */
    private const AMBIGUOUS_NAMING_TERMS = [
        'Atlas Forge',
        'Atlas Code Forge',
        'Atlas Code Obra',
        'Forge Continuum',
        'Obra Command Center',
        'Dual Core',
        'Dev Forge',
        'Dev/Forge',
        'AtlasDev',
        'AtlasForge',
    ];

    private const CANONICAL_GLOSSARY_PATH = 'docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md';

    private const CANONICAL_GLOSSARY_ID = 'atlas-canonical-glossary-and-naming';

    private const AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH = 'docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md';

    private const AGENTIC_ENGINEERING_AUTHORITY_MAP_ID = 'atlas-agentic-software-engineering-authority-map';

    private const AGENTIC_ENGINEERING_INVENTORY_PATH = 'docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md';

    private const AGENTIC_ENGINEERING_INVENTORY_ID = 'atlas-agentic-engineering-documentation-inventory';

    /**
     * Docs that anchor the living Agentic Software Engineering hierarchy.
     * If these stop linking to the authority map/inventory, future agents can
     * again treat Dev, Forge, Atlas Code, TEOS, Rivals or provider research as
     * parallel systems.
     *
     * @var array<string,array{requires:array<int,string>, reason:string}>
     */
    private const AGENTIC_ENGINEERING_AUTHORITY_LINKS = [
        'docs/engineering-knowledge-base/START_HERE.md' => [
            'requires' => [
                self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
                self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            ],
            'reason' => 'global bootstrap must point new agents at the Agentic Engineering authority chain',
        ],
        'docs/engineering-knowledge-base/README.md' => [
            'requires' => [
                self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
                self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            ],
            'reason' => 'KB overview must expose the Agentic Engineering authority chain',
        ],
        self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH => [
            'requires' => [self::AGENTIC_ENGINEERING_INVENTORY_PATH],
            'reason' => 'authority map must delegate scattered family classification to the inventory',
        ],
        self::AGENTIC_ENGINEERING_INVENTORY_PATH => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'inventory must stay subordinate to the authority map',
        ],
        'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md' => [
            'requires' => [
                self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
                self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            ],
            'reason' => 'system mother doc must link to hierarchy and inventory',
        ],
        'docs/engineering-knowledge-base/atlas-dev-index.md' => [
            'requires' => [
                self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
                self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            ],
            'reason' => 'Atlas Dev entrypoint must not become a parallel hierarchy',
        ],
        'docs/engineering-knowledge-base/atlas-programming-governance-system.md' => [
            'requires' => [
                self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
                self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            ],
            'reason' => 'Programming Governance must stay the governed programming flow below Agentic Engineering',
        ],
        'docs/engineering-knowledge-base/atlas-programming-forge-flow.md' => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'Forge Flow must stay below Agentic Engineering hierarchy',
        ],
        'docs/engineering-knowledge-base/atlas-forge-continuum-os.md' => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'Forge Continuum must stay a programming-heavy specialization',
        ],
        'docs/engineering-knowledge-base/atlas-forge-operating-system.md' => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'Forge OS must stay the factory below Forge Continuum',
        ],
        'docs/engineering-knowledge-base/atlas-desktop-code-surface.md' => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'Atlas Code surface must not be read as the whole OS',
        ],
        'docs/engineering-knowledge-base/atlas-code-category-evolution.md' => [
            'requires' => [
                self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
                self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            ],
            'reason' => 'Atlas Code category doc must stay product/surface scoped',
        ],
        'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md' => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'TEOS must stay temporal/north-star and not replace the hierarchy',
        ],
        'docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md' => [
            'requires' => [
                self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
                self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            ],
            'reason' => 'superiority docs must stay strategy/benchmark, not architecture mother docs',
        ],
        'docs/engineering-knowledge-base/atlas-intelligence-factory-os.md' => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'Intelligence Factory must not replace Agentic Engineering OS',
        ],
        'docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md' => [
            'requires' => [self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH],
            'reason' => 'AAWR must not replace Dev, Forge or Programming Governance',
        ],
    ];

    /**
     * @var array<string,string>
     */
    private const GRANDFATHERED_SPLIT_REQUIRED = [
        'docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md' => 'split Kernel APs into focused contract specs before adding new sections',
        'docs/engineering-knowledge-base/atlas-ai-master-architecture.md' => 'extract domain playbooks and keep this as Layer 2 reference',
        'docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md' => 'split roadmap into AP and phase specs for execution',
        'docs/engineering-knowledge-base/START_HERE.md' => 'keep as full reading order; use session bootstrap for new-session context',
    ];

    public function __construct(private readonly CanonicalDocsFrontmatterParser $frontmatter) {}

    /**
     * @return array<string,mixed>
     */
    /**
     * The docs-health report. The filesystem walk + per-doc frontmatter parse (scanDocs) is the
     * expensive part, and one create orchestration calls this MORE THAN ONCE with identical input
     * (the session-bootstrap docs gate and the docs split plan). It is now computed ONCE per
     * request and reused.
     *
     * The cache key is the resolved docs root PLUS a cheap content signature of that corpus (a
     * single stat-only walk: relative path + mtime + size of every .md file, NEVER reading or
     * parsing them). Identical inputs in one request collapse 2 -> 1, while a genuinely changed
     * corpus (including a mid-request mutation) produces a different key and correctly recomputes
     * — never a stale result. The signature walk is far cheaper than the scan+parse it guards, so
     * the reused call costs milliseconds instead of a full corpus parse.
     *
     * The computed report is cached in the container as a `scoped` binding (request-local, reset
     * between requests by Laravel's forgetScopedInstances), so even the DIFFERENT autowired
     * instances the create path builds share the SAME array — byte-for-byte what one
     * analyzeDocs(scanDocs(), baseline) produced. With no container (a unit test that `new`s the
     * service standalone) it falls back to a per-instance memo — still correct, still
     * compute-once per identical corpus.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $root = $this->docsRoot();
        $cacheKey = $root.'@'.$this->corpusSignature($root);

        if (isset($this->reportMemo[$cacheKey])) {
            return $this->reportMemo[$cacheKey];
        }

        if (! function_exists('app') || ! app()->bound('app')) {
            return $this->reportMemo[$cacheKey] = $this->analyzeDocs($this->scanDocs(), $this->loadBaselineSet());
        }

        $container = app();
        $key = self::SHARED_REPORT_KEY.':'.$cacheKey;
        if (! $container->bound($key)) {
            $container->scoped($key, fn (): array => $this->analyzeDocs($this->scanDocs(), $this->loadBaselineSet()));
        }

        return $this->reportMemo[$cacheKey] = $container->make($key);
    }

    /**
     * A cheap, stat-only content signature of the docs corpus under $root: a sha256 over each
     * .md file's relative path + mtime + size, in sorted order. It NEVER reads or parses file
     * contents, so it is far cheaper than the scan+analysis it keys — yet it changes the instant
     * any doc is added, removed, or edited (mtime/size move), which is what makes the memo safe
     * to reuse only for a genuinely identical corpus. A missing root yields a stable 'absent'
     * marker so the empty-corpus report path is itself memoized once.
     */
    private function corpusSignature(string $root): string
    {
        if (! File::isDirectory($root)) {
            return 'absent';
        }

        $parts = [];
        foreach (File::allFiles($root) as $file) {
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $parts[] = $file->getRelativePathname().':'.$file->getMTime().':'.$file->getSize();
        }
        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Analyze an already-parsed set of docs. Exposed for testability so
     * unit tests can supply fixtures without touching the filesystem.
     *
     * The optional $baselineSet (a map of frozen violation string => true)
     * powers the docs-health ratchet: violations present in the baseline are
     * classified as legacy_debt (non-blocking, frozen), and any violation NOT
     * in the baseline is blocking (a regression). The legacy `status` field is
     * kept exactly as before (ok|failed) so every existing consumer/test is
     * untouched; the new `enforcement` block carries the green|debt_holding|failed
     * truth that gates and `--enforce` consume.
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @param  array<string,bool>  $baselineSet
     * @return array<string,mixed>
     */
    public function analyzeDocs(array $docs, array $baselineSet = []): array
    {
        $required = $this->requiredDocsReport($docs);
        $frontmatterViolations = $this->frontmatterViolations($docs);
        $canonicalCoverageViolations = $this->canonicalModuleCoverageViolations($docs);
        $canonicalViolations = $this->canonicalModuleViolations($docs);
        $humanGoldViolations = $this->humanGoldDocumentationViolations($docs);
        $agenticAuthorityViolations = $this->agenticEngineeringAuthorityViolations($docs);
        $violations = array_values(array_merge(
            $required['missing'],
            $frontmatterViolations,
            $canonicalCoverageViolations,
            $canonicalViolations,
            $humanGoldViolations,
            $agenticAuthorityViolations,
        ));
        $warnings = $this->collectWarnings($docs);
        $oversized = $this->oversizedDocs($docs);

        $blocking = [];
        $legacyDebt = [];
        foreach ($violations as $violation) {
            if (isset($baselineSet[$violation])) {
                $legacyDebt[] = $violation;
            } else {
                $blocking[] = $violation;
            }
        }
        $enforcementStatus = $blocking !== []
            ? 'failed'
            : ($legacyDebt !== [] ? 'debt_holding' : 'green');

        return [
            'status' => $violations === [] ? 'ok' : 'failed',
            'summary' => [
                'docs_root' => $this->relativePath($this->docsRoot()),
                'doc_count' => count($docs),
                'required_doc_count' => count(self::REQUIRED_DOCS),
                'required_missing_count' => count($required['missing']),
                'oversized_count' => count($oversized),
                'frontmatter_violation_count' => count($frontmatterViolations),
                'canonical_module_coverage_violation_count' => count($canonicalCoverageViolations),
                'canonical_module_violation_count' => count($canonicalViolations),
                'human_gold_violation_count' => count($humanGoldViolations),
                'agentic_engineering_authority_violation_count' => count($agenticAuthorityViolations),
                'warning_count' => count($warnings),
                'blocking_count' => count($blocking),
                'legacy_debt_count' => count($legacyDebt),
                'baseline_count' => count($baselineSet),
            ],
            'enforcement' => [
                'status' => $enforcementStatus,
                'blocking_count' => count($blocking),
                'legacy_debt_count' => count($legacyDebt),
                'baseline_count' => count($baselineSet),
                'ratchet' => 'monotonic_decrease_only',
            ],
            'required_docs' => $required['items'],
            'oversized_docs' => $oversized,
            'violations' => $violations,
            'blocking' => $blocking,
            'legacy_debt' => $legacyDebt,
            'warnings' => $warnings,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * Build the docs-health baseline payload from the CURRENT violations,
     * always computed against an empty baseline so the freeze captures the
     * full present debt. Sorted for a stable, diff-friendly lockfile.
     *
     * @return array<string,mixed>
     */
    public function buildBaselinePayload(): array
    {
        $violations = $this->analyzeDocs($this->scanDocs(), [])['violations'];
        sort($violations);

        return [
            'schema_version' => 'atlas.docs_health.baseline.v1',
            'ratchet' => 'monotonic_decrease_only',
            'frozen_at' => now()->toJSON(),
            'violation_count' => count($violations),
            'violations' => array_values($violations),
        ];
    }

    /**
     * Freeze the current violations into the baseline lockfile and return the
     * written payload. After this, docs-health --enforce blocks only on NEW
     * violations (regressions), never on the frozen legacy debt.
     *
     * @return array<string,mixed>
     */
    public function freezeBaseline(): array
    {
        $payload = $this->buildBaselinePayload();
        $path = $this->baselinePath();
        File::ensureDirectoryExists(dirname($path));
        File::put(
            $path,
            (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}').PHP_EOL,
        );

        return $payload;
    }

    /**
     * Load the frozen baseline as a set (violation string => true) for O(1)
     * classification. Returns an empty set when no baseline exists yet, which
     * keeps every current violation blocking (identical to pre-baseline behavior).
     *
     * @return array<string,bool>
     */
    private function loadBaselineSet(): array
    {
        $path = $this->baselinePath();
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded) || ! is_array($decoded['violations'] ?? null)) {
            return [];
        }

        $set = [];
        foreach ($decoded['violations'] as $violation) {
            $set[(string) $violation] = true;
        }

        return $set;
    }

    public function baselinePath(): string
    {
        return $this->docsRoot().DIRECTORY_SEPARATOR.'.governance'.DIRECTORY_SEPARATOR.'docs-health-baseline.json';
    }

    public function relativeBaselinePath(): string
    {
        return $this->relativePath($this->baselinePath());
    }

    public function baselineExists(): bool
    {
        return File::exists($this->baselinePath());
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scanDocs(): array
    {
        $root = $this->docsRoot();
        if (! File::isDirectory($root)) {
            return [];
        }

        return collect(File::allFiles($root))
            ->filter(fn (SplFileInfo $file): bool => strtolower($file->getExtension()) === 'md')
            ->map(function (SplFileInfo $file): array {
                $path = $file->getPathname();
                $markdown = File::get($path);
                $parsed = $this->frontmatter->parse($markdown);
                $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
                $relativePath = $this->relativePath($path);

                return [
                    'path' => $relativePath,
                    'line_count' => substr_count($markdown, "\n") + 1,
                    'status' => (string) ($frontmatter['status'] ?? 'missing'),
                    'type' => (string) ($frontmatter['type'] ?? 'missing'),
                    'category' => (string) ($frontmatter['category'] ?? 'missing'),
                    'frontmatter' => $frontmatter,
                    'frontmatter_errors' => array_values((array) ($parsed['errors'] ?? [])),
                    'body' => (string) ($parsed['body'] ?? ''),
                    'limit' => $this->lineLimit($relativePath, $frontmatter),
                ];
            })
            ->sortBy('path')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array{items:array<int,array<string,mixed>>,missing:array<int,string>}
     */
    private function requiredDocsReport(array $docs): array
    {
        return $this->violationRules()->requiredDocsReport($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function frontmatterViolations(array $docs): array
    {
        return $this->violationRules()->frontmatterViolations($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function humanGoldDocumentationViolations(array $docs): array
    {
        return $this->violationRules()->humanGoldDocumentationViolations($docs);
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function nonEmptyListStrings(array $items): array
    {
        return $this->violationRules()->nonEmptyListStrings($items);
    }

    private function looksLikeInternalPrompt(string $value): bool
    {
        return $this->violationRules()->looksLikeInternalPrompt($value);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function canonicalModuleCoverageViolations(array $docs): array
    {
        return $this->violationRules()->canonicalModuleCoverageViolations($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function canonicalModuleViolations(array $docs): array
    {
        return $this->violationRules()->canonicalModuleViolations($docs);
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function requiresMacroNaming(array $frontmatter): bool
    {
        return $this->violationRules()->requiresMacroNaming($frontmatter);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function agenticEngineeringAuthorityViolations(array $docs): array
    {
        return $this->violationRules()->agenticEngineeringAuthorityViolations($docs);
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function referencesDoc(array $frontmatter, string $body, string $requiredPath): bool
    {
        return $this->violationRules()->referencesDoc($frontmatter, $body, $requiredPath);
    }

    private function violationRules(): EngineeringDocumentationViolationRules
    {
        return $this->violationRulesInstance ??= new EngineeringDocumentationViolationRules(
            self::REQUIRED_DOCS,
            self::REQUIRED_FRONTMATTER,
            self::HUMAN_GOLD_FRONTMATTER,
            self::HUMAN_GOLD_GRAPH_IDS,
            self::CANONICAL_MODULE_SCHEMA,
            self::CANONICAL_MODULE_REQUIRED_FRONTMATTER,
            self::CANONICAL_MACRO_NAMING_FRONTMATTER,
            self::CANONICAL_MODULE_ALLOWED_STATUS,
            self::CANONICAL_MODULE_ALLOWED_LAYERS,
            self::CANONICAL_MODULE_ALLOWED_KINDS,
            self::CANONICAL_MODULE_ALLOWED_RISK,
            self::CANONICAL_MODULE_OPTIONAL_LIST_FRONTMATTER,
            self::CANONICAL_MODULE_REQUIRED_SECTIONS,
            self::AGENTIC_ENGINEERING_AUTHORITY_LINKS,
            self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH,
            self::AGENTIC_ENGINEERING_AUTHORITY_MAP_ID,
            self::AGENTIC_ENGINEERING_INVENTORY_PATH,
            self::AGENTIC_ENGINEERING_INVENTORY_ID,
            fn (string $path): bool => $this->isNonCanonicalArtifactPath($path),
        );
    }

    /**
     * Aggregate every non-blocking warning produced by the soft rules.
     * Warnings DO NOT block the gate (status stays "ok" when violations
     * are empty) — they only surface issues operators should address
     * before they grow into divergence between docs and runtime.
     *
     * Each warning entry: { rule: string, path: string, message: string }
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function collectWarnings(array $docs): array
    {
        return $this->warningCollector()->collectWarnings($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function statusValueWarnings(array $docs): array
    {
        return $this->warningCollector()->statusValueWarnings($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function futurePlannedClarityWarnings(array $docs): array
    {
        return $this->warningCollector()->futurePlannedClarityWarnings($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function deprecatedSuccessorWarnings(array $docs): array
    {
        return $this->warningCollector()->deprecatedSuccessorWarnings($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function schemaCitationWarnings(array $docs): array
    {
        return $this->warningCollector()->schemaCitationWarnings($docs);
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function ambiguousNamingWarnings(array $docs): array
    {
        return $this->warningCollector()->ambiguousNamingWarnings($docs);
    }

    public function mentionsNonRuntimeMarker(string $haystack): bool
    {
        return $this->warningCollector()->mentionsNonRuntimeMarker($haystack);
    }

    /**
     * @return array<int,string>
     */
    public function ambiguousTermsFound(string $haystack): array
    {
        return $this->warningCollector()->ambiguousTermsFound($haystack);
    }

    private function warningCollector(): EngineeringDocumentationWarningCollector
    {
        return $this->warningCollectorInstance ??= new EngineeringDocumentationWarningCollector(
            self::AMBIGUOUS_NAMING_TERMS,
            self::CANONICAL_GLOSSARY_ID,
            self::CANONICAL_GLOSSARY_PATH,
            self::CANONICAL_MODULE_ALLOWED_STATUS,
            self::CANONICAL_MODULE_SCHEMA,
            self::NON_CANONICAL_ARTIFACT_PATH_MARKERS,
            self::STATUS_VALUE_TOLERATED_LEGACY,
            fn (string $path): bool => $this->shouldSkipPath($path),
            fn (array $frontmatter, string $body): bool => $this->referencesGlossary($frontmatter, $body),
            fn (): string => $this->relativeGlossaryPath(),
        );
    }

    /**
     * Centralized skip predicate shared by the soft rules.
     */
    private function shouldSkipPath(string $path): bool
    {
        return str_contains($path, '/archive/')
            || str_contains($path, '/templates/')
            || $this->isNonCanonicalArtifactPath($path);
    }

    /**
     * True when the path lives under a derived/visual artifact subtree
     * (see self::NON_CANONICAL_ARTIFACT_PATH_MARKERS). Such docs are Human
     * Knowledge Surfaces, not canonical module contracts, so the canonical
     * frontmatter, coverage, module and line-limit rules skip them — exactly
     * as /archive/ and /templates/ are skipped.
     */
    private function isNonCanonicalArtifactPath(string $path): bool
    {
        foreach (self::NON_CANONICAL_ARTIFACT_PATH_MARKERS as $marker) {
            if (str_contains($path, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function referencesGlossary(array $frontmatter, string $body): bool
    {
        foreach (['related_paths', 'depends_on', 'flows_to', 'governs', 'related_to', 'influenced_by'] as $field) {
            foreach ((array) ($frontmatter[$field] ?? []) as $value) {
                $value = (string) $value;
                if ($value === self::CANONICAL_GLOSSARY_PATH || $value === self::CANONICAL_GLOSSARY_ID) {
                    return true;
                }
                if (str_contains($value, 'atlas-canonical-glossary-and-naming')) {
                    return true;
                }
            }
        }

        return str_contains($body, 'atlas-canonical-glossary-and-naming');
    }

    private function relativeGlossaryPath(): string
    {
        return self::CANONICAL_GLOSSARY_PATH;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,mixed>>
     */
    private function oversizedDocs(array $docs): array
    {
        return collect($docs)
            ->filter(function (array $doc): bool {
                $limit = $doc['limit'];

                return is_int($limit) && (int) $doc['line_count'] > $limit;
            })
            ->map(fn (array $doc): array => [
                'path' => $doc['path'],
                'line_count' => $doc['line_count'],
                'limit' => $doc['limit'],
                'status' => array_key_exists($doc['path'], self::GRANDFATHERED_SPLIT_REQUIRED)
                    ? 'split_required_grandfathered'
                    : 'split_required',
                'recommended_action' => self::GRANDFATHERED_SPLIT_REQUIRED[$doc['path']]
                    ?? 'split this active doc into focused specs before adding new responsibilities',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function lineLimit(string $path, array $frontmatter): ?int
    {
        if (array_key_exists($path, self::REQUIRED_DOCS)) {
            return self::REQUIRED_DOCS[$path];
        }
        if ($this->isNonCanonicalArtifactPath($path)) {
            return null;
        }
        if (in_array((string) ($frontmatter['status'] ?? ''), ['archived', 'source_material'], true)) {
            return null;
        }
        if (($frontmatter['doc_schema'] ?? null) === self::CANONICAL_MODULE_SCHEMA) {
            return 520;
        }
        if (str_contains($path, '/archive/')) {
            return null;
        }
        if (str_contains($path, '/domains/')) {
            return 260;
        }
        if (str_contains($path, 'runbook')) {
            return 220;
        }
        if (str_contains($path, 'audit')) {
            return 350;
        }
        if (str_contains($path, 'roadmap')) {
            return 300;
        }
        if (($frontmatter['category'] ?? null) === 'onboarding') {
            return 180;
        }

        return 300;
    }

    private function docsRoot(): string
    {
        return base_path('docs/engineering-knowledge-base');
    }

    private function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
