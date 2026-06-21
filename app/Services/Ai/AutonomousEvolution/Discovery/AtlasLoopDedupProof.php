<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * §5.6 · DEDUP — the PURE count-drop measure behind the frozen judge's dedup proof (Guard 4d).
 *
 * The ungameable anti-no-op veto: a clone-unification is REAL iff the targeted clone body that ≥2 member files
 * shared on BASELINE is shared by <2 of them on CANDIDATE — i.e. the duplication was structurally REMOVED (both
 * bodies replaced by delegation), not laundered (a "dedup" that adds a shared helper while leaving both bodies
 * intact leaves the count at ≥2 ⇒ REJECTED). Source-in / result-out (no git, no FS), so it is fully unit-tested
 * here; the frozen judge supplies the candidate/baseline source via its own git-stash machinery and trusts only
 * this measure — never a provider-claimed number. Behavior-preservation is the judge's separate Guard 3 (the
 * frozen per-member sibling tests); this measures duplication-removed only.
 */
final class AtlasLoopDedupProof
{
    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    private readonly AtlasLoopCloneOrderedNormalizer $normalizer;

    public function __construct(?AtlasLoopCloneOrderedNormalizer $normalizer = null)
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
        $this->normalizer = $normalizer ?? new AtlasLoopCloneOrderedNormalizer;
    }

    /**
     * The order+literal-aware body hash of every method/function with a non-empty body in a source.
     *
     * @return list<string>
     */
    public function bodyHashes(string $source): array
    {
        try {
            $stmts = $this->parser->parse($source);
        } catch (Throwable) {
            return [];
        }
        if ($stmts === null) {
            return [];
        }
        $units = $this->finder->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod || $n instanceof Node\Stmt\Function_,
        );

        $hashes = [];
        foreach ($units as $unit) {
            $body = $unit->stmts ?? null;
            if (! is_array($body) || $body === []) {
                continue; // abstract/interface/empty — no body to clone
            }
            $first = $body[0];
            $last = $body[count($body) - 1];
            $start = $first->getStartFilePos();
            $end = $last->getEndFilePos();
            if (! is_int($start) || ! is_int($end) || $start < 0 || $end < $start) {
                continue;
            }
            $hashes[] = $this->normalizer->hash(substr($source, $start, $end - $start + 1));
        }

        return $hashes;
    }

    /**
     * Determine the targeted clone body-hash (shared by ≥2 member files on baseline) and whether the candidate
     * REMOVED the duplication. NULL when there was no baseline clone to remove (fail-closed — nothing to certify).
     *
     * @param  array<string,string>  $baseline  relPath => baseline source
     * @param  array<string,string>  $candidate  relPath => candidate source
     * @return array{target_hash:string, baseline_count:int, candidate_count:int, removed:bool}|null
     */
    public function evaluate(array $baseline, array $candidate): ?array
    {
        $baseSets = [];
        foreach ($baseline as $rel => $src) {
            $baseSets[$rel] = array_flip($this->bodyHashes($src));
        }
        $candSets = [];
        foreach ($candidate as $rel => $src) {
            $candSets[$rel] = array_flip($this->bodyHashes($src));
        }

        // Frequency of each body-hash across member FILES on baseline (a file counts at most once per hash).
        $freq = [];
        foreach ($baseSets as $set) {
            foreach (array_keys($set) as $h) {
                $freq[$h] = ($freq[$h] ?? 0) + 1;
            }
        }
        $targets = array_keys(array_filter($freq, static fn (int $c): bool => $c >= 2));
        if ($targets === []) {
            return null; // no duplication existed on baseline ⇒ nothing to earn (fail-closed)
        }
        // The most-shared clone (deterministic tie-break by hash) is the target.
        usort($targets, static fn (string $a, string $b): int => ($freq[$b] <=> $freq[$a]) ?: ($a <=> $b));
        $target = $targets[0];

        $baselineCount = $freq[$target];
        $candidateCount = 0;
        foreach ($candSets as $set) {
            if (isset($set[$target])) {
                $candidateCount++;
            }
        }

        return [
            'target_hash' => $target,
            'baseline_count' => $baselineCount,
            'candidate_count' => $candidateCount,
            // REMOVED iff the shared clone went from ≥2 members to <2 (genuine unification, not a no-op).
            'removed' => $candidateCount < $baselineCount && $baselineCount >= 2 && $candidateCount < 2,
        ];
    }
}
