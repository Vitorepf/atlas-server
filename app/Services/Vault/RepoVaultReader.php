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
     * Walk the repo docs directory and build an index keyed by frontmatter `id`
     * (which doubles as `graph_id` in the cartography).
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
            $id = $parsed['frontmatter']['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            // First seen wins; later duplicates are tracked via aliases on the entry.
            if (isset($index[$id])) {
                continue;
            }
            $index[$id] = [
                'path' => $absolutePath,
                'relative_path' => $this->relativeToBase($absolutePath),
                'frontmatter' => $parsed['frontmatter'],
                'mtime' => @filemtime($absolutePath) ?: 0,
                'exists' => true,
            ];
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
}
