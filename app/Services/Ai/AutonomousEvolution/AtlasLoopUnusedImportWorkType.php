<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * A SECOND deterministic, PROVIDER-LESS work-type (same shape as the dead-code remover): certify + remove
 * provably-UNUSED `use` imports. Real value (dead imports are maintenance noise + a stale-dependency signal),
 * provider-free, so it MILLS→CERTIFIES with ZERO provider calls — another hermes-free value stream.
 *
 * SOUNDNESS (it mutates source, so it is conservative + FAIL-CLOSED, never risks a live import):
 *   - only SIMPLE imports are considered: a `use X\Y\Z;` (or `... as A;`) that is TYPE_NORMAL and the SOLE
 *     name in its statement; grouped (`use X\{A,B}`), multi-name, and function/const imports are SKIPPED;
 *   - an import is flagged unused ONLY when its short-name/alias appears EXACTLY ONCE in the whole file —
 *     i.e. only in the `use` line itself. Any second whole-word occurrence (code, docblock @param, a string
 *     literal, an attribute) means it is referenced ⇒ never flagged (no false positives by construction);
 *   - the cert re-checks: the proposed source PARSES, is a NET reduction, and every removed name now appears
 *     ZERO times. Any failure ⇒ null (fail-closed). A removal of a truly-unused import cannot break tests,
 *     and the holdout suite (when run in-loop) is the backstop.
 */
final class AtlasLoopUnusedImportWorkType implements AtlasLoopDeterministicWorkType
{
    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * @return array{rel_path:string, original:string, proposed:string, removed:list<string>, certified:true, provider_used:false}|null
     */
    public function produceCertifiedRemoval(string $repoRoot, string $relPath): ?array
    {
        $abs = rtrim($repoRoot, '/').'/'.ltrim($relPath, '/');
        $code = @file_get_contents($abs);
        if ($code === false || trim($code) === '') {
            return null;
        }
        try {
            $stmts = $this->parser->parse($code);
        } catch (Throwable) {
            return null;
        }
        if ($stmts === null) {
            return null;
        }

        // Collect SIMPLE imports: {shortName => useStatementStartLine}. Skip anything ambiguous.
        $candidates = [];
        foreach ($this->finder->findInstanceOf($stmts, Node\Stmt\Use_::class) as $use) {
            if ($use->type !== Node\Stmt\Use_::TYPE_NORMAL || count($use->uses) !== 1) {
                continue; // grouped / multi / function / const => conservative skip
            }
            $u = $use->uses[0];
            $short = $u->alias?->toString() ?? $u->name->getLast();
            if ($short === '' || isset($candidates[$short])) {
                continue; // duplicate short-name across two imports => ambiguous, skip both safely
            }
            $candidates[$short] = $use->getStartLine();
        }
        if ($candidates === []) {
            return null;
        }

        // Flag a name as unused iff it appears EXACTLY ONCE in the whole file (only its own use line).
        $unusedLines = [];
        $removed = [];
        foreach ($candidates as $short => $line) {
            if ($this->wholeWordCount($code, $short) === 1 && $line >= 1) {
                $unusedLines[] = $line;
                $removed[] = $short;
            }
        }
        if ($removed === []) {
            return null;
        }

        $proposed = $this->deleteLines($code, $unusedLines);

        // Cert: parses, net reduction, every removed name now absent.
        if (! $this->parses($proposed) || strlen($proposed) >= strlen($code)) {
            return null;
        }
        foreach ($removed as $short) {
            if ($this->wholeWordCount($proposed, $short) !== 0) {
                return null;
            }
        }

        return [
            'rel_path' => $relPath,
            'original' => $code,
            'proposed' => $proposed,
            'removed' => $removed,
            'certified' => true,
            'provider_used' => false,
        ];
    }

    private function wholeWordCount(string $code, string $name): int
    {
        return (int) preg_match_all('/\b'.preg_quote($name, '/').'\b/', $code);
    }

    /** Delete 1-based line numbers from $code (bottom-up so indices never shift). */
    private function deleteLines(string $code, array $lineNumbers): string
    {
        $lines = explode("\n", $code);
        rsort($lineNumbers);
        foreach ($lineNumbers as $line) {
            $offset = $line - 1;
            if ($offset >= 0 && $offset < count($lines)) {
                array_splice($lines, $offset, 1);
            }
        }

        return implode("\n", $lines);
    }

    private function parses(string $code): bool
    {
        try {
            return $this->parser->parse($code) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
