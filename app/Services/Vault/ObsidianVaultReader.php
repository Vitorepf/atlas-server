<?php

declare(strict_types=1);

namespace App\Services\Vault;

/**
 * Reads the AtlasVault from iCloud-synced Obsidian directory.
 *
 * Source of truth for human/reflective content (books, philosophy, marginalia,
 * stories, personal hypotheses). Read-only.
 */
final class ObsidianVaultReader
{
    public function __construct(
        private readonly FrontmatterParser $parser
    ) {
    }

    /**
     * @return array<string, array{path: string, relative_path: string, frontmatter: array<string, mixed>, mtime: int, exists: true}>
     */
    public function index(?string $rootOverride = null): array
    {
        $root = $rootOverride ?? (string) config('atlas_vault.obsidian_vault_path');
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
            $fm = $parsed['frontmatter'];

            // Vault notes can use multiple id schemes; cartography prefers `graph_id` then `atlas_id` then `id`.
            $id = $fm['graph_id'] ?? $fm['atlas_id'] ?? $fm['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            if (isset($index[$id])) {
                continue;
            }
            $index[$id] = [
                'path' => $absolutePath,
                'relative_path' => $this->relativeToVault($absolutePath, $root),
                'frontmatter' => $fm,
                'mtime' => @filemtime($absolutePath) ?: 0,
                'exists' => true,
            ];
        }

        return $index;
    }

    /**
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
            if (! $file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $name = $file->getFilename();
            // skip dotfiles + Obsidian internals
            if (str_starts_with($name, '.') || str_contains($file->getPathname(), '/.obsidian/')) {
                continue;
            }
            yield $file->getPathname();
        }
    }

    private function relativeToVault(string $absolutePath, string $root): string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $root)) {
            return 'AtlasVault/'.substr($absolutePath, strlen($root));
        }

        return $absolutePath;
    }
}
