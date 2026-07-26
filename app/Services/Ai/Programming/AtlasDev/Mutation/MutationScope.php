<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * The deduplicated scope for a scoped E3 infection run.
 *
 * The scope captures EXACTLY the patch's touched test files plus their
 * covered source files (VAL-E3-001), and NOTHING ELSE:
 *   - {@see $testFiles}:   touched files in the tests/ tree (PHPUnit will be
 *                           scoped to these via --test-framework-options).
 *   - {@see $sourceFiles}: the covered SOURCE files infection will MUTATE,
 *                           scoped via --filter. This is the deduped union of
 *                           touched source files plus the source files
 *                           convention-derived from touched test files
 *                           (e.g. tests/Unit/CalculatorTest.php covers
 *                           app/Calculator.php), so a test file touched
 *                           without its source in the patch still has the
 *                           unit-under-test in the mutation scope
 *                           (VAL-E3-012: multi-file union).
 *
 * Cardinality of (testFiles + sourceFiles) is FAR below the ~3592-file suite:
 * the adapter NEVER runs mutation/coverage across the full tree
 * (VAL-E3-001, mission boundary: infection must be scoped to touched files
 * only — never the full ~3592-file suite).
 *
 * {@see isEmpty()} is the canonical no-op guard (VAL-E3-008): when no test
 * files are touched, the scope is empty and the adapter is a no-op (skipped
 * with reason, never a false fail).
 */
final class MutationScope
{
    /**
     * @param  list<string>  $testFiles  touched PHPUnit test files (tests/...).
     * @param  list<string>  $sourceFiles  covered source files infection will mutate (app/...).
     */
    public function __construct(
        public readonly array $testFiles,
        public readonly array $sourceFiles,
    ) {}

    /**
     * A scope is empty iff it carries no test files. Without test files
     * there is nothing to mutation-test (the patch touched only non-test
     * source, docs, or nothing at all). VAL-E3-008: an empty scope => the
     * adapter is a no-op (skipped with reason, never a false fail).
     *
     * Note: source files alone do not make the scope non-empty. Even if the
     * patch touched source, without a touched test file there is no coverage
     * to mutation-test against (and we do not invent one out of thin air).
     */
    public function isEmpty(): bool
    {
        return $this->testFiles === [];
    }

    /**
     * The SINGLE pcov.directory to instrument for coverage.
     *
     * pcov.directory is a SINGLE-VALUED ini directive: emitting multiple
     * `-d pcov.directory=` entries makes only the LAST entry effective, so a
     * scope spanning >1 source directory would silently drop coverage for
     * every directory except the last (m3-e3 scrutiny Defect 1, BLOCKING).
     *
     * The fix returns the LOWEST COMMON ANCESTOR directory of every scoped
     * source file — the narrowest single directory that still contains ALL
     * of them, so pcov instruments the WHOLE scope in one directive. By
     * construction all scoped source lives under app/, so the LCA always
     * resolves to an app/ subtree (never the bare repo root, which would
     * instrument the full ~3592-file tree — VAL-E3-001 / VAL-E3-011).
     *
     * The MUTATED set is still narrowed to the exact touched source files
     * via infection's --filter / source.directories, so widening the pcov
     * instrumentation to the LCA does NOT widen what infection mutates; it
     * only ensures every touched source dir is actually instrumented for
     * coverage collection.
     *
     * @return list<string> exactly one entry: the LCA directory. Empty only
     *                      when the scope has no source files at all.
     */
    public function pcovDirectories(): array
    {
        $dirs = [];
        foreach ($this->sourceFiles as $source) {
            $dir = dirname($source);
            if ($dir === '' || $dir === '.') {
                continue;
            }
            $dirs[] = $dir;
        }

        return self::lowestCommonAncestor($dirs);
    }

    /**
     * Compute the single lowest common ancestor directory of a list of
     * directories.
     *
     * The LCA is the longest shared path-prefix (segment-aligned, never a
     * partial segment) of every input directory. When one input is itself a
     * prefix of another, the prefix is the LCA (it already contains both).
     * When the inputs share no common prefix, the LCA is the empty string
     * (and the caller treats that as "no pcov.directory" — never the repo
     * root, which would instrument the full tree).
     *
     * The result is a SINGLE directory: emitting one pcov.directory equal to
     * the LCA guarantees pcov instruments every scoped source dir, not just
     * the last one emitted on the command line (m3-e3 scrutiny Defect 1).
     *
     * @param  list<string>  $dirs
     * @return list<string> either [] (no dirs / no shared prefix) or [$lca].
     */
    private static function lowestCommonAncestor(array $dirs): array
    {
        if ($dirs === []) {
            return [];
        }
        $dirs = array_values(array_unique($dirs));
        if (count($dirs) === 1) {
            return [$dirs[0]];
        }

        // Segment-align the prefix walk: split every directory into path
        // segments and walk forward while ALL inputs share the same segment.
        $segmentLists = [];
        foreach ($dirs as $dir) {
            $segmentLists[] = explode('/', $dir);
        }
        $first = $segmentLists[0];
        $shared = [];
        foreach ($first as $index => $segment) {
            foreach ($segmentLists as $segments) {
                if (! isset($segments[$index]) || $segments[$index] !== $segment) {
                    // Stop at the first segment that diverges: the shared
                    // prefix up to (but not including) this segment is the
                    // LCA.
                    return $shared === [] ? [] : [implode('/', $shared)];
                }
            }
            $shared[] = $segment;
        }

        // Every segment of the first directory matched in all the others, so
        // the first directory is itself the LCA (it is a prefix of all the
        // others). This happens when one scoped dir is an ancestor of the
        // rest (e.g. app/Services/Foo and app/Services/Foo/Deep => LCA is
        // app/Services/Foo). $shared is guaranteed non-empty here (the loop
        // above only returns early on divergence; reaching this point means
        // every segment of $first matched, and $first has at least one
        // segment), so no empty-guard is needed.
        return [implode('/', $shared)];
    }
}
