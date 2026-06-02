<?php

namespace App\Services\Engineering;

use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class EngineeringDocumentationHealthService
{
    private const CANONICAL_MODULE_SCHEMA = 'atlas_canonical_module_doc.v1';

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
        'ForgeRivals',
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
    public function report(): array
    {
        return $this->analyzeDocs($this->scanDocs(), $this->loadBaselineSet());
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
        $byPath = collect($docs)->keyBy('path');
        $items = [];
        $missing = [];

        foreach (self::REQUIRED_DOCS as $path => $limit) {
            $doc = $byPath->get($path);
            $exists = is_array($doc);
            if (! $exists) {
                $missing[] = "{$path}: required documentation bootstrap file is missing";
            }

            $items[] = [
                'path' => $path,
                'exists' => $exists,
                'line_count' => $exists ? (int) $doc['line_count'] : null,
                'limit' => $limit,
            ];
        }

        return ['items' => $items, 'missing' => $missing];
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function frontmatterViolations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $status = (string) $doc['status'];
            if (str_contains($path, '/archive/')
                || $this->isNonCanonicalArtifactPath($path)
                || in_array($status, ['archived', 'source_material'], true)) {
                continue;
            }

            $frontmatter = (array) $doc['frontmatter'];
            foreach (self::REQUIRED_FRONTMATTER as $field) {
                if (! array_key_exists($field, $frontmatter) || $frontmatter[$field] === [] || $frontmatter[$field] === '') {
                    $violations[] = "{$path}: missing required frontmatter field [{$field}]";
                }
            }

            foreach ((array) $doc['frontmatter_errors'] as $error) {
                $violations[] = "{$path}: frontmatter parse error [{$error}]";
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function humanGoldDocumentationViolations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $frontmatter = (array) $doc['frontmatter'];
            $graphId = (string) ($frontmatter['graph_id'] ?? '');
            if (! in_array($graphId, self::HUMAN_GOLD_GRAPH_IDS, true)) {
                continue;
            }

            foreach (self::HUMAN_GOLD_FRONTMATTER as $field) {
                if (! array_key_exists($field, $frontmatter) || trim((string) $frontmatter[$field]) === '') {
                    $violations[] = "{$path}: human gold doc missing field [{$field}]";
                }
            }

            foreach (['depends_on', 'flows_to', 'unlocks', 'governs'] as $field) {
                $value = $frontmatter[$field] ?? null;
                if (! is_array($value) || $this->nonEmptyListStrings($value) === []) {
                    $violations[] = "{$path}: human gold doc relation [{$field}] must be a non-empty list";
                }
            }

            $summaryFields = [
                'summary' => (string) ($frontmatter['summary'] ?? ''),
                'human_summary' => (string) ($frontmatter['human_summary'] ?? ''),
            ];
            foreach ($summaryFields as $field => $value) {
                if ($this->looksLikeInternalPrompt($value)) {
                    $violations[] = "{$path}: human gold doc field [{$field}] looks like internal prompt/task text";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function nonEmptyListStrings(array $items): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $items
        ), fn (string $item): bool => $item !== ''));
    }

    private function looksLikeInternalPrompt(string $value): bool
    {
        $value = strtolower($value);
        foreach ([
            'prompt completo',
            'prompt para',
            'me manda',
            'voce pediu',
            'você pediu',
            'manda o claude',
            'manda o codex',
            'manda o gemini',
            'goal enorme',
            'type something',
            'chat about this',
            'skip interview',
        ] as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function canonicalModuleCoverageViolations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $status = (string) $doc['status'];
            if (str_contains($path, '/archive/') || str_contains($path, '/templates/') || $this->isNonCanonicalArtifactPath($path) || in_array($status, ['archived', 'source_material'], true)) {
                continue;
            }

            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== self::CANONICAL_MODULE_SCHEMA) {
                $violations[] = "{$path}: official non-archive docs must declare doc_schema [".self::CANONICAL_MODULE_SCHEMA.']';
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function canonicalModuleViolations(array $docs): array
    {
        $violations = [];
        $graphIds = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== self::CANONICAL_MODULE_SCHEMA) {
                continue;
            }
            if (str_contains($path, '/templates/') || $this->isNonCanonicalArtifactPath($path)) {
                continue;
            }

            foreach (self::CANONICAL_MODULE_REQUIRED_FRONTMATTER as $field) {
                if (! array_key_exists($field, $frontmatter) || $frontmatter[$field] === [] || $frontmatter[$field] === '') {
                    $violations[] = "{$path}: missing canonical module field [{$field}]";
                }
            }

            if (array_key_exists('macro_layer', $frontmatter) && ! is_bool($frontmatter['macro_layer'])) {
                $violations[] = "{$path}: canonical module field [macro_layer] must be boolean";
            }

            if ($this->requiresMacroNaming($frontmatter)) {
                foreach (self::CANONICAL_MACRO_NAMING_FRONTMATTER as $field) {
                    if (! array_key_exists($field, $frontmatter) || trim((string) $frontmatter[$field]) === '') {
                        $violations[] = "{$path}: macro structural layer missing required naming field [{$field}]";
                    }
                }
            }

            $graphId = trim((string) ($frontmatter['graph_id'] ?? ''));
            if ($graphId !== '') {
                if (isset($graphIds[$graphId])) {
                    $violations[] = "{$path}: duplicate graph_id [{$graphId}] already used by {$graphIds[$graphId]}";
                }
                $graphIds[$graphId] = $path;
                if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $graphId)) {
                    $violations[] = "{$path}: graph_id [{$graphId}] must be a stable lowercase ASCII slug";
                }
            }

            $graphStatus = (string) ($frontmatter['graph_status'] ?? '');
            if ($graphStatus !== '' && ! in_array($graphStatus, self::CANONICAL_MODULE_ALLOWED_STATUS, true)) {
                $violations[] = "{$path}: graph_status [{$graphStatus}] is not allowed";
            }

            $graphLayer = (string) ($frontmatter['graph_layer'] ?? '');
            if ($graphLayer !== '' && ! in_array($graphLayer, self::CANONICAL_MODULE_ALLOWED_LAYERS, true)) {
                $violations[] = "{$path}: graph_layer [{$graphLayer}] is not allowed";
            }

            $graphKind = (string) ($frontmatter['graph_kind'] ?? '');
            if ($graphKind !== '' && ! in_array($graphKind, self::CANONICAL_MODULE_ALLOWED_KINDS, true)) {
                $violations[] = "{$path}: graph_kind [{$graphKind}] is not allowed";
            }

            $graphSource = (string) ($frontmatter['graph_source'] ?? '');
            if ($graphSource !== '' && $graphSource !== 'repo') {
                $violations[] = "{$path}: graph_source must be [repo] for canonical engineering docs";
            }

            $riskLevel = (string) ($frontmatter['risk_level'] ?? '');
            if ($riskLevel !== '' && ! in_array($riskLevel, self::CANONICAL_MODULE_ALLOWED_RISK, true)) {
                $violations[] = "{$path}: risk_level [{$riskLevel}] is not allowed";
            }

            foreach (['repo_paths', 'allowed_changes', 'forbidden_changes', 'evidence', 'required_tests', 'next_actions'] as $field) {
                if (array_key_exists($field, $frontmatter) && ! is_array($frontmatter[$field])) {
                    $violations[] = "{$path}: canonical module field [{$field}] must be a list";
                }
            }

            foreach (self::CANONICAL_MODULE_OPTIONAL_LIST_FRONTMATTER as $field) {
                if (array_key_exists($field, $frontmatter) && ! is_array($frontmatter[$field])) {
                    $violations[] = "{$path}: optional canonical module field [{$field}] must be a list";
                }
            }

            if (array_key_exists('requires_evidence', $frontmatter) && ! is_bool($frontmatter['requires_evidence'])) {
                $violations[] = "{$path}: canonical module field [requires_evidence] must be boolean";
            }

            foreach ((array) ($frontmatter['repo_paths'] ?? []) as $repoPath) {
                $repoPath = trim((string) $repoPath);
                if ($repoPath === '' || str_starts_with($repoPath, 'external:') || str_starts_with($repoPath, 'future:')) {
                    continue;
                }
                if (! file_exists(base_path($repoPath))) {
                    $violations[] = "{$path}: repo_paths entry [{$repoPath}] does not exist";
                }
            }

            $body = (string) ($doc['body'] ?? '');
            foreach (self::CANONICAL_MODULE_REQUIRED_SECTIONS as $section) {
                if (! preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/mi', $body)) {
                    $violations[] = "{$path}: missing canonical module section [{$section}]";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function requiresMacroNaming(array $frontmatter): bool
    {
        if (($frontmatter['macro_layer'] ?? false) === true) {
            return true;
        }

        foreach (self::CANONICAL_MACRO_NAMING_FRONTMATTER as $field) {
            if (array_key_exists($field, $frontmatter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Agentic Engineering authority chain is a hard gate because the user
     * explicitly wants no future AI to confuse the hierarchy. This rule does
     * not classify every historical doc; it protects the living entrypoints and
     * the docs most likely to be mistaken for competing systems.
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    private function agenticEngineeringAuthorityViolations(array $docs): array
    {
        $violations = [];
        $byPath = collect($docs)->keyBy('path');

        foreach (self::AGENTIC_ENGINEERING_AUTHORITY_LINKS as $path => $rule) {
            $doc = $byPath->get($path);
            if (! is_array($doc)) {
                $violations[] = "{$path}: missing Agentic Engineering authority-chain doc [{$rule['reason']}]";
                continue;
            }

            $frontmatter = (array) ($doc['frontmatter'] ?? []);
            $body = (string) ($doc['body'] ?? '');
            foreach ($rule['requires'] as $requiredPath) {
                if ($this->referencesDoc($frontmatter, $body, $requiredPath)) {
                    continue;
                }

                $violations[] = "{$path}: Agentic Engineering authority chain must reference [{$requiredPath}] ({$rule['reason']})";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function referencesDoc(array $frontmatter, string $body, string $requiredPath): bool
    {
        $requiredId = match ($requiredPath) {
            self::AGENTIC_ENGINEERING_AUTHORITY_MAP_PATH => self::AGENTIC_ENGINEERING_AUTHORITY_MAP_ID,
            self::AGENTIC_ENGINEERING_INVENTORY_PATH => self::AGENTIC_ENGINEERING_INVENTORY_ID,
            default => preg_replace('/\.md$/', '', basename($requiredPath)) ?: $requiredPath,
        };
        foreach (['related_paths', 'depends_on', 'flows_to', 'governs', 'related_to', 'influenced_by', 'evidence'] as $field) {
            foreach ((array) ($frontmatter[$field] ?? []) as $value) {
                $value = (string) $value;
                if ($value === $requiredPath || $value === $requiredId) {
                    return true;
                }
                if (str_contains($value, basename($requiredPath))) {
                    return true;
                }
            }
        }

        return str_contains($body, basename($requiredPath)) || str_contains($body, $requiredPath);
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
        return array_values(array_merge(
            $this->statusValueWarnings($docs),
            $this->futurePlannedClarityWarnings($docs),
            $this->deprecatedSuccessorWarnings($docs),
            $this->schemaCitationWarnings($docs),
            $this->ambiguousNamingWarnings($docs),
        ));
    }

    /**
     * Rule W1 — canonical_module status must be one of the five admitted
     * values (planned/future/building/active/deprecated). Non-canonical
     * values (draft/scaffold/proposed/canon/split_required) are warnings
     * because the cleanup plan (atlas-documentation-status-cleanup-plan.md)
     * sequences their flip — blocking now would explode the repo.
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function statusValueWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if ($this->shouldSkipPath($path)) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== self::CANONICAL_MODULE_SCHEMA) {
                continue;
            }
            $status = (string) $doc['status'];
            if ($status === '' || $status === 'missing') {
                continue;
            }
            if (in_array($status, self::CANONICAL_MODULE_ALLOWED_STATUS, true)) {
                continue;
            }
            if (in_array($status, self::STATUS_VALUE_TOLERATED_LEGACY, true)) {
                continue;
            }
            $warnings[] = [
                'rule' => 'status_value_non_canonical',
                'path' => $path,
                'message' => "{$path}: status [{$status}] is not in canonical set [planned|future|building|active|deprecated]; see atlas-documentation-status-cleanup-plan.md",
            ];
        }

        return $warnings;
    }

    /**
     * Rule W2 — docs in status [future] or [planned] should make their
     * non-runtime nature obvious so downstream readers don't mistake
     * vision for current runtime. Tolerated when one of: (a)
     * implementation_state frontmatter is declared; (b) summary/body
     * contains an explicit non-runtime marker (e.g., "nao construido",
     * "tese", "future", "planned", "not implemented", "blocker").
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function futurePlannedClarityWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if ($this->shouldSkipPath($path)) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== self::CANONICAL_MODULE_SCHEMA) {
                continue;
            }
            $status = (string) $doc['status'];
            if (! in_array($status, ['future', 'planned'], true)) {
                continue;
            }
            if (array_key_exists('implementation_state', $frontmatter)
                && trim((string) $frontmatter['implementation_state']) !== '') {
                continue;
            }
            if (array_key_exists('blocker', $frontmatter)
                && trim((string) $frontmatter['blocker']) !== '') {
                continue;
            }
            $summary = strtolower((string) ($frontmatter['summary'] ?? ''));
            $body = strtolower((string) ($doc['body'] ?? ''));
            $haystack = $summary."\n".$body;
            if ($this->mentionsNonRuntimeMarker($haystack)) {
                continue;
            }
            $warnings[] = [
                'rule' => 'future_planned_not_runtime_unclear',
                'path' => $path,
                'message' => "{$path}: status [{$status}] doc has no implementation_state/blocker and summary/body does not declare it is not current runtime",
            ];
        }

        return $warnings;
    }

    /**
     * Rule W3 — docs marked deprecated (the canonical schema's mapping
     * for superseded/historical) must point at the sucessor so reviewers
     * can follow the chain. Tolerated when: (a) superseded_by frontmatter
     * field is non-empty; (b) summary contains "supersed"|"substitu"|
     * "replaced"|"sucesso".
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function deprecatedSuccessorWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if ($this->shouldSkipPath($path)) {
                continue;
            }
            if ((string) $doc['status'] !== 'deprecated') {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            $supersededBy = $frontmatter['superseded_by'] ?? null;
            if ($supersededBy !== null && $supersededBy !== '' && $supersededBy !== []) {
                continue;
            }
            $summary = strtolower((string) ($frontmatter['summary'] ?? ''));
            if (preg_match('/(supersed|substitu|replaced by|sucesso|sucessora|sucessor)/i', $summary) === 1) {
                continue;
            }
            $warnings[] = [
                'rule' => 'deprecated_without_successor',
                'path' => $path,
                'message' => "{$path}: deprecated doc has no superseded_by field and summary does not name a sucessor",
            ];
        }

        return $warnings;
    }

    /**
     * Rule W4 — docs that cite a canonical schema (atlas.<...>.vN) in
     * their body imply that schema exists and is implemented. Warn when:
     * (a) status is active/building but evidence or repo_paths is empty;
     * (b) status is something other than active/building/planned/future
     *     and there is no implementation_state/blocker declaration —
     *     i.e., the doc claims a schema is implemented without proof and
     *     without being explicitly marked as in-progress.
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function schemaCitationWarnings(array $docs): array
    {
        $warnings = [];
        $schemaPattern = '/\\batlas\\.[a-z0-9_]+(?:\\.[a-z0-9_]+)*\\.v\\d+\\b/i';
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if ($this->shouldSkipPath($path)) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== self::CANONICAL_MODULE_SCHEMA) {
                continue;
            }
            $body = (string) ($doc['body'] ?? '');
            if (preg_match($schemaPattern, $body) !== 1) {
                continue;
            }
            $status = (string) $doc['status'];
            $evidence = (array) ($frontmatter['evidence'] ?? []);
            $repoPaths = (array) ($frontmatter['repo_paths'] ?? []);
            $implementationState = trim((string) ($frontmatter['implementation_state'] ?? ''));
            $blocker = trim((string) ($frontmatter['blocker'] ?? ''));

            if (in_array($status, ['active', 'building'], true)) {
                if ($evidence === [] || $repoPaths === []) {
                    $warnings[] = [
                        'rule' => 'schema_cited_without_evidence',
                        'path' => $path,
                        'message' => "{$path}: body cites canonical schema (atlas.*.vN) and status [{$status}] but evidence or repo_paths is empty",
                    ];
                }

                continue;
            }
            if (in_array($status, ['planned', 'future'], true)) {
                continue;
            }
            if ($implementationState !== '' || $blocker !== '') {
                continue;
            }
            $warnings[] = [
                'rule' => 'schema_cited_without_runtime_declaration',
                'path' => $path,
                'message' => "{$path}: body cites canonical schema (atlas.*.vN) but status [{$status}] is outside [active|building|planned|future] and has no implementation_state/blocker field",
            ];
        }

        return $warnings;
    }

    /**
     * Rule W5/W6 — docs that mention Atlas Dev/Forge/Code ambiguous
     * cluster terms (Atlas Forge, Atlas Code Forge, Obra Command Center,
     * Dual Core, ForgeRivals, etc.) must either link to the canonical
     * glossary doc (related_paths/depends_on/flows_to/body link) or be
     * the glossary doc itself. Two or more cluster terms in the title
     * always warn unless the glossary is referenced.
     *
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    private function ambiguousNamingWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if ($this->shouldSkipPath($path)) {
                continue;
            }
            if ($path === self::CANONICAL_GLOSSARY_PATH) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== self::CANONICAL_MODULE_SCHEMA) {
                continue;
            }
            $title = (string) ($frontmatter['title'] ?? '');
            $body = (string) ($doc['body'] ?? '');
            $matches = $this->ambiguousTermsFound($title.' '.$body);
            if ($matches === []) {
                continue;
            }
            if ($this->referencesGlossary($frontmatter, $body)) {
                continue;
            }
            $count = count($matches);
            $rule = $count >= 2 || $this->ambiguousTermsFound($title) !== []
                ? 'ambiguous_naming_in_title'
                : 'missing_glossary_reference';
            $sample = implode(', ', array_slice($matches, 0, 3));
            $warnings[] = [
                'rule' => $rule,
                'path' => $path,
                'message' => "{$path}: mentions ambiguous cluster terms [{$sample}] without referencing {$this->relativeGlossaryPath()}",
            ];
        }

        return $warnings;
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

    private function mentionsNonRuntimeMarker(string $haystack): bool
    {
        $markers = [
            'nao construido',
            'não construido',
            'nao construída',
            'não construída',
            'not implemented',
            'not yet implemented',
            'visao futura',
            'visão futura',
            'tese estrategica',
            'tese estratégica',
            'future state',
            'future vision',
            'planned but not',
            'not current runtime',
            'no codigo equivalente',
            'no código equivalente',
        ];
        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function ambiguousTermsFound(string $haystack): array
    {
        $found = [];
        foreach (self::AMBIGUOUS_NAMING_TERMS as $term) {
            if (stripos($haystack, $term) !== false) {
                $found[] = $term;
            }
        }

        return $found;
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
