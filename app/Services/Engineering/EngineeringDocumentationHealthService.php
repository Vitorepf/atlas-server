<?php

namespace App\Services\Engineering;

use App\Services\Semantic\FrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class EngineeringDocumentationHealthService
{
    /**
     * @var array<string,int|null>
     */
    private const REQUIRED_DOCS = [
        'docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md' => 180,
        'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md' => 260,
        'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md' => 260,
        'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md' => 260,
        'docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md' => 260,
        'docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md' => 180,
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
        $violations = array_values(array_merge(
            $required['missing'],
            $this->frontmatterViolations($docs),
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
                'frontmatter_violation_count' => count($this->frontmatterViolations($docs)),
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
