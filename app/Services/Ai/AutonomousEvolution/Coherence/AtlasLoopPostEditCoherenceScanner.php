<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Coherence;

use Symfony\Component\Process\Process;

final class AtlasLoopPostEditCoherenceScanner
{
    /**
     * @param  list<string>  $files
     * @return list<array<string,mixed>>
     */
    public function scan(array $files): array
    {
        $paths = array_values(array_unique(array_filter($files, static fn (mixed $path): bool => is_string($path) && $path !== '')));
        sort($paths, SORT_STRING);

        $symbols = $this->symbolsByFile($paths);
        $classMap = $this->classMap($symbols);
        $findings = [];

        foreach ($paths as $path) {
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            if ($contents === '') {
                continue;
            }

            $findings = array_merge($findings, $this->parseErrors($path));
            $findings = array_merge($findings, $this->unresolvedUses($path, $contents, $classMap));
            $findings = array_merge($findings, $this->danglingMethodReferences($path, $contents, $classMap));
        }

        usort($findings, static function (array $left, array $right): int {
            $byFile = strcmp((string) $left['file'], (string) $right['file']);
            if ($byFile !== 0) {
                return $byFile;
            }

            $byLine = ((int) $left['line']) <=> ((int) $right['line']);
            if ($byLine !== 0) {
                return $byLine;
            }

            return strcmp((string) $left['reason'], (string) $right['reason']);
        });

        return $findings;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string,array{class:string,methods:list<string>}>
     */
    private function symbolsByFile(array $paths): array
    {
        $symbols = [];
        foreach ($paths as $path) {
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            if ($contents === '') {
                continue;
            }

            $class = $this->declaredClass($contents);
            if ($class === null) {
                continue;
            }

            $symbols[$path] = [
                'class' => $class,
                'methods' => $this->publicMethods($contents),
            ];
        }

        ksort($symbols, SORT_STRING);

        return $symbols;
    }

    /**
     * @param  array<string,array{class:string,methods:list<string>}>  $symbols
     * @return array<string,array{file:string,methods:list<string>}>
     */
    private function classMap(array $symbols): array
    {
        $map = [];
        foreach ($symbols as $path => $symbol) {
            $map[$symbol['class']] = [
                'file' => $path,
                'methods' => $symbol['methods'],
            ];
        }

        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function parseErrors(string $path): array
    {
        $process = new Process(['/opt/homebrew/bin/php', '-l', $path]);
        $process->run();

        if ($process->isSuccessful()) {
            return [];
        }

        $stderr = trim($process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput());

        return [[
            'file' => $path,
            'line' => $this->lineFromPhpLintError($stderr),
            'reason' => 'parse_error',
            'target_symbol' => null,
            'stderr' => $stderr,
        ]];
    }

    /**
     * @param  array<string,array{file:string,methods:list<string>}>  $classMap
     * @return list<array<string,mixed>>
     */
    private function unresolvedUses(string $path, string $contents, array $classMap): array
    {
        preg_match_all('/^use\s+([^;]+);/mi', $contents, $matches, PREG_OFFSET_CAPTURE);
        $findings = [];

        foreach ($matches[1] ?? [] as [$fqcn, $offset]) {
            $trimmed = trim((string) $fqcn);
            if ($trimmed === '' || isset($classMap[$trimmed]) || class_exists($trimmed) || interface_exists($trimmed) || trait_exists($trimmed)) {
                continue;
            }

            $findings[] = [
                'file' => $path,
                'line' => $this->lineAtOffset($contents, (int) $offset),
                'reason' => 'unresolved_use',
                'target_symbol' => $trimmed,
            ];
        }

        return $findings;
    }

    /**
     * @param  array<string,array{file:string,methods:list<string>}>  $classMap
     * @return list<array<string,mixed>>
     */
    private function danglingMethodReferences(string $path, string $contents, array $classMap): array
    {
        preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE);
        $findings = [];

        foreach ($matches[0] ?? [] as $index => [$full, $offset]) {
            $class = (string) ($matches[1][$index][0] ?? '');
            $method = (string) ($matches[2][$index][0] ?? '');
            if ($class === '' || $method === '') {
                continue;
            }

            $target = $classMap[$class] ?? null;
            if ($target === null || in_array($method, $target['methods'], true)) {
                continue;
            }

            $findings[] = [
                'file' => $path,
                'line' => $this->lineAtOffset($contents, (int) $offset),
                'reason' => 'dangling_method_reference',
                'target_symbol' => $class.'::'.$method,
            ];
        }

        return $findings;
    }

    private function declaredClass(string $contents): ?string
    {
        if (! preg_match('/\bclass\s+([A-Z][A-Za-z0-9_]*)\b/', $contents, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * @return list<string>
     */
    private function publicMethods(string $contents): array
    {
        preg_match_all('/public\s+function\s+([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $contents, $matches);

        $methods = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        sort($methods, SORT_STRING);

        return $methods;
    }

    private function lineAtOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    private function lineFromPhpLintError(string $stderr): int
    {
        if (preg_match('/ on line (\d+)/', $stderr, $match)) {
            return (int) $match[1];
        }

        return 1;
    }
}
