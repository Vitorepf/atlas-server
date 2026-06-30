<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Semantic;

final class AtlasMaestroAllowedFilesIntentChecker
{
    /** @var callable|null */
    private $pathExistsCallback;

    public function __construct(
        private readonly ?AtlasMaestroSemanticSymbolResolver $resolver = null,
        ?callable $pathExistsCallback = null,
    ) {
        $this->pathExistsCallback = $pathExistsCallback;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function check(array $packet): array
    {
        $allowedFiles = $this->allowedFiles($packet);
        $matched = [];

        foreach ($this->citedTargets((string) ($packet['objective'] ?? '')) as $target) {
            $resolved = str_contains($target, '/')
                ? $this->resolvePath($target)
                : $this->resolver()->resolve($target);

            if (! (bool) ($resolved['exists'] ?? false)) {
                continue;
            }

            $symbol = (string) ($resolved['symbol'] ?? $target);
            $file = (string) ($resolved['file'] ?? '');
            $matched[] = ['symbol' => $symbol, 'defining_file' => $file];

            if (! $this->isAllowed($file, $allowedFiles)) {
                return [
                    'ok' => false,
                    'reason' => 'symbol_outside_allowed_files',
                    'symbol' => $symbol,
                    'defining_file' => $file,
                    'matched_symbols' => $matched,
                ];
            }
        }

        return [
            'ok' => true,
            'matched_symbols' => $matched,
            'warnings' => $this->companionWarnings($allowedFiles),
        ];
    }

    /** @param list<string> $allowedFiles */
    private function companionWarnings(array $allowedFiles): array
    {
        $testBasenames = [];
        foreach ($allowedFiles as $f) {
            if (str_starts_with($f, 'tests/')) {
                $testBasenames[] = basename($f);
            }
        }

        $warnings = [];
        foreach ($allowedFiles as $f) {
            if (! str_starts_with($f, 'app/')) {
                continue;
            }
            $expected = basename($f, '.php').'Test.php';
            if (! in_array($expected, $testBasenames, true)) {
                $warnings[] = ['kind' => 'missing_test_companion', 'impl_file' => $f, 'expected_test_basename' => $expected];
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function citedTargets(string $objective): array
    {
        $targets = [];
        if (preg_match_all('/\b(?:[A-Z][A-Za-z0-9_]*\\\\)*Atlas[A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)?\b/', $objective, $matches) > 0) {
            foreach ($matches[0] as $symbol) {
                $targets[$symbol] = true;
            }
        }
        if (preg_match_all('#\b(?:app|tests|config)/[A-Za-z0-9_./-]+\.(?:php|md)\b#', $objective, $matches) > 0) {
            foreach ($matches[0] as $path) {
                $targets[ltrim($path, '/')] = true;
            }
        }

        return array_keys($targets);
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return list<string>
     */
    private function allowedFiles(array $packet): array
    {
        $allowed = [];
        foreach ((array) ($packet['allowed_files'] ?? []) as $path) {
            $path = ltrim(trim((string) $path), '/');
            if ($path !== '') {
                $allowed[$path] = true;
            }
        }

        return array_keys($allowed);
    }

    /**
     * @return array{symbol:string,file?:string,exists:bool}
     */
    private function resolvePath(string $path): array
    {
        $path = ltrim($path, '/');
        $cb = $this->pathExistsCallback;
        $exists = $cb !== null ? (bool) $cb($path) : is_file(base_path($path));

        return ['symbol' => $path, 'file' => $path, 'exists' => $exists];
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function isAllowed(string $definingFile, array $allowedFiles): bool
    {
        $definingFile = ltrim($definingFile, '/');
        foreach ($allowedFiles as $allowedFile) {
            $allowedFile = rtrim(ltrim($allowedFile, '/'), '/');
            if ($definingFile === $allowedFile || str_starts_with($definingFile, $allowedFile.'/')) {
                return true;
            }
        }

        return false;
    }

    private function resolver(): AtlasMaestroSemanticSymbolResolver
    {
        return $this->resolver ?? new AtlasMaestroSemanticSymbolResolver(base_path());
    }
}
