<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * DETERMINISTIC dead-code AUTHOR — the provider-LESS missing half of the dead-code work-type.
 *
 * The {@see AtlasDeadCodeAnalyzer} DETECTS dead `private` members (sound from a single file) but never
 * edits. The orchestrator's existing dead-code lane authors the removal through the PROVIDER (the scenario
 * explorer → hermes), so it hangs exactly like every other work-type. This class closes that gap: given the
 * analyzer's findings, it SURGICALLY deletes each dead member's own line span (its leading docblock/attrs
 * through its closing line) with ZERO provider calls, producing the proposed content the cert
 * (AtlasLoopProposalOutOfProcessVerifier → gate->evaluateDeadCodeRemoval) verifies. That makes the work-type
 * the hermes-FREE path to a live certified delivery.
 *
 * SAFETY (it mutates source, so it is paranoid and FAIL-CLOSED — returns null rather than risk a live edit):
 *   - it removes ONLY what the analyzer proved dead (private, single-file-sound);
 *   - a flagged member sharing a multi-declaration node (`private $a, $b;`) ⇒ FAIL CLOSED (never delete a
 *     live sibling by removing the whole node);
 *   - any member whose AST node it cannot resolve unambiguously (name+start-line) ⇒ FAIL CLOSED;
 *   - the downstream evaluateDeadCodeRemoval cert (revert-recheck + full-suite holdout) is the backstop, so
 *     an imperfect-but-conservative edit simply fails to certify — it can never merge a wrong removal.
 */
final class AtlasLoopDeadCodeRemover
{
    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    private readonly AtlasDeadCodeAnalyzer $analyzer;

    public function __construct(?AtlasDeadCodeAnalyzer $analyzer = null)
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
        $this->analyzer = $analyzer ?? new AtlasDeadCodeAnalyzer;
    }

    /**
     * Produce the cleaned source for $absPath with its analyzer-confirmed dead private members removed.
     * Pass $deadMembers to reuse a prior analysis (the discovery side already has them); null re-analyzes.
     *
     * @param  list<array{kind:string,name:string,line:int,class:string}>|null  $deadMembers
     * @return array{content:string, removed:list<array{kind:string,name:string,line:int}>}|null  null = fail-closed
     */
    public function remove(string $absPath, ?array $deadMembers = null): ?array
    {
        if ($deadMembers === null) {
            $report = $this->analyzer->analyzeFile($absPath);
            if (! ($report['parseable'] ?? false)) {
                return null;
            }
            $deadMembers = $report['dead'] ?? [];
        }
        if ($deadMembers === []) {
            return null; // nothing provably dead — a no-op is not a removal (fail closed)
        }

        $code = @file_get_contents($absPath);
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

        $spans = [];
        $removed = [];
        foreach ($deadMembers as $member) {
            if (! isset($member['kind'], $member['name'], $member['line'])) {
                return null;
            }
            $span = $this->resolveSpan($stmts, (string) $member['kind'], (string) $member['name'], (int) $member['line']);
            if ($span === null) {
                return null; // unresolved / ambiguous / multi-declaration — never guess
            }
            $spans[] = $span;
            $removed[] = ['kind' => (string) $member['kind'], 'name' => (string) $member['name'], 'line' => (int) $member['line']];
        }

        return ['content' => $this->deleteSpans($code, $spans), 'removed' => $removed];
    }

    /**
     * Resolve a dead member to its [startLine, endLine] span (incl. the leading docblock immediately above).
     * Returns null on no/ambiguous match or a multi-declaration node (would delete a live sibling).
     *
     * @param  list<Node\Stmt>  $stmts
     * @return array{0:int,1:int}|null
     */
    private function resolveSpan(array $stmts, string $kind, string $name, int $line): ?array
    {
        $nodeClass = match ($kind) {
            'method' => Node\Stmt\ClassMethod::class,
            'property' => Node\Stmt\Property::class,
            'const' => Node\Stmt\ClassConst::class,
            default => null,
        };
        if ($nodeClass === null) {
            return null;
        }

        $match = null;
        foreach ($this->finder->findInstanceOf($stmts, $nodeClass) as $node) {
            if ($node->getStartLine() !== $line || ! $this->declaresExactlyName($node, $name)) {
                continue;
            }
            if ($match !== null) {
                return null; // two nodes at the same start line + name — ambiguous, fail closed
            }
            $match = $node;
        }
        if ($match === null) {
            return null;
        }

        $start = $match->getStartLine();
        $end = $match->getEndLine();
        if ($start < 1 || $end < $start) {
            return null;
        }
        // Extend upward over an attached docblock (PHP-Parser already counts attribute groups in getStartLine).
        $comments = $match->getComments();
        if ($comments !== []) {
            $cStart = $comments[0]->getStartLine();
            if ($cStart >= 1 && $cStart < $start) {
                $start = $cStart;
            }
        }

        return [$start, $end];
    }

    /**
     * True iff the node declares EXACTLY the one named member. A multi-declaration node
     * (`private $a, $b;` / `private const A=1, B=2;`) returns false ⇒ the caller fails closed.
     */
    private function declaresExactlyName(Node $node, string $name): bool
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            return $node->name->toString() === $name;
        }
        if ($node instanceof Node\Stmt\Property) {
            return count($node->props) === 1 && $node->props[0]->name->toString() === $name;
        }
        if ($node instanceof Node\Stmt\ClassConst) {
            return count($node->consts) === 1 && $node->consts[0]->name->toString() === $name;
        }

        return false;
    }

    /**
     * Delete the 1-based [start,end] line spans from $code (bottom-up so earlier indices never shift),
     * collapsing a double blank line left behind. Spans are sibling members ⇒ non-overlapping.
     *
     * @param  list<array{0:int,1:int}>  $spans
     */
    private function deleteSpans(string $code, array $spans): string
    {
        $lines = explode("\n", $code);
        usort($spans, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($spans as [$start, $end]) {
            $offset = $start - 1;
            $length = $end - $start + 1;
            if ($offset < 0 || $offset >= count($lines)) {
                continue;
            }
            array_splice($lines, $offset, $length);
            if ($offset > 0 && $offset < count($lines)
                && trim($lines[$offset - 1]) === '' && trim($lines[$offset]) === '') {
                array_splice($lines, $offset, 1);
            }
        }

        return implode("\n", $lines);
    }
}
