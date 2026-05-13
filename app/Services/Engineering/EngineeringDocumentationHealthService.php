<?php

namespace App\Services\Engineering;

use App\Services\Semantic\FrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class EngineeringDocumentationHealthService
{
    private const CANONICAL_MODULE_SCHEMA = 'atlas_canonical_module_doc.v1';

    /**
     * @var array<string,int|null>
     */
    private const REQUIRED_DOCS = [
        'docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md' => 520,
        'docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md' => 520,
        'docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md' => 520,
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
     * @var array<string,string>
     */
    private const GRANDFATHERED_SPLIT_REQUIRED = [
        'docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md' => 'split Kernel APs into focused contract specs before adding new sections',
        'docs/engineering-knowledge-base/atlas-ai-master-architecture.md' => 'extract domain playbooks and keep this as Layer 2 reference',
        'docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md' => 'split roadmap into AP and phase specs for execution',
        'docs/engineering-knowledge-base/START_HERE.md' => 'keep as full reading order; use session bootstrap for new-session context',
    ];

    public function __construct(private readonly FrontmatterParser $frontmatter) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $docs = $this->scanDocs();
        $required = $this->requiredDocsReport($docs);
        $frontmatterViolations = $this->frontmatterViolations($docs);
        $canonicalCoverageViolations = $this->canonicalModuleCoverageViolations($docs);
        $canonicalViolations = $this->canonicalModuleViolations($docs);
        $violations = array_values(array_merge(
            $required['missing'],
            $frontmatterViolations,
            $canonicalCoverageViolations,
            $canonicalViolations,
        ));
        $oversized = $this->oversizedDocs($docs);

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
            ],
            'required_docs' => $required['items'],
            'oversized_docs' => $oversized,
            'violations' => $violations,
            'generated_at' => now()->toJSON(),
        ];
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
            if (str_contains($path, '/archive/') || in_array($status, ['archived', 'source_material'], true)) {
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
    private function canonicalModuleCoverageViolations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $status = (string) $doc['status'];
            if (str_contains($path, '/archive/') || str_contains($path, '/templates/') || in_array($status, ['archived', 'source_material'], true)) {
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
            if (str_contains($path, '/templates/')) {
                continue;
            }

            foreach (self::CANONICAL_MODULE_REQUIRED_FRONTMATTER as $field) {
                if (! array_key_exists($field, $frontmatter) || $frontmatter[$field] === [] || $frontmatter[$field] === '') {
                    $violations[] = "{$path}: missing canonical module field [{$field}]";
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
