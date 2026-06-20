<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * COMPREHENSION GROUNDING GATE — §11.5 of the loop pipeline (stage-1 UNDERSTAND).
 *
 * Before the loop touches anything, the frontier model states the TRUE OBJECTIVE of the
 * scope it is about to evolve. That stated objective is the seed for every downstream
 * stage (projection → orchestration → implement → test → wiring-review). If the model
 * HALLUCINATED the purpose — invented a consumer, a class, a feature that does not exist —
 * then every later stage is confidently misdirected and the whole cycle is wasted.
 *
 * This gate makes a hallucinated objective DETECTABLE at the seam where it is cheapest to
 * catch: the model is required to CITE the concrete symbols/consumers its objective rests
 * on, and this gate deterministically checks that each cited symbol actually EXISTS in the
 * repo. A citation that resolves nowhere is a tell that the comprehension is fabricated.
 *
 * Resolution is OR over three independent oracles, cheapest first, so a true symbol passes
 * even if one oracle is blind:
 *   1. class_exists($symbol)                         — autoload-visible classes/interfaces;
 *   2. a file under $repoRoot whose path or basename matches the symbol — covers symbols
 *      that are not autoloaded yet (FQN → expected PSR-4 path, or bare basename match);
 *   3. (best-effort) a row in atlas_engineering_code_symbols — the code index, for members
 *      and non-class symbols. This oracle is OPTIONAL: any DB error is swallowed.
 *
 * Contract — this gate FLAGS, it never BLOCKS:
 *   - grounded=true iff ALL cited symbols resolve, OR no symbols were cited;
 *   - FAIL-OPEN: an empty citation list, or unavailable check infra, yields grounded=true
 *     with a note explaining why (and a logged warning) — a missing oracle must never be
 *     able to halt the loop, only a positively-refuted citation lowers trust.
 *   - a hallucinated symbol (resolves on no oracle) lands in `ungrounded`.
 *
 * The gate is pure read: class_exists + a bounded file scan + an optional index lookup. It
 * mutates nothing and is deterministic for a fixed repo state.
 */
final class AtlasLoopComprehensionGroundingGate
{
    public const SCHEMA = 'atlas.loop.comprehension.grounding.v1';

    /**
     * The code-index table consulted as the third (best-effort) resolution oracle.
     */
    private const CODE_SYMBOL_TABLE = 'atlas_engineering_code_symbols';

    /**
     * Hard cap on files visited during the path/basename scan. A repo with hundreds of
     * thousands of files must not turn one grounding check into a multi-second walk; once
     * the budget is spent the scan stops and the remaining symbols fall through to the
     * (cheaper) index oracle. The cap is generous enough that legitimate citations under a
     * normal app tree resolve well within it.
     */
    private const MAX_FILES_SCANNED = 60000;

    /**
     * Directory names never worth scanning for a cited source symbol — third-party code,
     * VCS metadata, build/cache output. Skipping them keeps the walk both fast and free of
     * accidental matches against vendored class names.
     *
     * @var list<string>
     */
    private const SKIP_DIRECTORIES = [
        'vendor',
        'node_modules',
        '.git',
        'storage',
        'bootstrap/cache',
        '.idea',
        '.phpunit.cache',
    ];

    /**
     * Ground a stated true-objective against the symbols it cites.
     *
     * @param string                  $statedObjective the model's stage-1 true-objective claim (recorded in the note for provenance)
     * @param array<int|string,mixed> $citedSymbols    symbols/consumers the objective rests on (FQNs, class names, or basenames)
     * @param string                  $repoRoot        absolute path to the repository root the citations refer to
     *
     * @return array{
     *     schema:string,
     *     grounded:bool,
     *     resolved:list<string>,
     *     ungrounded:list<string>,
     *     citation_count:int,
     *     note:string
     * }
     */
    public function ground(string $statedObjective, array $citedSymbols, string $repoRoot): array
    {
        $symbols = $this->normalizeCitations($citedSymbols);
        $citationCount = count($symbols);

        // FAIL-OPEN #1 — nothing cited. A bare objective cannot be REFUTED here, so it is
        // not blocked; the caller is told it was un-evidenced via the note.
        if ($citationCount === 0) {
            $note = 'fail-open: no symbols cited — objective could not be grounded (not blocked). '
                . $this->objectiveTag($statedObjective);
            $this->logFailOpen($note);

            return $this->result(true, [], [], 0, $note);
        }

        $normalizedRoot = $this->normalizeRepoRoot($repoRoot);

        // FAIL-OPEN #2 — the check infra is unavailable (repoRoot is not a real directory),
        // so the file oracle cannot run. We still try class_exists + the index, but if a
        // symbol resolves on neither we MUST NOT refute it on the basis of a blind scan:
        // an unreadable root makes the whole check inconclusive → grounded=true, logged.
        $rootReadable = is_dir((string) $normalizedRoot);

        $resolved = [];
        $ungrounded = [];

        foreach ($symbols as $symbol) {
            if ($this->resolves($symbol, $rootReadable ? $normalizedRoot : null)) {
                $resolved[] = $symbol;
            } else {
                $ungrounded[] = $symbol;
            }
        }

        if (! $rootReadable && $ungrounded !== []) {
            $note = 'fail-open: repoRoot is not a readable directory ('
                . $this->shortPath($repoRoot)
                . ') — file oracle unavailable, ' . count($ungrounded)
                . ' unresolved citation(s) treated as inconclusive (not blocked). '
                . $this->objectiveTag($statedObjective);
            $this->logFailOpen($note);

            // Inconclusive, not refuted: surface what could be confirmed, keep grounded=true.
            return $this->result(true, $resolved, [], $citationCount, $note);
        }

        $grounded = $ungrounded === [];

        if ($grounded) {
            $note = sprintf(
                'grounded: all %d cited symbol(s) resolve. %s',
                $citationCount,
                $this->objectiveTag($statedObjective)
            );
        } else {
            $note = sprintf(
                'UNGROUNDED: %d of %d cited symbol(s) resolve nowhere [%s] — stated objective may be hallucinated. %s',
                count($ungrounded),
                $citationCount,
                implode(', ', $ungrounded),
                $this->objectiveTag($statedObjective)
            );
            $this->logUngrounded($note);
        }

        return $this->result($grounded, $resolved, $ungrounded, $citationCount, $note);
    }

