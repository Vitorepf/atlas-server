<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\RepoWide;

final class AtlasLoopRepoWideCallerResolver
{
    /**
     * @param  array<string,string>  $fileContentsByPath
     * @return list<string>
     */
    public function callersOf(string $fqcn, array $fileContentsByPath): array
    {
        $fqcn = trim(ltrim($fqcn, '\\'));
        if ($fqcn === '') {
            return [];
        }

        $shortName = $this->shortName($fqcn);
        $namespace = $this->namespaceOf($fqcn);
        $paths = [];

        foreach ($fileContentsByPath as $path => $content) {
            if (! is_string($content) || $content === '') {
                continue;
            }

            if (! $this->hasFqcnAnchor($content, $fqcn, $namespace)) {
                continue;
            }

            if (! $this->hasShortNameUsage($content, $fqcn, $shortName)) {
                continue;
            }

            $paths[(string) $path] = true;
        }

        $out = array_keys($paths);
        sort($out, SORT_STRING);

        return $out;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return (string) end($parts);
    }

    private function namespaceOf(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }

    private function hasFqcnAnchor(string $content, string $fqcn, string $namespace): bool
    {
        if (preg_match($this->usePattern($fqcn), $content) === 1) {
            return true;
        }

        if ($namespace !== '' && preg_match($this->namespacePattern($namespace), $content) === 1) {
            return true;
        }

        return preg_match($this->fqcnTokenPattern($fqcn), $this->withoutUseImports($content)) === 1;
    }

    private function hasShortNameUsage(string $content, string $fqcn, string $shortName): bool
    {
        $body = $this->withoutUseImports($content);

        return preg_match($this->fqcnTokenPattern($fqcn), $body) === 1
            || preg_match('/(?<![A-Za-z0-9_])'.preg_quote($shortName, '/').'(?![A-Za-z0-9_])/', $body) === 1;
    }

    private function withoutUseImports(string $content): string
    {
        return (string) preg_replace('/^\s*use\s+[^;]+;\s*$/m', '', $content);
    }

    private function usePattern(string $fqcn): string
    {
        return '/^\s*use\s+\\\\?'.preg_quote($fqcn, '/').'(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?\s*;/m';
    }

    private function namespacePattern(string $namespace): string
    {
        return '/^\s*namespace\s+\\\\?'.preg_quote($namespace, '/').'\s*(?:;|\{)/m';
    }

    private function fqcnTokenPattern(string $fqcn): string
    {
        return '/(?<![A-Za-z0-9_\\\\])\\\\?'.preg_quote($fqcn, '/').'(?![A-Za-z0-9_\\\\])/';
    }
}
