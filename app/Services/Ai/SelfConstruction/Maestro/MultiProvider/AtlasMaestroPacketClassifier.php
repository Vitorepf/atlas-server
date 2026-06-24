<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\MultiProvider;

final class AtlasMaestroPacketClassifier
{
    public const ARCHITECTURE = 'architecture';
    public const MULTI_FILE = 'multi-file';
    public const GRIND = 'grind';
    public const DOC = 'doc';

    public const REASON_DOC = 'doc-paths-only';
    public const REASON_MULTI_FILE = '3plus-paths-2plus-subtrees';
    public const REASON_ARCHITECTURE = 'architecture-keyword-with-small-surface';
    public const REASON_GRIND = 'grind-default';

    public function classify(array $packet): string
    {
        return match ($this->reasonFor($packet)) {
            self::REASON_DOC => self::DOC,
            self::REASON_MULTI_FILE => self::MULTI_FILE,
            self::REASON_ARCHITECTURE => self::ARCHITECTURE,
            default => self::GRIND,
        };
    }

    public function reasonFor(array $packet): string
    {
        $allowedFiles = $this->paths($packet['allowed_files'] ?? []);
        $codePaths = $this->codePaths($allowedFiles);

        if ($allowedFiles !== [] && count($allowedFiles) === count(array_filter($allowedFiles, $this->isMaestroDocPath(...)))) {
            return self::REASON_DOC;
        }

        if (count($codePaths) >= 3 && count($this->subtrees($codePaths)) >= 2) {
            return self::REASON_MULTI_FILE;
        }

        if (count($codePaths) <= 2 && $this->containsArchitectureAnchor((string) ($packet['objective'] ?? ''))) {
            return self::REASON_ARCHITECTURE;
        }

        return self::REASON_GRIND;
    }

    /**
     * @return list<string>
     */
    private function paths(mixed $paths): array
    {
        $normalized = [];
        foreach ((array) $paths as $path) {
            $path = ltrim(trim((string) $path), '/');
            if ($path !== '') {
                $normalized[$path] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function codePaths(array $paths): array
    {
        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => str_starts_with($path, 'app/') || str_starts_with($path, 'config/'),
        ));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function subtrees(array $paths): array
    {
        $subtrees = [];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $subtree = $parts[0] === 'config'
                ? 'config'
                : implode('/', array_slice($parts, 0, min(2, count($parts))));
            $subtrees[$subtree] = true;
        }

        return array_keys($subtrees);
    }

    private function isMaestroDocPath(string $path): bool
    {
        return preg_match('#^docs/(loop|cortex|maestro)-[^/]+\.md$#', $path) === 1;
    }

    private function containsArchitectureAnchor(string $objective): bool
    {
        return preg_match('/\b(design|architecture|architectural|contract|contracts)\b/i', $objective) === 1;
    }
}