    /**
     * True iff the symbol resolves on ANY oracle (class autoload OR file scan OR code index).
     */
    private function resolves(string $symbol, ?string $repoRoot): bool
    {
        if ($this->resolvesViaAutoload($symbol)) {
            return true;
        }

        if ($repoRoot !== null && $this->resolvesViaFileScan($symbol, $repoRoot)) {
            return true;
        }

        return $this->resolvesViaCodeIndex($symbol);
    }

    /**
     * Oracle 1 — autoload-visible class / interface / trait / enum.
     */
    private function resolvesViaAutoload(string $symbol): bool
    {
        $fqn = ltrim($symbol, '\\');

        return class_exists($fqn)
            || interface_exists($fqn)
            || trait_exists($fqn)
            || enum_exists($fqn);
    }

    /**
     * Oracle 2 — a source file under $repoRoot whose path or basename matches the symbol.
     *
     * Two match modes:
     *   - FQN-shaped citation (has a namespace separator): we derive the expected relative
     *     PSR-4 path ("App\Foo\Bar" → ".../app/Foo/Bar.php" and ".../Foo/Bar.php") and test
     *     those exact paths first — cheap and precise, no walk needed when it hits.
     *   - any citation: a bounded recursive scan looking for a file whose basename equals
     *     "<LastSegment>.php". This catches non-PSR-4 layouts and bare basenames.
     */
    private function resolvesViaFileScan(string $symbol, string $repoRoot): bool
    {
        $lastSegment = $this->lastSegment($symbol);
        if ($lastSegment === '') {
            return false;
        }

        if ($this->resolvesViaExpectedPath($symbol, $repoRoot)) {
            return true;
        }

        $targetBasename = $lastSegment . '.php';

        return $this->scanForBasename($repoRoot, $targetBasename);
    }

