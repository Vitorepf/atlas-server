<?php

declare(strict_types=1);

namespace App\Services\Vault;

/**
 * Reads the canonical engineering knowledge base from the repo.
 *
 * Source of truth for technical/architectural Atlas content (Kernel, Forge,
 * Runtime, Governance, Evidence). Read-only — never writes a single byte.
 */
final class RepoVaultReader
{
    public function __construct(
        private readonly FrontmatterParser $parser
    ) {
    }

    /**
     * Walk the repo docs directory and build an index keyed by frontmatter
     * `graph_id` when available, falling back to `id` for legacy docs.
     *
     * @return array<string, array{path: string, relative_path: string, frontmatter: array<string, mixed>, mtime: int, exists: true}>
     */
    public function index(?string $rootOverride = null): array
    {
        $root = $rootOverride ?? (string) config('atlas_vault.repo_docs_path');
        if (! is_dir($root)) {
            return [];
        }

        $index = [];
        foreach ($this->walkMd($root) as $absolutePath) {
            $content = @file_get_contents($absolutePath);
            if ($content === false) {
                continue;
            }
            $parsed = $this->parser->parse($content);
            $id = $parsed['frontmatter']['graph_id'] ?? $parsed['frontmatter']['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $entry = [
                'path' => $absolutePath,
                'relative_path' => $this->relativeToBase($absolutePath),
                'frontmatter' => $parsed['frontmatter'],
                'mtime' => @filemtime($absolutePath) ?: 0,
                'exists' => true,
            ];

            // Some historical archive/source-material files intentionally keep
            // the same graph_id as the promoted operational document. The
            // cartography must navigate the promoted doc, not an archived
            // snapshot that happens to be walked first by the filesystem.
            if (isset($index[$id]) && ! $this->prefersEntry($entry, $index[$id], $id)) {
                continue;
            }

            $index[$id] = $entry;
        }

        return $index;
    }

    /**
     * Read a single .md by its graph_id (== frontmatter `id`). Returns null if missing.
     *
     * @return array{frontmatter: array<string, mixed>, body: string, path: string, relative_path: string, mtime: int}|null
     */
    public function read(string $graphId): ?array
    {
        $index = $this->index();
        if (! isset($index[$graphId])) {
            return null;
        }
        $entry = $index[$graphId];
        $content = @file_get_contents($entry['path']);
        if ($content === false) {
            return null;
        }
        $parsed = $this->parser->parse($content);

        return [
            'frontmatter' => $parsed['frontmatter'],
            'body' => $parsed['body'],
            'path' => $entry['path'],
            'relative_path' => $entry['relative_path'],
            'mtime' => $entry['mtime'],
        ];
    }

    /** @return \Generator<int, string> */
    private function walkMd(string $dir): \Generator
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($rii as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                yield $file->getPathname();
            }
        }
    }

    private function relativeToBase(string $absolutePath): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $base)) {
            return substr($absolutePath, strlen($base));
        }

        return $absolutePath;
    }

    /**
     * @param  array{path: string, relative_path: string, frontmatter: array<string, mixed>, mtime: int, exists: true}  $candidate
     * @param  array{path: string, relative_path: string, frontmatter: array<string, mixed>, mtime: int, exists: true}  $existing
     */
    private function prefersEntry(array $candidate, array $existing, string $id): bool
    {
        return $this->entryAuthorityScore($candidate, $id) > $this->entryAuthorityScore($existing, $id);
    }

    /**
     * @param  array{path: string, relative_path: string, frontmatter: array<string, mixed>, mtime: int, exists: true}  $entry
     */
    private function entryAuthorityScore(array $entry, string $id): int
    {
        $score = 0;
        $path = str_replace('\\', '/', $entry['relative_path']);
        $frontmatter = $entry['frontmatter'];

        if (! str_contains($path, '/archive/') && ! str_contains($path, '/archive/source-material/')) {
            $score += 1000;
        }

        if (($frontmatter['doc_schema'] ?? null) === 'atlas_canonical_module_doc.v1') {
            $score += 100;
        }

        if (($frontmatter['status'] ?? null) === 'active' || ($frontmatter['graph_status'] ?? null) === 'active') {
            $score += 25;
        }

        $parent = $frontmatter['graph_parent'] ?? null;
        if (is_string($parent) && $parent !== '' && $parent !== $id) {
            $score += 10;
        }

        // Prefer the promoted top-level file over deeper copies when all
        // semantic authority signals tie.
        $score -= substr_count($path, '/');

        return $score;
    }
}
