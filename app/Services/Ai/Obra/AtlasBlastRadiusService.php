<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use Throwable;

/**
 * WO-17-T3 — deterministic blast radius (P2 without the fantasy).
 *
 * Given the files a slice changes, answer "what else must I check": the affected call
 * graph (files that REFERENCE the changed files' symbols), the tests that cover them,
 * and the governed decisions the change touches. No LLM, no world-model dependency
 * (the local edges table isn't populated) — it reads the symbols the changed files
 * DEFINE and finds their references by symbol name, the deterministic first-order call
 * graph. Query-aware decision recall (T0.2) supplies the "decisões tocadas" arm.
 *
 * Provider-safe + fail-open: a missing tool / index degrades to a smaller-but-honest
 * radius, never a throw. `ripgrep` when present (fast), `grep -r` fallback.
 */
final class AtlasBlastRadiusService
{
    public const SCHEMA = 'atlas.obra.blast_radius.v1';

    public function __construct(
        private readonly AtlasHybridMemoryRetrievalService $memory,
    ) {}

    /**
     * @param  list<string>  $changedFiles  repo-relative paths
     * @return array<string,mixed> {schema, changed, affected, tests, decisions, counts}
     */
    public function radiusFor(array $changedFiles, ?string $repoPath = null): array
    {
        $repoPath = rtrim($repoPath ?? base_path(), '/');
        $changed = array_values(array_unique(array_filter(array_map(
            static fn ($f): string => trim((string) $f),
            $changedFiles,
        ), static fn (string $f): bool => $f !== '')));

        $symbols = [];
        foreach ($changed as $file) {
            $sym = $this->classNameOf($file);
            if ($sym !== null) {
                $symbols[$sym] = true;
            }
        }

        $referencing = $this->referencingFiles(array_keys($symbols), $repoPath, $changed);
        $tests = array_values(array_filter($referencing, static fn (string $f): bool => str_contains($f, 'tests/') || str_ends_with($f, 'Test.php')));
        $affected = array_values(array_filter($referencing, static fn (string $f): bool => ! (str_contains($f, 'tests/') || str_ends_with($f, 'Test.php'))));
        $decisions = $this->decisionsTouched($changed, array_keys($symbols));

        return [
            'schema' => self::SCHEMA,
            'changed' => $changed,
            'symbols' => array_keys($symbols),
            'affected' => $affected,
            'tests' => $tests,
            'decisions' => $decisions,
            'counts' => [
                'changed' => count($changed),
                'affected' => count($affected),
                'tests' => count($tests),
                'decisions' => count($decisions),
            ],
        ];
    }

    /** PSR-4: a PHP file's class name is its basename sans extension. */
    private function classNameOf(string $file): ?string
    {
        if (! str_ends_with($file, '.php')) {
            return null;
        }
        $base = preg_replace('/\.php$/', '', basename($file)) ?? '';

        return $base !== '' ? $base : null;
    }

    /**
     * Files that reference any of $symbols (the first-order call graph), minus the
     * changed files themselves. Deterministic textual reference over app/ + tests/.
     *
     * @param  list<string>  $symbols
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function referencingFiles(array $symbols, string $repoPath, array $exclude): array
    {
        $symbols = array_values(array_filter($symbols, static fn (string $s): bool => strlen($s) >= 3));
        if ($symbols === []) {
            return [];
        }

        try {
            $pattern = '\b('.implode('|', array_map('preg_quote', $symbols)).')\b';
            $rg = trim((string) @shell_exec('command -v rg 2>/dev/null'));
            if ($rg !== '') {
                $cmd = 'rg -l --no-messages -e '.escapeshellarg($pattern).' '
                    .escapeshellarg($repoPath.'/app').' '.escapeshellarg($repoPath.'/tests').' 2>/dev/null';
            } else {
                $cmd = 'grep -rlE '.escapeshellarg($pattern).' '
                    .escapeshellarg($repoPath.'/app').' '.escapeshellarg($repoPath.'/tests').' 2>/dev/null';
            }
            $out = (string) @shell_exec($cmd);
            $base = $repoPath.'/';
            $files = [];
            foreach (preg_split('/\R/', $out) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $rel = str_starts_with($line, $base) ? substr($line, strlen($base)) : $line;
                if (! in_array($rel, $exclude, true) && str_ends_with($rel, '.php')) {
                    $files[$rel] = true;
                }
            }
            ksort($files);

            return array_keys($files);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Governed decisions the change touches — query-aware recall (T0.2) over the file
     * names + symbols, decision-type only.
     *
     * @param  list<string>  $changed
     * @param  list<string>  $symbols
     * @return list<string>
     */
    private function decisionsTouched(array $changed, array $symbols): array
    {
        $query = trim(implode(' ', array_merge(
            array_map(static fn (string $f): string => basename($f), $changed),
            $symbols,
        )));
        if ($query === '') {
            return [];
        }
        try {
            $recall = $this->memory->recall(
                $query,
                [],
                ['memory_type' => 'decision'],
                ['limit' => 5, 'requester' => 'blast_radius', 'include_verbatim' => false, 'include_semantic' => false, 'include_compounding' => false],
            );

            return array_values(array_filter(array_map(
                static fn ($row): string => trim((string) (is_array($row) ? ($row['title'] ?? '') : '')),
                (array) ($recall['recall'] ?? []),
            ), static fn (string $t): bool => $t !== ''));
        } catch (Throwable) {
            return [];
        }
    }
}