    /**
     * Fast path for FQN-shaped citations: probe the conventional PSR-4 locations directly
     * before falling back to a recursive walk.
     */
    private function resolvesViaExpectedPath(string $symbol, string $repoRoot): bool
    {
        $fqn = trim($symbol, '\\');
        if (! str_contains($fqn, '\\')) {
            return false;
        }

        $segments = array_values(array_filter(explode('\\', $fqn), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return false;
        }

        $relative = implode(DIRECTORY_SEPARATOR, $segments) . '.php';
        // Drop a leading top-level namespace ("App\Foo" → "Foo") for app/ PSR-4 roots.
        $withoutTop = count($segments) > 1
            ? implode(DIRECTORY_SEPARATOR, array_slice($segments, 1)) . '.php'
            : null;

        $candidates = [
            $repoRoot . DIRECTORY_SEPARATOR . $relative,
            $repoRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . ($withoutTop ?? $relative),
            $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . ($withoutTop ?? $relative),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bounded recursive walk for a file whose basename equals $targetBasename.
     */
    private function scanForBasename(string $repoRoot, string $targetBasename): bool
    {
        $visited = 0;

        try {
            $directory = new \RecursiveDirectoryIterator(
                $repoRoot,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );

            $filter = new \RecursiveCallbackFilterIterator(
                $directory,
                function (\SplFileInfo $current): bool {
                    if (! $current->isDir()) {
                        return true;
                    }

                    return ! $this->isSkippedDirectory($current->getFilename());
                }
            );

            $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);

            foreach ($iterator as $fileInfo) {
                if (++$visited > self::MAX_FILES_SCANNED) {
                    return false;
                }

                if (! $fileInfo instanceof \SplFileInfo || ! $fileInfo->isFile()) {
                    continue;
                }

                if ($fileInfo->getFilename() === $targetBasename) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Unreadable subtree mid-walk: treat the file oracle as silent, not as a refutation.
            return false;
        }

        return false;
    }

    /**
     * Oracle 3 (best-effort) — a row in the code index whose symbol_name, namespaced FQN,
     * or file basename matches the citation. Any DB/schema unavailability is swallowed: this
     * oracle can only ADD a resolution, never cause a refutation or a crash.
     */
    private function resolvesViaCodeIndex(string $symbol): bool
    {
        $fqn = trim($symbol, '\\');
        $lastSegment = $this->lastSegment($symbol);

        try {
            if (! $this->codeIndexAvailable()) {
                return false;
            }

            $query = DB::table(self::CODE_SYMBOL_TABLE)
                ->where(function ($q) use ($fqn, $lastSegment): void {
                    $q->where('symbol_name', $fqn)
                        ->orWhere('symbol_name', $lastSegment);

                    if (str_contains($fqn, '\\')) {
                        $namespace = $this->namespaceOf($fqn);
                        if ($namespace !== '') {
                            $q->orWhere(function ($inner) use ($namespace, $lastSegment): void {
                                $inner->where('namespace', $namespace)
                                    ->where('symbol_name', $lastSegment);
                            });
                        }
                    }
                });

            return $query->limit(1)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the code-index table is present in the current connection. Wrapped so a
     * missing connection/driver/table never propagates out of the best-effort oracle.
     */
    private function codeIndexAvailable(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable(self::CODE_SYMBOL_TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalize the raw citation array into a clean, de-duplicated list of non-empty strings,
     * preserving first-seen order so the output is deterministic.
     *
     * @param array<int|string,mixed> $citedSymbols
     *
     * @return list<string>
     */
    private function normalizeCitations(array $citedSymbols): array
    {
        $out = [];
        $seen = [];

        foreach ($citedSymbols as $raw) {
            if (! is_string($raw) && ! is_int($raw)) {
                continue;
            }

            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }

            $key = ltrim($value, '\\');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $value;
        }

        return $out;
    }

    private function isSkippedDirectory(string $name): bool
    {
        foreach (self::SKIP_DIRECTORIES as $skip) {
            if ($name === $skip || $name === basename($skip)) {
                return true;
            }
        }

        return false;
    }

    private function lastSegment(string $symbol): string
    {
        $fqn = trim($symbol, '\\');
        if ($fqn === '') {
            return '';
        }

        $parts = explode('\\', $fqn);

        return (string) end($parts);
    }

    private function namespaceOf(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        if ($pos === false) {
            return '';
        }

        return substr($fqn, 0, $pos);
    }

    private function normalizeRepoRoot(string $repoRoot): ?string
    {
        $trimmed = rtrim(trim($repoRoot), DIRECTORY_SEPARATOR);
        if ($trimmed === '') {
            return null;
        }

        $real = realpath($trimmed);

        return $real !== false ? $real : $trimmed;
    }

    private function objectiveTag(string $statedObjective): string
    {
        $clean = trim($statedObjective);
        if ($clean === '') {
            return 'objective="(empty)"';
        }

        if (mb_strlen($clean) > 120) {
            $clean = mb_substr($clean, 0, 117) . '...';
        }

        return 'objective="' . $clean . '"';
    }

    private function shortPath(string $path): string
    {
        $clean = trim($path);

        return $clean === '' ? '(empty)' : $clean;
    }

    /**
     * @param list<string> $resolved
     * @param list<string> $ungrounded
     *
     * @return array{
     *     schema:string,
     *     grounded:bool,
     *     resolved:list<string>,
     *     ungrounded:list<string>,
     *     citation_count:int,
     *     note:string
     * }
     */
    private function result(bool $grounded, array $resolved, array $ungrounded, int $citationCount, string $note): array
    {
        return [
            'schema' => self::SCHEMA,
            'grounded' => $grounded,
            'resolved' => array_values($resolved),
            'ungrounded' => array_values($ungrounded),
            'citation_count' => $citationCount,
            'note' => $note,
        ];
    }

    private function logFailOpen(string $note): void
    {
        try {
            Log::info('[' . self::SCHEMA . '] ' . $note);
        } catch (Throwable) {
            // Logging must never be load-bearing for a fail-open gate.
        }
    }

    private function logUngrounded(string $note): void
    {
        try {
            Log::warning('[' . self::SCHEMA . '] ' . $note);
        } catch (Throwable) {
            // ditto
        }
    }
}
