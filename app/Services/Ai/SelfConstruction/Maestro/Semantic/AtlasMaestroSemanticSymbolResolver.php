<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Semantic;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * MAESTRO SEMANTIC SYMBOL RESOLVER — the v+3 foundation: it replaces the structural ASSUMPTION (today's
 * inspector believes a cited symbol IS where the packet claims) with a CHECKED FACT. Given a class / method /
 * function name cited in a packet objective or acceptance_criteria, it resolves the symbol to a concrete
 * file:line by deterministic path+regex resolution over the repo (no LLM).
 *
 * Returns {symbol, file, line, kind, exists:true} when found; on a miss it returns exists:false with a non-empty
 * fuzzy candidate list and NEVER invents a file path.
 */
final class AtlasMaestroSemanticSymbolResolver
{
    /** @var array<string,string>|null basename(no .php) => repo-relative path */
    private ?array $index = null;

    public function __construct(private readonly ?string $repoRoot = null)
    {
    }

    /**
     * @return array{symbol:string, file?:string, line?:int, kind?:string, exists:bool, candidates?:list<string>}
     */
    public function resolve(string $fqSymbol): array
    {
        $symbol = trim($fqSymbol);
        $repoRoot = rtrim($this->repoRoot ?? base_path(), '/');

        $member = null;
        $kind = 'class';
        if (str_contains($symbol, '::')) {
            [$class, $member] = explode('::', $symbol, 2);
            $kind = 'method';
        } else {
            $class = $symbol;
        }
        $class = trim($class, '\\');
        $member = $member !== null ? trim((string) $member, '()') : null;
        $short = $this->shortName($class);

        $relFile = $this->locate($class, $short, $repoRoot);

        // Fall back to a free function search when no class file resolves.
        if ($relFile === null && ! str_contains($symbol, '::')) {
            $fn = $this->locateFunction($short, $repoRoot);
            if ($fn !== null) {
                return ['symbol' => $symbol, 'file' => $fn['file'], 'line' => $fn['line'], 'kind' => 'function', 'exists' => true];
            }
        }

        if ($relFile === null) {
            return ['symbol' => $symbol, 'exists' => false, 'candidates' => $this->candidates($short, $repoRoot)];
        }

        $absFile = $repoRoot.'/'.$relFile;
        if ($kind === 'method' && $member !== null && $member !== '') {
            $line = $this->memberLine($absFile, $member);
            if ($line > 0) {
                return ['symbol' => $symbol, 'file' => $relFile, 'line' => $line, 'kind' => 'method', 'exists' => true];
            }
            // method not found in the resolved class ⇒ honest miss, not a fabricated line.
            return ['symbol' => $symbol, 'exists' => false, 'candidates' => $this->candidates($short, $repoRoot)];
        }

        $line = $this->declarationLine($absFile, $short);
        if ($line <= 0) {
            return ['symbol' => $symbol, 'exists' => false, 'candidates' => $this->candidates($short, $repoRoot)];
        }

        return ['symbol' => $symbol, 'file' => $relFile, 'line' => $line, 'kind' => 'class', 'exists' => true];
    }

    private function locate(string $class, string $short, string $repoRoot): ?string
    {
        // PSR-4 fast path for App\ / Tests\.
        foreach (['App\\' => 'app/', 'Tests\\' => 'tests/'] as $prefix => $dir) {
            if (str_starts_with($class.'\\', $prefix) && str_contains($class, '\\')) {
                $rel = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($repoRoot.'/'.$rel) && $this->declarationLine($repoRoot.'/'.$rel, $short) > 0) {
                    return $rel;
                }
            }
        }

        // Short-name / non-PSR-4: basename index over app/ + tests/.
        $rel = $this->fileIndex($repoRoot)[$short] ?? null;
        if ($rel !== null && $this->declarationLine($repoRoot.'/'.$rel, $short) > 0) {
            return $rel;
        }

        return null;
    }

    /**
     * @return array{file:string, line:int}|null
     */
    private function locateFunction(string $name, string $repoRoot): ?array
    {
        foreach ($this->fileIndex($repoRoot) as $rel) {
            $line = $this->memberLine($repoRoot.'/'.$rel, $name);
            if ($line > 0) {
                return ['file' => $rel, 'line' => $line];
            }
        }

        return null;
    }

    private function declarationLine(string $absFile, string $short): int
    {
        if (! is_file($absFile)) {
            return 0;
        }
        $pattern = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+'.preg_quote($short, '/').'\b/';

        return $this->firstMatchingLine($absFile, $pattern);
    }

    private function memberLine(string $absFile, string $member): int
    {
        if (! is_file($absFile)) {
            return 0;
        }

        return $this->firstMatchingLine($absFile, '/\bfunction\s+'.preg_quote($member, '/').'\s*\(/');
    }

    private function firstMatchingLine(string $absFile, string $pattern): int
    {
        $lines = @file($absFile, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private function candidates(string $short, string $repoRoot): array
    {
        $names = array_keys($this->fileIndex($repoRoot));
        $scored = [];
        foreach ($names as $name) {
            $percent = 0.0;
            similar_text(strtolower($short), strtolower($name), $percent);
            $scored[] = ['name' => $name, 'score' => $percent];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return array_slice(array_map(static fn (array $s): string => $s['name'], $scored), 0, 5);
    }

    /**
     * @return array<string,string>  basename(no .php) => first repo-relative path (sorted, deterministic)
     */
    private function fileIndex(string $repoRoot): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        foreach (['app', 'tests'] as $dir) {
            $base = $repoRoot.'/'.$dir;
            if (! is_dir($base)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if (! $file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }
                    $name = $file->getBasename('.php');
                    $rel = ltrim(str_replace($repoRoot, '', $file->getPathname()), '/');
                    if (! isset($index[$name]) || strcmp($rel, $index[$name]) < 0) {
                        $index[$name] = $rel; // deterministic: lexicographically-first path wins
                    }
                }
            } catch (Throwable) {
                // best-effort scan
            }
        }

        return $this->index = $index;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));

        return (string) end($parts);
    }
}
